<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M16;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M10\DecisionLedger;

/**
 * M16 — the governed release-output spine.
 *
 * Ported from `packages/allfather-control-substrate/src/release-output/`
 * @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d.
 *
 * The dependency ordering is fixed and is the one the reconciliation in upstream PR #356 repaired:
 *
 *     source-use profile  ->  ReleaseArtifact  ->  projection  ->  visibility activation
 *
 * **Every audience beyond `TENANT_INTERNAL` is designed-inert** (V1-GAP-093), and that is enforced
 * in STORAGE, not by this code:
 *   - `output_visibility_profiles` has `CHECK (audience_class IN ('OPERATOR_ONLY','TENANT_INTERNAL')
 *     OR activation_permitted = 0)`, so a permitting row for a wider audience is impossible to
 *     write;
 *   - `release_artifacts` has `CHECK (status <> 'RELEASED_CUSTOMER_VISIBLE')`, so an artifact can
 *     never reach a customer-visible state.
 *
 * This projection creates no customer-visible, export, API or channel surface, and could not
 * without a schema change.
 */
final class ReleaseOutput
{
    /**
     * The CLOSED audience vocabulary — seven members, and the seventh is not like the others.
     *
     * `BLOCKED_QUARANTINED` is a terminal-deny PSEUDO-AUDIENCE: a rendering state for content that
     * has been withheld, never a place content is sent. It belongs to the vocabulary because a
     * caller can name it and the answer has to be a stated refusal; it is deliberately absent from
     * the LADDER below, from every storage CHECK, and from the seeded profiles, so there is no row
     * anywhere that could make it a projection target.
     *
     * Upstream draws this line with a union type and a function that throws on the pseudo-audience.
     * PHP has neither, so the same line is drawn by the two constants plus `audienceOrderIndex`.
     *
     * @var list<string>
     */
    public const AUDIENCE_CLASS = [
        'OPERATOR_ONLY', 'TENANT_INTERNAL', 'CUSTOMER_VISIBLE',
        'EXPORTABLE', 'API_VISIBLE', 'CHANNEL_EMAIL_SMS_CRM',
        'BLOCKED_QUARANTINED',
    ];

    /**
     * The audience LADDER, inner to wider — the real audiences, ordered.
     *
     * The monotonicity law is stated over this list: content(wider) is a subset of
     * content(narrower). A pseudo-audience has no position on it, because "narrower than a refusal"
     * is not a question with an answer.
     *
     * @var list<string>
     */
    public const AUDIENCE_ORDER = [
        'OPERATOR_ONLY', 'TENANT_INTERNAL', 'CUSTOMER_VISIBLE',
        'EXPORTABLE', 'API_VISIBLE', 'CHANNEL_EMAIL_SMS_CRM',
    ];

    /**
     * Members of the vocabulary that are rendering states rather than destinations.
     *
     * Kept as a list rather than a single value so a second one — if the vocabulary ever gains
     * another terminal-deny state — is added HERE and refused everywhere, instead of being missed
     * at one of the call sites that special-cases the name.
     *
     * @var list<string>
     */
    public const PSEUDO_AUDIENCES = ['BLOCKED_QUARANTINED'];

    /** The only audiences this projection may ever ACTIVATE. The rest are designed-inert. */
    public const ACTIVATABLE_AUDIENCES = ['OPERATOR_ONLY', 'TENANT_INTERNAL'];

    /**
     * An audience's position on the ladder, inner to wider.
     *
     * THROWS on a pseudo-audience rather than returning a sentinel, and that is the point: a
     * caller asking where BLOCKED_QUARANTINED sits has already made a category error, and a -1 or
     * a null would let that error travel — most likely as "not found, therefore widest" or "not
     * found, therefore narrowest", both of which are answers to a question nobody should be asking.
     */
    public static function audienceOrderIndex(string $audienceClass): int
    {
        $index = array_search($audienceClass, self::AUDIENCE_ORDER, true);
        if ($index === false) {
            throw new \ValueError(sprintf(
                '%s is not a projection target: %s',
                $audienceClass,
                in_array($audienceClass, self::PSEUDO_AUDIENCES, true)
                    ? 'it is a refusal-rendering pseudo-audience'
                    : 'it is not an audience class at all',
            ));
        }

        return (int) $index;
    }

    public const ARTIFACT_KIND = [
        'COMPANY_SIGNAL_BRIEFING', 'BATTLECARD_BRIEFING',
        'ENTITY_TWIN_SNAPSHOT_VIEW', 'ENTITY_RELATIONSHIP_SNAPSHOT_VIEW',
    ];

    public const ARTIFACT_STATUS = [
        'DRAFT', 'READY_FOR_RELEASE', 'RELEASED_INTERNAL', 'RELEASED_CUSTOMER_VISIBLE',
        'HELD_SOURCE_USE', 'HELD_FRESHNESS', 'HELD_CONFLICT', 'SUPERSEDED', 'ROLLED_BACK', 'QUARANTINED',
    ];

    public const CLAIM_CEILING = [
        'INTERNAL_DRAFT', 'INTERNAL_REVIEWED', 'INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS',
        'SUPERSEDED', 'RETRACTED',
    ];

    /** The licence ceiling a contributing source imposes on an artifact's audience. */
    public const LICENSE_CEILING = [
        'INTERNAL_ONLY', 'DERIVED_OUTPUT_ALLOWED', 'CUSTOMER_VISIBLE_ALLOWED', 'PROHIBITED',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly DecisionLedger $ledger,
    ) {
    }

    // ---------------------------------------------------------------- 1. source-use profile

    /**
     * Compile the source-use profile for a release: what every contributing source permits.
     *
     * This runs FIRST because it can HOLD the artifact. A source whose card forbids derived output,
     * whose review expired, or whose licence is PROHIBITED does not merely annotate the artifact —
     * it stops it, and the hold reason names which source and why.
     *
     * @param list<string> $sourceRefIds
     */
    public function compileSourceUseProfile(
        string $tenantId,
        string $workspaceId,
        array $sourceRefIds,
    ): Outcome {
        if ($sourceRefIds === []) {
            // An artifact with no contributing source has no provenance to state, and an
            // unprovenanced release is exactly what this spine exists to prevent.
            return Outcome::refuse('SOURCE_USE_NO_CONTRIBUTING_SOURCES', [], 'PROVENANCE');
        }

        $contributions = [];
        $holdReasons = [];
        $ceiling = 'CUSTOMER_VISIBLE_ALLOWED';
        // The strictest raw values across contributors. Each is a CEILING or a FLOOR, so the
        // strictest contributor governs the whole artifact — one internal-only source makes the
        // artifact internal-only however permissive its siblings are.
        $licenseCeiling = 'OPEN_CC0';
        $sensitivityCeiling = 'PUBLIC_OR_NON_SENSITIVE';
        $retentionFloor = 'short';
        $ownershipMix = null;
        $now = $this->clock->now();

        foreach ($sourceRefIds as $sourceRefId) {
            $card = $this->db->one(
                'SELECT slc.source_license_card_id, slc.tenant_id, slc.license_class, slc.review_status,
                        slc.review_expires_at, slc.customer_output_allowed, slc.derived_summary_allowed,
                        slc.allowed_use_class, slc.pii_risk_class, slc.retention_class,
                        sr.status AS source_status
                 FROM source_license_cards slc
                 JOIN source_references sr ON sr.source_ref_id = slc.source_ref_id
                 WHERE slc.source_ref_id = ? AND slc.tenant_id = ?',
                [$sourceRefId, $tenantId]
            );
            if ($card === null) {
                $holdReasons[] = 'SOURCE_CARD_MISSING';
                $contributions[] = ['source_ref_id' => $sourceRefId, 'state' => 'MISSING'];
                $ceiling = 'PROHIBITED';
                $licenseCeiling = 'PROHIBITED';
                continue;
            }

            $cardState = 'APPROVED';
            if ((string) $card['review_status'] !== 'approved') {
                $holdReasons[] = 'SOURCE_CARD_NOT_APPROVED';
                $cardState = 'NOT_APPROVED';
            } elseif ($card['review_expires_at'] !== null && (string) $card['review_expires_at'] < $now) {
                // A review window that lapsed without anyone re-classifying the source is as stale
                // as one explicitly marked expired.
                $holdReasons[] = 'SOURCE_CARD_REVIEW_EXPIRED';
                $cardState = 'EXPIRED';
            } elseif ((string) $card['source_status'] !== 'ACTIVE_FOR_CAPTURE') {
                $holdReasons[] = 'SOURCE_NOT_ACTIVE_FOR_CAPTURE';
                $cardState = 'SOURCE_WITHDRAWN';
            }

            $licenseClass = (string) $card['license_class'];
            $contributionCeiling = self::ceilingForLicense(
                $licenseClass,
                (int) $card['customer_output_allowed'] === 1,
                (int) $card['derived_summary_allowed'] === 1,
            );
            if ($contributionCeiling === 'PROHIBITED') {
                $holdReasons[] = 'SOURCE_LICENSE_PROHIBITS_USE';
            }
            // The STRICTEST contributing ceiling governs. One internal-only source makes the whole
            // artifact internal-only, however permissive its siblings are.
            $ceiling = self::strictestCeiling($ceiling, $contributionCeiling);
            $licenseCeiling = self::strictestLicenseClass($licenseCeiling, $licenseClass);
            $sensitivityCeiling = self::strictestSensitivity($sensitivityCeiling, (string) ($card['pii_risk_class'] ?? 'none'));
            $retentionFloor = self::strictestRetention($retentionFloor, (string) ($card['retention_class'] ?? 'short'));
            $ownershipMix = self::mergeOwnership($ownershipMix, (string) $card['allowed_use_class']);

            $contributions[] = [
                'source_ref_id' => $sourceRefId,
                'source_license_card_id' => (string) $card['source_license_card_id'],
                'license_class' => $licenseClass,
                'card_state' => $cardState,
                'contribution_ceiling' => $contributionCeiling,
            ];
        }

        $holdReasons = array_values(array_unique($holdReasons));
        $profileId = Ids::new();

        // The derived hard ceiling, folded exactly as the contribution-driven compile folds it —
        // one rule, one place, so the two paths cannot answer the same question differently.
        //
        // A ceiling is a statement about what the SOURCES permit. It is not a deployment
        // authority: nothing above TENANT_INTERNAL is activatable here whatever the ceiling says,
        // because ACTIVATABLE_AUDIENCES pins that separately. An earlier revision folded the two
        // together and capped the recorded ceiling at TENANT_INTERNAL, which silently rewrote the
        // source-use answer to match the activation policy — a licence that permits customer
        // output was recorded as one that does not.
        $audienceCeiling = self::foldAudienceCeiling(
            $holdReasons === [] ? 'COMPILED_ALLOW' : 'COMPILED_HELD',
            $sensitivityCeiling,
            $licenseCeiling,
            $ceiling === 'CUSTOMER_VISIBLE_ALLOWED',
        );

        $profileDigest = Digest::canonicalDigest([
            'tenantId' => $tenantId,
            'workspaceId' => $workspaceId,
            'contributions' => $contributions,
            'licenseCeiling' => $licenseCeiling,
            'audienceCeiling' => $audienceCeiling,
            'holdReasons' => $holdReasons,
        ]);

        $this->db->insert('release_source_use_profiles', [
            'release_source_use_profile_id' => $profileId,
            'schema_version' => '1',
            'tenant_id' => $tenantId,
            // COMPILED_HELD is a real compiled answer, not a failure to compile: the profile
            // records WHY the release is held, which a missing profile could not.
            'status' => $holdReasons === [] ? 'COMPILED_ALLOW' : 'COMPILED_HELD',
            'hold_reasons' => json_encode($holdReasons, JSON_THROW_ON_ERROR),
            'profile_digest' => $profileDigest,
            'compiler_version' => '1',
            'customer_output_allowed' => $ceiling === 'CUSTOMER_VISIBLE_ALLOWED' ? 1 : 0,
            'license_ceiling' => $licenseCeiling,
            'sensitivity_ceiling' => $sensitivityCeiling,
            'retention_floor' => $retentionFloor,
            'audience_ceiling' => $audienceCeiling,
            'data_ownership_mix' => $ownershipMix,
            'profile_json' => json_encode([
                'contributions' => $contributions,
                'effective_license_ceiling' => $ceiling,
            ], JSON_THROW_ON_ERROR),
            'compiled_at' => $now,
        ]);

        return Outcome::ok([
            'release_source_use_profile_id' => $profileId,
            'profile_digest' => $profileDigest,
            'effective_license_ceiling' => $ceiling,
            'license_ceiling' => $licenseCeiling,
            'audience_ceiling' => $audienceCeiling,
            'hold_reasons' => $holdReasons,
            'contributions' => $contributions,
            'status' => $holdReasons === [] ? 'COMPILED_ALLOW' : 'COMPILED_HELD',
        ]);
    }

    /**
     * The canonical hold-reason order. The digest is order-sensitive, so this is the declaration
     * order of the upstream union rather than whatever order the checks happen to fire in.
     *
     * @var list<string>
     */
    public const SOURCE_USE_HOLD_REASONS = [
        'SOURCE_LICENSE_PROHIBITED',
        'SOURCE_CARD_NOT_APPROVED',
        'SOURCE_CARD_REVIEW_EXPIRED',
        'SOURCE_CARD_MISSING_FOR_EXTERNAL_SOURCE',
        'EVIDENCE_QUARANTINED_OR_REVOKED',
        'SENSITIVITY_PROHIBITED',
        'RETENTION_PROHIBITED',
        'UNKNOWN_LICENSE_VALUE',
    ];

    /** Least- to most-permissive. The fold takes the MINIMUM — the strictest contributor governs. */
    private const LICENSE_LATTICE = [
        'PROHIBITED', 'LICENSED_INTERNAL_ONLY', 'LICENSED_NO_CUSTOMER_OUTPUT', 'PUBLIC_WEB_RESTRICTED',
        'LICENSED_DERIVED_OUTPUT_ALLOWED', 'PUBLIC_WEB_ALLOWED', 'OPEN_CC0',
    ];

    /** Least- to most-sensitive. The fold takes the MAXIMUM. */
    private const SENSITIVITY_ORDER = [
        'PUBLIC_OR_NON_SENSITIVE', 'INTERNAL_DEMO', 'RESTRICTED_REVIEW', 'PROHIBITED_IN_V1',
    ];

    /** Least- to most-restrictive retention. The fold takes the MAXIMUM. */
    private const RETENTION_STRICTNESS = ['standard', 'short', 'minimal', 'prohibited'];

    /**
     * Compile a source-use profile from EXPLICIT contributions rather than from card lookups.
     *
     * The card-driven {@see compileSourceUseProfile} answers "what do these registered sources
     * permit". This one answers the question P02 actually has: a twin's state rests on a MIX of
     * bases — the tenant's own material, prior released snapshots, and external references that
     * carry no governed card at all — and only the caller knows which is which.
     *
     * THE FIRST-PARTY RULE IS THE WHOLE POINT. A contribution with no card reference AND
     * `TENANT_OWNED` ownership is the tenant's own material: it folds at the top of the lattice and
     * never drags the artifact down. Every other cardless contribution HOLDS the profile with
     * `SOURCE_CARD_MISSING_FOR_EXTERNAL_SOURCE`, because an external claim with no licence card is
     * exactly the thing nobody has cleared for release.
     *
     * A HOLD IS A COMPILED ANSWER, NOT A FAILURE. The profile row is written either way and records
     * WHY, which a missing profile could not. The artifact that follows is HELD rather than absent.
     *
     * @param list<array<string, mixed>> $contributions
     */
    public function compileSourceUseProfileFromContributions(
        string $tenantId,
        string $workspaceId,
        array $contributions,
    ): Outcome {
        if ($contributions === []) {
            return Outcome::refuse('SOURCE_USE_NO_CONTRIBUTING_SOURCES', [], 'PROVENANCE');
        }

        $holds = [];
        $now = $this->clock->now();

        foreach ($contributions as $c) {
            $license = (string) ($c['license_ceiling'] ?? 'PROHIBITED');
            if (!in_array($license, self::LICENSE_LATTICE, true)) {
                // Vocabulary drift in storage fails CLOSED. A licence value nobody recognizes is
                // not treated as permissive by default.
                $holds[] = 'UNKNOWN_LICENSE_VALUE';
            } elseif ($license === 'PROHIBITED') {
                $holds[] = 'SOURCE_LICENSE_PROHIBITED';
            }

            $firstParty = ($c['source_license_card_id'] ?? null) === null
                && (string) ($c['data_ownership'] ?? 'PUBLIC') === 'TENANT_OWNED';
            if (!$firstParty) {
                $cardState = $c['card_state'] ?? null;
                if ($cardState === null) {
                    $holds[] = 'SOURCE_CARD_MISSING_FOR_EXTERNAL_SOURCE';
                } else {
                    if (($cardState['approved'] ?? false) !== true) {
                        $holds[] = 'SOURCE_CARD_NOT_APPROVED';
                    }
                    $expires = $cardState['review_expires_at'] ?? null;
                    if ($expires !== null && $now > (string) $expires) {
                        $holds[] = 'SOURCE_CARD_REVIEW_EXPIRED';
                    }
                }
            }

            if (($c['evidence_usable'] ?? true) === false) {
                $holds[] = 'EVIDENCE_QUARANTINED_OR_REVOKED';
            }
            if ((string) ($c['sensitivity_class'] ?? 'PUBLIC_OR_NON_SENSITIVE') === 'PROHIBITED_IN_V1') {
                $holds[] = 'SENSITIVITY_PROHIBITED';
            }
            if ((string) ($c['retention_class'] ?? 'standard') === 'prohibited') {
                $holds[] = 'RETENTION_PROHIBITED';
            }
        }

        $holdReasons = array_values(array_filter(
            self::SOURCE_USE_HOLD_REASONS,
            static fn (string $r): bool => in_array($r, $holds, true),
        ));

        $customerOutputAllowed = true;
        $licenseIndex = count(self::LICENSE_LATTICE) - 1;
        $sensitivityIndex = 0;
        $retentionIndex = 0;
        $ownerships = [];
        foreach ($contributions as $c) {
            $customerOutputAllowed = $customerOutputAllowed && (($c['customer_output_allowed'] ?? false) === true);
            $li = array_search((string) ($c['license_ceiling'] ?? 'PROHIBITED'), self::LICENSE_LATTICE, true);
            $licenseIndex = min($licenseIndex, $li === false ? 0 : (int) $li);
            $si = array_search((string) ($c['sensitivity_class'] ?? 'PUBLIC_OR_NON_SENSITIVE'), self::SENSITIVITY_ORDER, true);
            // An unknown sensitivity reads as RESTRICTED_REVIEW, not as public.
            $sensitivityIndex = max($sensitivityIndex, $si === false ? 2 : (int) $si);
            $ri = array_search((string) ($c['retention_class'] ?? 'standard'), self::RETENTION_STRICTNESS, true);
            $retentionIndex = max($retentionIndex, $ri === false ? count(self::RETENTION_STRICTNESS) - 1 : (int) $ri);
            $ownerships[] = (string) ($c['data_ownership'] ?? 'PUBLIC');
        }

        $licenseCeiling = self::LICENSE_LATTICE[$licenseIndex];
        $sensitivityCeiling = self::SENSITIVITY_ORDER[$sensitivityIndex];
        $retentionFloor = self::RETENTION_STRICTNESS[$retentionIndex];
        $distinctOwnership = array_values(array_unique($ownerships));
        $ownershipMix = count($distinctOwnership) === 1 && $distinctOwnership[0] !== 'MIXED'
            ? $distinctOwnership[0]
            : 'MIXED';

        $status = $holdReasons === [] ? 'COMPILED_ALLOW' : 'COMPILED_HELD';

        // The derived hard ceiling. A HELD compile renders the OPERATOR hold-view only;
        // RESTRICTED_REVIEW sensitivity caps at OPERATOR_ONLY; an internal-only licence or a failed
        // customer-output AND-fold caps at TENANT_INTERNAL. The activation pins keep everything
        // above TENANT_INTERNAL inert regardless — this answers "what may the SOURCES show", never
        // "what is activated".
        $audienceCeiling = self::foldAudienceCeiling(
            $status,
            $sensitivityCeiling,
            $licenseCeiling,
            $customerOutputAllowed,
        );

        $profileId = Ids::new();
        // The digest deliberately EXCLUDES the evaluation clock: the clock reaches the profile only
        // through derived semantics (the expiry hold), never as an input of its own.
        $profileDigest = Digest::canonicalDigest([
            'tenantId' => $tenantId,
            'workspaceId' => $workspaceId,
            'status' => $status,
            'holdReasons' => $holdReasons,
            'customerOutputAllowed' => $customerOutputAllowed,
            'licenseCeiling' => $licenseCeiling,
            'sensitivityCeiling' => $sensitivityCeiling,
            'retentionFloor' => $retentionFloor,
            'audienceCeiling' => $audienceCeiling,
            'dataOwnershipMix' => $ownershipMix,
            'perSourceContributions' => $contributions,
        ]);

        // A PROFILE IS ITS DIGEST. Identical contributions compile to an identical profile, and
        // storage says so with a unique (tenant, digest) index — so a recompile CONVERGES on the
        // existing row rather than inserting a duplicate. This is what makes a replayed composition
        // replayable: the compile runs before the assembly's idempotency probe can be reached.
        $existing = $this->db->one(
            'SELECT release_source_use_profile_id FROM release_source_use_profiles
             WHERE tenant_id = ? AND profile_digest = ?',
            [$tenantId, $profileDigest]
        );
        if ($existing !== null) {
            return Outcome::ok([
                'release_source_use_profile_id' => (string) $existing['release_source_use_profile_id'],
                'profile_digest' => $profileDigest,
                'license_ceiling' => $licenseCeiling,
                'sensitivity_ceiling' => $sensitivityCeiling,
                'retention_floor' => $retentionFloor,
                'audience_ceiling' => $audienceCeiling,
                'data_ownership_mix' => $ownershipMix,
                'hold_reasons' => $holdReasons,
                'status' => $status,
                'converged' => true,
            ]);
        }

        $this->db->insert('release_source_use_profiles', [
            'release_source_use_profile_id' => $profileId,
            'schema_version' => '1',
            'tenant_id' => $tenantId,
            'status' => $status,
            'hold_reasons' => json_encode($holdReasons, JSON_THROW_ON_ERROR),
            'profile_digest' => $profileDigest,
            'compiler_version' => '1',
            'customer_output_allowed' => $customerOutputAllowed ? 1 : 0,
            'license_ceiling' => $licenseCeiling,
            'sensitivity_ceiling' => $sensitivityCeiling,
            'retention_floor' => $retentionFloor,
            'audience_ceiling' => $audienceCeiling,
            'data_ownership_mix' => $ownershipMix,
            'profile_json' => json_encode([
                'contributions' => $contributions,
                'effective_license_ceiling' => $customerOutputAllowed && $holdReasons === []
                    ? 'CUSTOMER_VISIBLE_ALLOWED'
                    : 'INTERNAL_ONLY',
            ], JSON_THROW_ON_ERROR),
            'compiled_at' => $now,
        ]);

        return Outcome::ok([
            'release_source_use_profile_id' => $profileId,
            'profile_digest' => $profileDigest,
            'license_ceiling' => $licenseCeiling,
            'sensitivity_ceiling' => $sensitivityCeiling,
            'retention_floor' => $retentionFloor,
            'audience_ceiling' => $audienceCeiling,
            'data_ownership_mix' => $ownershipMix,
            'hold_reasons' => $holdReasons,
            'status' => $status,
        ]);
    }

    // ---------------------------------------------------------------- 2. the frozen artifact

    /**
     * Assemble a frozen release artifact.
     *
     * The order of checks matters and is preserved:
     *  1. exactly ONE consumer reference, paired to the artifact kind;
     *  2. the M10 binding — an `INTERNAL_INFORMATIONAL_RELEASE` record in this exact scope. **No
     *     record, no artifact, ever**;
     *  3. the idempotency probe runs BEFORE any consumer read, so a retry REPLAYS rather than
     *     re-validating state the first pass already moved;
     *  4. the source-use hold — a profile with hold reasons produces a HELD artifact, not a
     *     releasable one.
     *
     * @param array<string, mixed> $input
     */
    public function assembleArtifact(array $input): Outcome
    {
        $artifactKind = (string) $input['artifact_kind'];
        $refs = [
            'released_briefing_id' => $input['released_briefing_id'] ?? null,
            'released_entity_twin_snapshot_id' => $input['released_entity_twin_snapshot_id'] ?? null,
            'released_relationship_snapshot_id' => $input['released_relationship_snapshot_id'] ?? null,
        ];
        $present = array_filter($refs, static fn ($v): bool => $v !== null);
        if (count($present) !== 1) {
            return Outcome::refuse('CONSUMER_REFERENCE_INVALID', [
                'reference_count' => count($present),
            ], 'PROVENANCE');
        }

        // The M10 binding. An artifact without a release record behind it would be a published
        // claim nobody decided to release.
        $record = $this->db->one(
            "SELECT dr.decision_record_id, dr.tenant_id, dr.workspace_id, dr.proposal_id,
                    (SELECT av.arbiter_verdict_id FROM arbiter_verdicts av
                      WHERE av.proposal_id = dr.proposal_id
                      ORDER BY av.issued_at DESC, av.arbiter_verdict_id DESC LIMIT 1)
                      AS arbiter_verdict_id
             FROM decision_records dr
             WHERE dr.decision_record_id = ? AND dr.record_type = 'INTERNAL_INFORMATIONAL_RELEASE'",
            [$input['decision_record_id']]
        );
        if ($record === null || $record['proposal_id'] === null) {
            return Outcome::refuse('DECISION_RECORD_NOT_FOUND_OR_WRONG_TYPE', [], 'PROVENANCE');
        }
        if ((string) $record['tenant_id'] !== (string) $input['tenant_id']
            || (string) $record['workspace_id'] !== (string) $input['workspace_id']) {
            return Outcome::refuse('SCOPE_MISMATCH', [], 'SCOPE');
        }

        // Idempotency BEFORE consumer reads: a retry must replay, never re-validate.
        $prior = $this->db->one(
            'SELECT release_artifact_id FROM release_artifacts WHERE decision_record_id = ? AND artifact_kind = ?',
            [$input['decision_record_id'], $artifactKind]
        );
        if ($prior !== null) {
            return Outcome::ok([
                'outcome' => 'IDEMPOTENT_REPLAY',
                'release_artifact_id' => (string) $prior['release_artifact_id'],
            ]);
        }

        $profile = $this->db->one(
            'SELECT release_source_use_profile_id, profile_digest, hold_reasons, audience_ceiling, status
             FROM release_source_use_profiles WHERE release_source_use_profile_id = ?',
            [$input['source_use_profile_id']]
        );
        if ($profile === null) {
            return Outcome::refuse('SOURCE_USE_PROFILE_NOT_FOUND', [], 'PROVENANCE');
        }
        $holdReasons = json_decode((string) $profile['hold_reasons'], true, 512, JSON_THROW_ON_ERROR);

        // A source-use hold produces a HELD artifact rather than a releasable one. The artifact is
        // still WRITTEN, so the hold is auditable — a refusal that left no record would make the
        // reason for non-release invisible.
        $status = $holdReasons === [] ? 'READY_FOR_RELEASE' : 'HELD_SOURCE_USE';

        $contentElements = $input['content_elements'] ?? [];
        $uncertainty = (string) ($input['uncertainty_summary_safe'] ?? '');
        if (trim($uncertainty) === '') {
            // CHECK-pinned non-empty upstream. An artifact that states no uncertainty is claiming
            // certainty it has not established.
            return Outcome::refuse('ARTIFACT_UNCERTAINTY_SUMMARY_REQUIRED', [], 'VALIDATION');
        }

        $artifactId = Ids::new();
        $now = $this->clock->now();
        $artifactDigest = Digest::canonicalDigest([
            'artifactKind' => $artifactKind,
            'decisionRecordId' => (string) $input['decision_record_id'],
            'proposalId' => (string) $record['proposal_id'],
            'sourceUseProfileDigest' => (string) $profile['profile_digest'],
            'contentElements' => $contentElements,
            'uncertaintySummarySafe' => $uncertainty,
            'claimCeiling' => (string) ($input['claim_ceiling'] ?? 'INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS'),
        ]);

        return $this->db->transaction(function (Database $tx) use (
            $artifactId, $artifactKind, $refs, $input, $record, $profile, $contentElements,
            $uncertainty, $status, $artifactDigest, $now
        ): Outcome {
            $tx->insert('release_artifacts', [
                'release_artifact_id' => $artifactId,
                'schema_version' => '1',
                'tenant_id' => (string) $input['tenant_id'],
                'workspace_id' => (string) $input['workspace_id'],
                'artifact_kind' => $artifactKind,
                'released_briefing_id' => $refs['released_briefing_id'],
                'released_entity_twin_snapshot_id' => $refs['released_entity_twin_snapshot_id'],
                'released_relationship_snapshot_id' => $refs['released_relationship_snapshot_id'],
                'proposal_id' => (string) $record['proposal_id'],
                // Derived from the bound proposal when the caller does not name it. The verdict is
                // a FACT ABOUT THE RELEASE, not a caller's choice — deriving it means an artifact
                // can never cite a verdict other than the one its decision record rests on.
                'arbiter_verdict_id' => (string) ($input['arbiter_verdict_id'] ?? $record['arbiter_verdict_id']),
                'decision_record_id' => (string) $input['decision_record_id'],
                'source_use_profile_id' => (string) $profile['release_source_use_profile_id'],
                'source_use_profile_digest' => (string) $profile['profile_digest'],
                'content_elements_json' => json_encode($contentElements, JSON_THROW_ON_ERROR),
                'uncertainty_summary_safe' => $uncertainty,
                'freshness_observed_at' => (string) ($input['freshness_observed_at'] ?? $now),
                'claim_ceiling' => (string) ($input['claim_ceiling'] ?? 'INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS'),
                'artifact_digest' => $artifactDigest,
                'assembler_version' => '1',
                'assembled_at' => $now,
                'status' => $status,
                'internal_scope_lens' => $input['internal_scope_lens'] ?? null,
            ]);

            return Outcome::ok([
                'outcome' => 'ASSEMBLED',
                'release_artifact_id' => $artifactId,
                'artifact_digest' => $artifactDigest,
                'status' => $status,
                'hold_reasons' => json_decode((string) $profile['hold_reasons'], true, 512, JSON_THROW_ON_ERROR),
            ]);
        });
    }

    /** Move a READY_FOR_RELEASE artifact to RELEASED_INTERNAL. */
    public function releaseInternal(string $artifactId): Outcome
    {
        $artifact = $this->db->one(
            'SELECT status FROM release_artifacts WHERE release_artifact_id = ?',
            [$artifactId]
        );
        if ($artifact === null) {
            return Outcome::refuse('ARTIFACT_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $artifact['status'] !== 'READY_FOR_RELEASE') {
            return Outcome::refuse('ARTIFACT_NOT_IN_RELEASABLE_STATE', [
                'status' => (string) $artifact['status'],
            ], 'POLICY');
        }

        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use ($artifactId, $now): Outcome {
            $tx->run(
                'UPDATE release_artifacts SET status = ? WHERE release_artifact_id = ? AND status = ?',
                ['RELEASED_INTERNAL', $artifactId, 'READY_FOR_RELEASE']
            );
            return Outcome::ok(['release_artifact_id' => $artifactId, 'status' => 'RELEASED_INTERNAL']);
        });
    }

    // ---------------------------------------------------------------- 3. projection

    /**
     * Project a frozen artifact for an audience.
     *
     * A projection for an audience the source-use ceiling does not permit is RECORDED AS REFUSED
     * rather than silently skipped: a refusal that leaves no row makes "we never tried" and "we
     * tried and it was denied" indistinguishable.
     */
    public function projectForAudience(string $artifactId, string $audienceClass): Outcome
    {
        // THE VOCABULARY IS CHECKED FIRST, and the two failures are named separately. Without this
        // both reached the storage CHECK as a driver error — and if that CHECK were ever widened,
        // BLOCKED_QUARANTINED would instead be recorded as a projection REFUSED for being above
        // the source-use ceiling, which is a true-sounding reason for the wrong thing: the
        // pseudo-audience is not too wide, it is not an audience.
        if (in_array($audienceClass, self::PSEUDO_AUDIENCES, true)) {
            return Outcome::refuse('AUDIENCE_NOT_A_PROJECTION_TARGET', [
                'audience_class' => $audienceClass,
            ], 'POLICY');
        }
        if (!in_array($audienceClass, self::AUDIENCE_ORDER, true)) {
            return Outcome::refuse('AUDIENCE_CLASS_UNKNOWN', [
                'audience_class' => $audienceClass,
            ], 'POLICY');
        }

        $artifact = $this->db->one(
            'SELECT ra.tenant_id, ra.workspace_id, ra.status, ra.content_elements_json,
                    ra.uncertainty_summary_safe, ra.claim_ceiling, p.audience_ceiling, p.customer_output_allowed
             FROM release_artifacts ra
             JOIN release_source_use_profiles p
               ON p.release_source_use_profile_id = ra.source_use_profile_id
             WHERE ra.release_artifact_id = ?',
            [$artifactId]
        );
        if ($artifact === null) {
            return Outcome::refuse('ARTIFACT_NOT_FOUND', [], 'PROVENANCE');
        }

        $existing = $this->db->one(
            'SELECT customer_output_projection_id, status FROM customer_output_projections
             WHERE release_artifact_id = ? AND audience_class = ?',
            [$artifactId, $audienceClass]
        );
        if ($existing !== null) {
            return Outcome::ok([
                'outcome' => 'IDEMPOTENT_REPLAY',
                'customer_output_projection_id' => (string) $existing['customer_output_projection_id'],
                'status' => (string) $existing['status'],
            ]);
        }

        // Two independent audience gates, and BOTH are recorded when both apply — a projection
        // refused for two reasons that reported only one would understate why it was refused.
        //
        // The AND-fold gate is not implied by the ceiling gate even though the fold consults the
        // same flag: the ceiling is computed once at compile time from the contributions, while
        // this reads the flag the profile actually recorded. If they ever disagree, the projection
        // must refuse on the recorded flag rather than trust the derived one.
        $refusals = [];
        if (
            self::audienceOrderIndex($audienceClass) >= self::audienceOrderIndex('CUSTOMER_VISIBLE')
            && (int) $artifact['customer_output_allowed'] !== 1
        ) {
            $refusals[] = 'CUSTOMER_OUTPUT_NOT_ALLOWED_BY_SOURCE_USE';
        }
        if (!self::audiencePermittedByCeiling($audienceClass, (string) $artifact['audience_ceiling'])) {
            $refusals[] = 'AUDIENCE_ABOVE_PROFILE_CEILING';
        }
        $permitted = $refusals === [];

        $elements = json_decode((string) $artifact['content_elements_json'], true, 512, JSON_THROW_ON_ERROR);
        $containsClaim = false;
        foreach ($elements as $element) {
            if (($element['elementClass'] ?? null) === 'CLAIM') {
                $containsClaim = true;
                break;
            }
        }

        $projectionId = Ids::new();
        $this->db->insert('customer_output_projections', [
            'customer_output_projection_id' => $projectionId,
            'schema_version' => '1',
            'tenant_id' => (string) $artifact['tenant_id'],
            'workspace_id' => (string) $artifact['workspace_id'],
            'release_artifact_id' => $artifactId,
            'audience_class' => $audienceClass,
            // A REFUSED projection carries NO content. Storing the elements on a refused row and
            // relying on the status to hide them would put the content one careless query away.
            'content_elements_safe_json' => json_encode($permitted ? $elements : [], JSON_THROW_ON_ERROR),
            'redaction_decisions_json' => json_encode([], JSON_THROW_ON_ERROR),
            'contains_claim' => $containsClaim ? 1 : 0,
            'caveat_blocks_present' => 1,
            'redaction_engine_version' => '1',
            'projection_digest' => Digest::canonicalDigest([
                'releaseArtifactId' => $artifactId,
                'audienceClass' => $audienceClass,
                'elements' => $permitted ? $elements : [],
            ]),
            'status' => $permitted ? 'PROJECTED' : 'REFUSED',
            'refusal_reason_codes' => json_encode($refusals, JSON_THROW_ON_ERROR),
            'projected_at' => $this->clock->now(),
        ]);

        return Outcome::ok([
            'customer_output_projection_id' => $projectionId,
            'status' => $permitted ? 'PROJECTED' : 'REFUSED',
            'audience_class' => $audienceClass,
            'refusal_reason_codes' => $refusals,
        ]);
    }

    // ---------------------------------------------------------------- 4. visibility activation

    /**
     * Seed the visibility profiles for a tenant.
     *
     * Only `OPERATOR_ONLY` and `TENANT_INTERNAL` are seeded as permitted. The wider audiences are
     * seeded EXPLICITLY with `activation_permitted = 0` rather than omitted, so their inertness is
     * a recorded fact an auditor can read — and the storage CHECK makes any other value impossible
     * to write anyway.
     */
    public function seedVisibilityProfiles(
        string $tenantId,
        string $requiredPermissionKey = 'briefing.read',
        ?array $artifactKinds = null,
        ?string $internalScopeLens = null,
    ): Outcome {
        $kinds = $artifactKinds ?? ['COMPANY_SIGNAL_BRIEFING', 'BATTLECARD_BRIEFING'];
        foreach ($kinds as $kind) {
            if (!in_array($kind, self::ARTIFACT_KIND, true)) {
                return Outcome::refuse('ARTIFACT_KIND_UNKNOWN', ['artifact_kind' => $kind], 'POLICY');
            }
        }

        // Which (audience, family) rules this registration is asking for. OPERATOR_ONLY never
        // carries a lens — the schema CHECK pins it, because a need-to-know lens is a
        // TENANT_INTERNAL construct and an operator hold-view is not scoped by role.
        $requested = [];
        foreach (self::ACTIVATABLE_AUDIENCES as $audience) {
            foreach ($kinds as $kind) {
                $requested[] = [
                    'audience' => $audience,
                    'artifact_kind' => $kind,
                    'lens' => $audience === 'TENANT_INTERNAL' ? $internalScopeLens : null,
                ];
            }
        }

        // PREFLIGHT BEFORE ANY WRITE. A conflict discovered on the LAST requested family would
        // otherwise leave the audience row and the earlier families' rules standing — a silently
        // widened authority surface created by a command that reported doing nothing. The preflight
        // is load-bearing precisely because this also runs INSIDE a caller's transaction, where it
        // cannot roll anything back on its own.
        foreach ($requested as $rule) {
            $standing = $this->db->one(
                "SELECT required_permission_key, internal_scope_lens FROM output_access_rules
                 WHERE tenant_id = ? AND audience_class = ? AND artifact_kind = ? AND status = 'ACTIVE'",
                [$tenantId, $rule['audience'], $rule['artifact_kind']]
            );
            if ($standing === null) {
                continue;
            }
            $standingLens = $standing['internal_scope_lens'] === null
                ? null
                : (string) $standing['internal_scope_lens'];
            if ((string) $standing['required_permission_key'] !== $requiredPermissionKey
                || $standingLens !== $rule['lens']
            ) {
                return Outcome::refuse('ACCESS_RULE_CONFLICT', [
                    'artifact_kind' => $rule['artifact_kind'],
                    'audience_class' => $rule['audience'],
                ], 'POLICY');
            }
        }

        $now = $this->clock->now();
        $seeded = 0;
        $rulesSeeded = 0;

        // The AUDIENCE row is shared across families and is created once per tenant. It carries the
        // activation permission, which is a property of the audience; WHICH PERMISSION READS WHICH
        // FAMILY lives in the access rules below. Conflating the two is how `briefing.read` would
        // come to govern a twin snapshot merely because P01 registered first.
        foreach (self::AUDIENCE_ORDER as $audience) {
            $existing = $this->db->one(
                "SELECT output_visibility_profile_id FROM output_visibility_profiles
                 WHERE tenant_id = ? AND audience_class = ? AND status = 'ACTIVE'",
                [$tenantId, $audience]
            );
            if ($existing !== null) {
                continue;
            }
            $this->db->insert('output_visibility_profiles', [
                'output_visibility_profile_id' => Ids::new(),
                'schema_version' => '1',
                'tenant_id' => $tenantId,
                'audience_class' => $audience,
                'profile_version' => 1,
                'activation_permitted' => in_array($audience, self::ACTIVATABLE_AUDIENCES, true) ? 1 : 0,
                'required_permission_key' => $requiredPermissionKey,
                'internal_scope_lens' => null,
                'status' => 'ACTIVE',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $seeded++;
        }

        foreach ($requested as $rule) {
            $existing = $this->db->one(
                "SELECT output_access_rule_id FROM output_access_rules
                 WHERE tenant_id = ? AND audience_class = ? AND artifact_kind = ? AND status = 'ACTIVE'",
                [$tenantId, $rule['audience'], $rule['artifact_kind']]
            );
            if ($existing !== null) {
                // The preflight already proved it agrees. Re-registration converges.
                continue;
            }
            $this->db->insert('output_access_rules', [
                'output_access_rule_id' => Ids::new(),
                'schema_version' => '1',
                'tenant_id' => $tenantId,
                'audience_class' => $rule['audience'],
                'artifact_kind' => $rule['artifact_kind'],
                'rule_version' => '1',
                'required_permission_key' => $requiredPermissionKey,
                'internal_scope_lens' => $rule['lens'],
                'status' => 'ACTIVE',
                'supersedes_output_access_rule_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $rulesSeeded++;
        }

        return Outcome::ok([
            'tenant_id' => $tenantId,
            'profiles_seeded' => $seeded,
            'access_rules_seeded' => $rulesSeeded,
            'required_permission_key' => $requiredPermissionKey,
            'artifact_kinds' => $kinds,
        ]);
    }

    /**
     * Which permission reads this artifact family, for this audience.
     *
     * Default-deny on absence: a family nobody registered an access rule for is readable by nobody,
     * rather than falling back to whatever the audience row happens to say. The audience row's
     * permission answers "may this audience be activated at all"; it was never meant to answer
     * "who may read a twin snapshot", and letting it do so is how one product family's registration
     * silently grants access to another's output.
     */
    public function requiredPermissionFor(string $tenantId, string $audienceClass, string $artifactKind): ?string
    {
        $rule = $this->db->one(
            "SELECT required_permission_key FROM output_access_rules
             WHERE tenant_id = ? AND audience_class = ? AND artifact_kind = ? AND status = 'ACTIVE'",
            [$tenantId, $audienceClass, $artifactKind]
        );

        return $rule === null ? null : (string) $rule['required_permission_key'];
    }

    /**
     * Activate internal visibility for a projection.
     *
     * A missing OR foreign artifact answers IDENTICALLY, so this is not a cross-tenant existence
     * oracle. The authority row's absence is default-deny, and the schema CHECK makes a permitting
     * row for any wider audience structurally impossible.
     */
    public function activateInternalVisibility(
        string $artifactId,
        string $tenantId,
        string $workspaceId,
        string $audienceClass = 'TENANT_INTERNAL',
    ): Outcome {
        $artifact = $this->db->one(
            'SELECT tenant_id, workspace_id, status, decision_record_id FROM release_artifacts WHERE release_artifact_id = ?',
            [$artifactId]
        );
        if ($artifact === null
            || (string) $artifact['tenant_id'] !== $tenantId
            || (string) $artifact['workspace_id'] !== $workspaceId) {
            return Outcome::refuse('SCOPE_MISMATCH', [], 'SCOPE');
        }
        if (!in_array((string) $artifact['status'], ['READY_FOR_RELEASE', 'RELEASED_INTERNAL'], true)) {
            return Outcome::refuse('ARTIFACT_NOT_IN_RELEASABLE_STATE', [
                'status' => (string) $artifact['status'],
            ], 'POLICY');
        }

        $projection = $this->db->one(
            'SELECT customer_output_projection_id, status FROM customer_output_projections
             WHERE release_artifact_id = ? AND audience_class = ?',
            [$artifactId, $audienceClass]
        );
        if ($projection === null || (string) $projection['status'] !== 'PROJECTED') {
            return Outcome::refuse('PROJECTION_REFUSED_OR_MISSING', [], 'POLICY');
        }

        $profile = $this->db->one(
            "SELECT activation_permitted FROM output_visibility_profiles
             WHERE tenant_id = ? AND audience_class = ? AND status = 'ACTIVE'",
            [$tenantId, $audienceClass]
        );
        if ($profile === null || (int) $profile['activation_permitted'] !== 1) {
            // Default-deny on absence, and the CHECK guarantees a wider audience can never have a
            // permitting row to find.
            return Outcome::refuse('VISIBILITY_ACTIVATION_NOT_PERMITTED', [
                'audience_class' => $audienceClass,
            ], 'POLICY');
        }

        $projectionId = (string) $projection['customer_output_projection_id'];
        $sequence = 1 + (int) $this->db->scalar(
            'SELECT COALESCE(MAX(event_sequence), 0) FROM release_visibility_activations
             WHERE customer_output_projection_id = ?',
            [$projectionId]
        );

        $eventId = Ids::new();
        $this->db->insert('release_visibility_activations', [
            'release_visibility_activation_id' => $eventId,
            'schema_version' => '1',
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'customer_output_projection_id' => $projectionId,
            'release_artifact_id' => $artifactId,
            'audience_class' => $audienceClass,
            'event_kind' => 'ACTIVATED',
            'authority_decision_record_id' => (string) $artifact['decision_record_id'],
            // The reason vocabulary is closed. An internal activation IS the internal scope of
            // the release verdict — it is not a separate decision, and naming it as one would
            // suggest an authority that does not exist.
            'reason_code' => 'INTERNAL_SCOPE_OF_RELEASE_VERDICT',
            'event_sequence' => $sequence,
            'occurred_at' => $this->clock->now(),
        ]);

        return Outcome::ok([
            'release_visibility_activation_id' => $eventId,
            'customer_output_projection_id' => $projectionId,
            'event_sequence' => $sequence,
            'event_kind' => 'ACTIVATED',
        ]);
    }

    /**
     * Deactivate visibility — the rollback lever.
     *
     * `$reasonCode` is drawn from the closed vocabulary: ROLLBACK, SUPERSESSION, QUARANTINE, or
     * HOLD_DISCOVERED_POST_RELEASE. Withdrawal always has one of those four causes, and inventing a
     * fifth would let the reason for a withdrawal escape the audit vocabulary.
     *
     * Appends a DEACTIVATED event rather than deleting the ACTIVATED one, so the history shows
     * that the content WAS visible and then was withdrawn. The read model consults only the LATEST
     * event, so appending is enough to hide it.
     */
    public function deactivateVisibility(string $projectionId, string $reasonCode = 'ROLLBACK'): Outcome
    {
        $projection = $this->db->one(
            'SELECT customer_output_projection_id, tenant_id, workspace_id, release_artifact_id,
                    audience_class
             FROM customer_output_projections WHERE customer_output_projection_id = ?',
            [$projectionId]
        );
        if ($projection === null) {
            return Outcome::refuse('PROJECTION_REFUSED_OR_MISSING', [], 'POLICY');
        }

        // Withdrawing something that is not currently visible would write a false record: the
        // history would show a withdrawal that withdrew nothing, and an auditor counting
        // DEACTIVATED events would over-count the times content was actually pulled.
        if (self::latestEventKind($this->db, $projectionId) !== 'ACTIVATED') {
            return Outcome::refuse('VISIBILITY_NOT_CURRENTLY_ACTIVE', [
                'customer_output_projection_id' => $projectionId,
            ], 'POLICY');
        }

        $event = $this->appendVisibilityEvent($this->db, $projection, 'DEACTIVATED', $reasonCode);

        return Outcome::ok($event);
    }

    /**
     * The kind of the LATEST visibility event for a projection, or null when it has none.
     *
     * The read model consults only this, so it is also what decides whether a withdrawal has
     * anything to withdraw.
     */
    private static function latestEventKind(Database $db, string $projectionId): ?string
    {
        $kind = $db->scalar(
            'SELECT event_kind FROM release_visibility_activations
             WHERE customer_output_projection_id = ?
             ORDER BY event_sequence DESC LIMIT 1',
            [$projectionId]
        );

        return $kind === null ? null : (string) $kind;
    }

    /**
     * Append one visibility event, allocating the next sequence number.
     *
     * @param array<string, mixed> $projection tenant_id, workspace_id, release_artifact_id,
     *                                         audience_class, customer_output_projection_id
     * @return array{release_visibility_activation_id: string, customer_output_projection_id: string, event_sequence: int, event_kind: string}
     */
    private function appendVisibilityEvent(
        Database $db,
        array $projection,
        string $eventKind,
        string $reasonCode,
    ): array {
        $projectionId = (string) $projection['customer_output_projection_id'];
        $sequence = 1 + (int) $db->scalar(
            'SELECT COALESCE(MAX(event_sequence), 0) FROM release_visibility_activations
             WHERE customer_output_projection_id = ?',
            [$projectionId]
        );

        $eventId = Ids::new();
        $db->insert('release_visibility_activations', [
            'release_visibility_activation_id' => $eventId,
            'schema_version' => '1',
            'tenant_id' => (string) $projection['tenant_id'],
            'workspace_id' => (string) $projection['workspace_id'],
            'customer_output_projection_id' => $projectionId,
            'release_artifact_id' => (string) $projection['release_artifact_id'],
            'audience_class' => (string) $projection['audience_class'],
            'event_kind' => $eventKind,
            'authority_decision_record_id' => (string) $db->scalar(
                'SELECT decision_record_id FROM release_artifacts WHERE release_artifact_id = ?',
                [$projection['release_artifact_id']]
            ),
            'reason_code' => $reasonCode,
            'event_sequence' => $sequence,
            'occurred_at' => $this->clock->now(),
        ]);

        return [
            'release_visibility_activation_id' => $eventId,
            'customer_output_projection_id' => $projectionId,
            'event_sequence' => $sequence,
            'event_kind' => $eventKind,
        ];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Append a lineage event.
     *
     * `release_lineage_events.kind` admits only SUPERSESSION, ROLLBACK and QUARANTINE — it records
     * what happened TO an already-released artifact, not the ordinary assembly path. Assembly and
     * internal release leave their trace on the artifact's own status and on the M10 record behind
     * it, so this is called only for the three lifecycle events it is for.
     */
    private function appendLineage(
        Database $db,
        string $artifactId,
        string $tenantId,
        string $workspaceId,
        string $kind,
        string $decisionCorrectionRecordId,
        ?string $successorArtifactId,
        string $reasonSummary,
        string $authoritySourceModuleId,
        string $at,
    ): void {
        $db->insert('release_lineage_events', [
            'release_lineage_event_id' => Ids::new(),
            'schema_version' => '1',
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'release_artifact_id' => $artifactId,
            'kind' => $kind,
            'successor_release_artifact_id' => $successorArtifactId,
            'decision_correction_record_id' => $decisionCorrectionRecordId,
            // The authority vocabulary admits M08, M11 and M02 — the planes that can WITHDRAW a
            // release. M10 records the correction but does not itself decide it; a rollback is
            // authorized by an audit finding (M11), a policy decision (M08), or an access change
            // (M02), and the module recorded here is the one that actually decided.
            'authority_source_module_id' => $authoritySourceModuleId,
            'authority_source_object_id' => $decisionCorrectionRecordId,
            'safe_reason_summary' => $reasonSummary,
            'occurred_at' => $at,
        ]);
    }

    /**
     * Roll an artifact back — the M16 half of the stand-down.
     *
     * The artifact's status moves to ROLLED_BACK, every currently-visible projection of it is
     * DEACTIVATED, and a lineage event records the authority for all of it. The row is not
     * deleted and its digest still verifies: the record of what WAS released is what makes the
     * rollback provable.
     *
     * The deactivation is not decoration. The read model decides visibility from the LATEST
     * visibility event, so a rollback that moved only the artifact status would leave every
     * projection's latest event saying ACTIVATED — the content would remain visible, and the
     * lineage would record a withdrawal that withdrew nothing. Standing the artifact down and
     * withdrawing its projections are one atomic act, in one transaction.
     */
    public function rollbackArtifact(
        string $artifactId,
        string $decisionCorrectionRecordId,
        string $reasonSummary,
        string $authoritySourceModuleId = 'M11',
    ): Outcome {
        $artifact = $this->db->one(
            'SELECT tenant_id, workspace_id, status FROM release_artifacts WHERE release_artifact_id = ?',
            [$artifactId]
        );
        if ($artifact === null) {
            return Outcome::refuse('ARTIFACT_NOT_FOUND', [], 'PROVENANCE');
        }

        // An already-rolled-back artifact REPLAYS. Appending a second ROLLBACK lineage event under
        // a different authority would make the record say two separate stand-downs happened.
        if ((string) $artifact['status'] === 'ROLLED_BACK') {
            return Outcome::ok([
                'release_artifact_id' => $artifactId,
                'status' => 'ROLLED_BACK',
                'outcome' => 'REPLAYED',
                'deactivated_projection_ids' => [],
            ]);
        }

        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use (
            $artifactId, $artifact, $decisionCorrectionRecordId, $reasonSummary,
            $authoritySourceModuleId, $now
        ): Outcome {
            $tx->run(
                'UPDATE release_artifacts SET status = ? WHERE release_artifact_id = ?',
                ['ROLLED_BACK', $artifactId]
            );

            $deactivated = [];
            $projections = $tx->all(
                'SELECT customer_output_projection_id, tenant_id, workspace_id, release_artifact_id,
                        audience_class
                 FROM customer_output_projections WHERE release_artifact_id = ?',
                [$artifactId]
            );
            foreach ($projections as $projection) {
                $projectionId = (string) $projection['customer_output_projection_id'];
                if (self::latestEventKind($tx, $projectionId) !== 'ACTIVATED') {
                    continue;
                }
                $this->appendVisibilityEvent($tx, $projection, 'DEACTIVATED', 'ROLLBACK');
                $deactivated[] = $projectionId;
            }

            $this->appendLineage(
                $tx, $artifactId, (string) $artifact['tenant_id'], (string) $artifact['workspace_id'],
                'ROLLBACK', $decisionCorrectionRecordId, null, $reasonSummary,
                $authoritySourceModuleId, $now
            );

            return Outcome::ok([
                'release_artifact_id' => $artifactId,
                'status' => 'ROLLED_BACK',
                'outcome' => 'ROLLED_BACK',
                'deactivated_projection_ids' => $deactivated,
            ]);
        });
    }

    /** The audience ceiling a licence class implies. */
    private static function ceilingForLicense(string $licenseClass, bool $customerOutputAllowed, bool $derivedAllowed): string
    {
        if ($licenseClass === 'PROHIBITED') {
            return 'PROHIBITED';
        }
        if (in_array($licenseClass, ['LICENSED_INTERNAL_ONLY', 'LICENSED_NO_CUSTOMER_OUTPUT'], true)) {
            return 'INTERNAL_ONLY';
        }
        if (!$customerOutputAllowed) {
            return $derivedAllowed ? 'DERIVED_OUTPUT_ALLOWED' : 'INTERNAL_ONLY';
        }

        return 'CUSTOMER_VISIBLE_ALLOWED';
    }

    /** The strictest raw licence class governs. */
    private static function strictestLicenseClass(string $a, string $b): string
    {
        $order = [
            'PROHIBITED' => 0, 'LICENSED_INTERNAL_ONLY' => 1, 'LICENSED_NO_CUSTOMER_OUTPUT' => 2,
            'PUBLIC_WEB_RESTRICTED' => 3, 'LICENSED_DERIVED_OUTPUT_ALLOWED' => 4,
            'PUBLIC_WEB_ALLOWED' => 5, 'OPEN_CC0' => 6,
        ];

        return ($order[$a] ?? 0) <= ($order[$b] ?? 0) ? $a : $b;
    }

    /** The HIGHEST sensitivity governs: one restricted source makes the artifact restricted. */
    private static function strictestSensitivity(string $a, string $b): string
    {
        $map = ['none' => 'PUBLIC_OR_NON_SENSITIVE', 'low' => 'INTERNAL_DEMO',
                'medium' => 'RESTRICTED_REVIEW', 'high' => 'RESTRICTED_REVIEW'];
        $b = $map[$b] ?? $b;
        $order = ['PUBLIC_OR_NON_SENSITIVE' => 0, 'INTERNAL_DEMO' => 1, 'RESTRICTED_REVIEW' => 2, 'PROHIBITED_IN_V1' => 3];

        return ($order[$a] ?? 0) >= ($order[$b] ?? 0) ? $a : $b;
    }

    /** The SHORTEST retention governs: the artifact may be kept only as long as its strictest source allows. */
    private static function strictestRetention(string $a, string $b): string
    {
        $order = ['prohibited' => 0, 'audit_minimized' => 1, 'short' => 2, 'standard' => 3];

        return ($order[$a] ?? 2) <= ($order[$b] ?? 2) ? $a : $b;
    }

    /** Mixing tenant-owned and public material makes the artifact MIXED. */
    private static function mergeOwnership(?string $current, string $allowedUseClass): string
    {
        $incoming = $allowedUseClass === 'customer_owned_tenant_scoped' ? 'TENANT_OWNED' : 'PUBLIC';
        if ($current === null) {
            return $incoming;
        }

        return $current === $incoming ? $current : 'MIXED';
    }

    /** The strictest of two ceilings governs. */
    private static function strictestCeiling(string $a, string $b): string
    {
        $order = ['PROHIBITED' => 0, 'INTERNAL_ONLY' => 1, 'DERIVED_OUTPUT_ALLOWED' => 2, 'CUSTOMER_VISIBLE_ALLOWED' => 3];

        return ($order[$a] ?? 0) <= ($order[$b] ?? 0) ? $a : $b;
    }

    /**
     * The derived hard audience ceiling.
     *
     * The single canonical rule, used by BOTH compile paths:
     *
     *  - a HELD compile, or RESTRICTED_REVIEW sensitivity, renders the operator hold-view only;
     *  - an internal-only licence, a no-customer-output licence, or a failed customer-output
     *    AND-fold caps at TENANT_INTERNAL;
     *  - otherwise the sources permit the full ladder.
     *
     * The third branch is the one that is easy to get wrong. It records what the SOURCES allow,
     * which is not what this deployment activates: `ACTIVATABLE_AUDIENCES` holds activation at
     * OPERATOR_ONLY and TENANT_INTERNAL regardless. Capping the ceiling here to match the
     * activation policy would be a different claim — that the licence itself forbids customer
     * output — and it would be false.
     */
    private static function foldAudienceCeiling(
        string $status,
        string $sensitivityCeiling,
        string $licenseCeiling,
        bool $customerOutputAllowed,
    ): string {
        if ($status === 'COMPILED_HELD' || $sensitivityCeiling === 'RESTRICTED_REVIEW') {
            return 'OPERATOR_ONLY';
        }
        if (
            in_array($licenseCeiling, ['LICENSED_INTERNAL_ONLY', 'LICENSED_NO_CUSTOMER_OUTPUT'], true)
            || !$customerOutputAllowed
        ) {
            return 'TENANT_INTERNAL';
        }

        return self::AUDIENCE_ORDER[count(self::AUDIENCE_ORDER) - 1];
    }

    /**
     * Whether the profile's audience ceiling permits projecting content for an audience.
     *
     * Both arguments are AUDIENCE CLASSES, compared by position on the one ladder. An earlier
     * revision compared the audience ceiling against the LICENCE ceiling vocabulary
     * (`PROHIBITED` / `CUSTOMER_VISIBLE_ALLOWED`), which never matched: it refused every wider
     * audience for the right reason by accident, but it also returned true for any internal
     * audience regardless of the ceiling, so an `OPERATOR_ONLY` ceiling did not actually bind a
     * `TENANT_INTERNAL` projection.
     *
     * A later revision replaced that with an explicit two-entry allow-list, which was total only
     * because the fold was capped at TENANT_INTERNAL. With the fold restored to canonical the
     * ceiling can name the full ladder, and an allow-list would have refused every projection
     * under it. The comparison is now the canonical one — index against index — which is total
     * over the whole vocabulary by construction.
     *
     * `audienceOrderIndex` throws on a pseudo-audience, and that is deliberate: a ceiling of
     * `BLOCKED_QUARANTINED` is a category error, and defaulting it to "narrowest" or "widest"
     * would let that error travel.
     */
    private static function audiencePermittedByCeiling(string $audienceClass, string $ceiling): bool
    {
        return self::audienceOrderIndex($audienceClass) <= self::audienceOrderIndex($ceiling);
    }

}
