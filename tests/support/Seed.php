<?php

declare(strict_types=1);

namespace Allfather\Loopia\Tests;

use Allfather\Loopia\Auth\PasswordPolicy;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P01\Battlecards;
use Allfather\Loopia\Product\P01\Release as P01Release;
use Allfather\Loopia\Substrate\M01\AdapterConformance;
use Allfather\Loopia\Substrate\M03\Eligibility;
use Allfather\Loopia\Substrate\M03\Registry;
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
use Allfather\Loopia\Substrate\M05\Readiness as ReadinessEvaluator;
use Allfather\Loopia\Substrate\M06\Registry as ToolRegistry;
use Allfather\Loopia\Substrate\M07\Council;
use Allfather\Loopia\Substrate\M08\Arbiter;
use Allfather\Loopia\Substrate\M08\PolicySets;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M16\ReleaseOutput;

/**
 * Minimal authority-chain seeding for tests.
 *
 * Deliberately NOT a fixture factory that hides governance: every seed here creates only rows a
 * real operator act would create, so a test cannot accidentally prove behavior that depends on a
 * state the product could never reach.
 */
final class Seed
{
    /** @return array{tenant_id: string, workspace_id: string, operator_id: string} */
    public static function tenantWorkspace(Database $db, Clock $clock, string $key = 'acme'): array
    {
        $now = $clock->now();
        $tenantId = Ids::new();
        $workspaceId = Ids::new();
        $operatorId = Ids::new();

        $db->insert('tenants', [
            'tenant_id' => $tenantId,
            'tenant_key' => $key . '-' . substr($tenantId, 0, 8),
            'schema_version' => '1',
            'display_name' => ucfirst($key),
            'tenant_type' => 'TEST',
            'status' => 'ACTIVE',
            'created_at' => $now,
        ]);

        $db->insert('operators', [
            'operator_id' => $operatorId,
            'tenant_id' => $tenantId,
            'schema_version' => '1',
            'display_name' => 'Operator ' . $key,
            'status' => 'ACTIVE',
            'created_at' => $now,
        ]);

        $db->insert('workspaces', [
            'workspace_id' => $workspaceId,
            'tenant_id' => $tenantId,
            'workspace_key' => 'ws-' . substr($workspaceId, 0, 8),
            'schema_version' => '1',
            'display_name' => 'Workspace',
            'workspace_type' => 'TEST',
            'environment_class' => 'TEST',
            'external_side_effects_allowed' => 0,
            'active_v1_capability_ceiling' => 'RELEASE_INTELLIGENCE',
            'status' => 'ACTIVE',
            'created_at' => $now,
        ]);

        // Output visibility profiles are part of TENANT SETUP, provisioned in production by
        // P01\Registration. They are seeded here for the same reason: the release path READS them
        // and must never provision its own — a release that created the row permitting it would be
        // checking its own work. Seeding them at tenant creation keeps that separation intact in
        // every test, rather than leaving each one to remember.
        (new ReleaseOutput($db, $clock, new DecisionLedger($db, $clock)))->seedVisibilityProfiles($tenantId);

        return ['tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'operator_id' => $operatorId];
    }

    /** An additional operator inside an existing tenant. */
    public static function operator(Database $db, Clock $clock, string $tenantId, string $displayName): string
    {
        $operatorId = Ids::new();
        $db->insert('operators', [
            'operator_id' => $operatorId,
            'tenant_id' => $tenantId,
            'schema_version' => '1',
            'display_name' => $displayName,
            'status' => 'ACTIVE',
            'created_at' => $clock->now(),
        ]);

        return $operatorId;
    }

    /** @return array{role_id: string} */
    public static function role(Database $db, Clock $clock, string $roleKey, array $permissions = []): array
    {
        $now = $clock->now();
        $roleId = Ids::new();
        $db->insert('roles', [
            'role_id' => $roleId,
            'role_key' => $roleKey,
            'role_version' => 1,
            'schema_version' => '1',
            'display_name' => $roleKey,
            'status' => 'ACTIVE',
            'created_at' => $now,
        ]);
        foreach ($permissions as $permissionKey) {
            $exists = $db->one('SELECT permission_key FROM permissions WHERE permission_key = ?', [$permissionKey]);
            if ($exists === null) {
                $db->insert('permissions', [
                    'permission_key' => $permissionKey,
                    'schema_version' => '1',
                    'display_name' => $permissionKey,
                    'created_at' => $now,
                ]);
            }
            $db->insert('role_permissions', ['role_id' => $roleId, 'permission_key' => $permissionKey]);
        }

        return ['role_id' => $roleId];
    }

    public static function membership(
        Database $db,
        Clock $clock,
        string $tenantId,
        string $workspaceId,
        string $operatorId,
        string $roleId,
    ): string {
        $now = $clock->now();
        $membershipId = Ids::new();
        $db->insert('workspace_memberships', [
            'membership_id' => $membershipId,
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'principal_type' => 'OPERATOR',
            'principal_id' => $operatorId,
            'role_id' => $roleId,
            'role_version' => 1,
            'status' => 'ACTIVE',
            'valid_from' => $now,
            'created_at' => $now,
        ]);

        return $membershipId;
    }

    /** @return array{platform_user_id: string, email: string, password: string} */
    public static function platformUser(
        Database $db,
        Clock $clock,
        string $tenantId,
        string $operatorId,
        string $email = 'operator@example.test',
        string $password = 'correct horse battery staple',
    ): array {
        $now = $clock->now();
        $hashed = PasswordPolicy::hash($password);
        $platformUserId = Ids::new();
        $db->insert('platform_users', [
            'platform_user_id' => $platformUserId,
            'schema_version' => '1',
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'email' => $email,
            'display_name' => 'Test Operator',
            'password_hash' => $hashed['hash'],
            'password_algo' => $hashed['algo'],
            'status' => 'ACTIVE',
            'password_changed_at' => $now,
            'created_at' => $now,
        ]);

        return ['platform_user_id' => $platformUserId, 'email' => $email, 'password' => $password];
    }

    /**
     * The full P01 evidence chain behind one company signal:
     * competitor -> source reference + licence card -> monitored source -> two snapshots -> diff -> signal.
     *
     * Built the way the product builds it, not shortcut: a diff REQUIRES two snapshots, a snapshot
     * REQUIRES an evidence object, and an evidence object requires an admitted source. Seeding a
     * signal without that chain would let a test prove behavior over a state the product can never
     * actually reach.
     *
     * `$overrides` bends one link at a time so a single refusal path can be isolated.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, string>
     */
    public static function p01SignalChain(
        Database $db,
        Clock $clock,
        array $scope,
        string $missionId,
        ?string $proposalId,
        array $overrides = [],
    ): array {
        $now = $clock->now();
        $m05 = new EvidenceSpine($db, $clock);

        $competitorId = Ids::new();
        $db->insert('competitors', [
            'competitor_id' => $competitorId,
            'schema_version' => '1',
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'display_name' => 'Competitor A',
            'competitor_class' => $overrides['competitor_class'] ?? 'DIRECT',
            'status' => 'ACTIVE',
            'created_at' => $now,
        ]);

        $sourceRefId = $m05->registerSource(
            $scope['tenant_id'], $scope['workspace_id'], 'WEB_RESOURCE',
            'https://competitor.example/pricing', 'OBSERVE_AND_DERIVE',
            'PUBLIC_OR_NON_SENSITIVE', 'ACTIVE_FOR_CAPTURE'
        );

        $cardId = Ids::new();
        $db->insert('source_license_cards', [
            'source_license_card_id' => $cardId,
            'schema_version' => '1',
            'tenant_id' => $scope['tenant_id'],
            'source_ref_id' => $sourceRefId,
            'source_name' => 'Competitor pricing page',
            'source_url_safe' => 'https://competitor.example/pricing',
            'source_type' => 'website',
            'allowed_use_class' => 'customer_visible_allowed',
            'license_class' => $overrides['license_class'] ?? 'PUBLIC_WEB_ALLOWED',
            'contract_reference_id' => $overrides['contract_reference_id'] ?? (str_starts_with($overrides['license_class'] ?? '', 'LICENSED_') ? 'fixture-approved-source-contract' : null),
            'customer_output_allowed' => $overrides['customer_output_allowed'] ?? 1,
            'derived_summary_allowed' => 1,
            'caching_policy' => 'standard_cache',
            'retention_class' => 'standard',
            // Defaults to 'none'. Overridable so a case can produce a RESTRICTED_REVIEW sensitivity
            // ceiling without also holding the compile, which is a different fold branch.
            'pii_risk_class' => $overrides['pii_risk_class'] ?? 'none',
            'robots_policy_checked' => 1,
            'terms_policy_checked' => 1,
            'review_status' => $overrides['review_status'] ?? 'approved',
            'approved_at' => $now,
            'review_expires_at' => $overrides['review_expires_at'] ?? null,
            // The freshness policy the SOURCE_FRESHNESS audit reads. Null by default: a card that
            // never asked for a recheck window is not failing a rule it did not set.
            'warn_after_ms' => $overrides['warn_after_ms'] ?? null,
            'error_after_ms' => $overrides['error_after_ms'] ?? null,
            'recheck_terms_after_ms' => $overrides['recheck_terms_after_ms'] ?? null,
            'created_at' => $now,
        ]);

        // The terms review the card's recheck window is measured against. Skipped when the case is
        // specifically about a card that requires one and has none.
        if (($overrides['skip_terms_review'] ?? false) !== true) {
            $db->insert('source_terms_reviews', [
                'source_terms_review_id' => Ids::new(),
                'schema_version' => '1',
                'source_license_card_id' => $cardId,
                'robots_url' => 'https://competitor.example/robots.txt',
                'terms_url' => 'https://competitor.example/terms',
                'robots_policy_checked' => 1,
                'terms_policy_checked' => 1,
                'reviewed_by' => 'operator',
                'reviewed_at' => $overrides['terms_reviewed_at'] ?? $now,
                'expires_at' => $overrides['terms_expires_at'] ?? null,
            ]);
        }

        $monitoredSourceId = Ids::new();
        $db->insert('monitored_sources', [
            'monitored_source_id' => $monitoredSourceId,
            'schema_version' => '1',
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'competitor_id' => $competitorId,
            'source_ref_id' => $sourceRefId,
            'source_license_card_id' => $cardId,
            'source_kind' => 'WEBSITE',
            'status' => 'ACTIVE',
            'created_at' => $now,
        ]);

        // Two snapshots, each backed by its own evidence object — the N-1 / N pair a diff is over.
        $snapshotIds = [];
        $snapshotHashes = [];
        $snapshotProofs = [];
        foreach ([['449 SEK', 1], ['499 SEK', 2]] as [$body, $sequence]) {
            $contentHash = Digest::contentDigest($body . $monitoredSourceId);
            // Explicit synthetic capture proof for this fixture; no network capture is claimed.
            $proof = \Allfather\Loopia\Product\P01\TextProvenance::capture($contentHash, $body);
            $evidenceId = $m05->capture([
                'tenant_id' => $scope['tenant_id'],
                'workspace_id' => $scope['workspace_id'],
                'mission_id' => $missionId,
                'source_ref_id' => $sourceRefId,
                'evidence_type' => 'SOURCE_CAPTURE',
                'captured_content_reference_safe' => 'evidence://sha256/' . substr($contentHash, 0, 12),
                'content_hash' => $contentHash,
                'capture_method' => 'GOVERNED_HTTPS_GET',
                'sensitivity_class' => 'PUBLIC_OR_NON_SENSITIVE',
            ])->value()['evidence_id'];

            $snapshotId = Ids::new();
            $db->insert('source_snapshots', [
                'source_snapshot_id' => $snapshotId,
                'schema_version' => '1',
                'tenant_id' => $scope['tenant_id'],
                'workspace_id' => $scope['workspace_id'],
                'monitored_source_id' => $monitoredSourceId,
                'evidence_id' => $evidenceId,
                'snapshot_sequence' => $sequence,
                'content_hash' => $contentHash,
                'hash_algorithm' => 'sha256',
                'capture_method' => 'FIXTURE',
                'text_provenance_json' => json_encode($proof, JSON_THROW_ON_ERROR),
                'payload_class' => 'html_snapshot',
                'captured_at' => $now,
            ]);
            $snapshotIds[] = $snapshotId;
            $snapshotHashes[] = $contentHash;
            $snapshotProofs[] = $proof;
        }

        $classified = \Allfather\Loopia\Product\P01\TextProvenance::classify($snapshotHashes[0], $snapshotHashes[1], $snapshotProofs[0], $snapshotProofs[1], 0.25);
        $classified['significance'] = $overrides['change_significance'] ?? $classified['significance'];
        $diffId = Ids::new();
        $db->insert('source_diffs', [
            'source_diff_id' => $diffId,
            'schema_version' => '1',
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'monitored_source_id' => $monitoredSourceId,
            'before_snapshot_id' => $snapshotIds[0],
            'after_snapshot_id' => $snapshotIds[1],
            'diff_digest' => \Allfather\Loopia\Product\P01\TextProvenance::digest([
                'beforeSnapshotId' => $snapshotIds[0], 'afterSnapshotId' => $snapshotIds[1],
                'beforeContentHash' => $snapshotHashes[0], 'afterContentHash' => $snapshotHashes[1],
                'before' => $snapshotProofs[0], 'after' => $snapshotProofs[1], 'threshold' => 0.25, ...$classified,
            ]),
            'classifier_version' => \Allfather\Loopia\Product\P01\TextProvenance::CLASSIFIER_VERSION,
            'material_change_threshold' => 0.25,
            'hash_algorithm' => 'sha256',
            'change_significance' => $classified['significance'],
            'diff_summary_safe' => $classified['summary'],
            'detected_at' => $now,
        ]);

        $signalId = Ids::new();
        $db->insert('company_signals', [
            'company_signal_id' => $signalId,
            'schema_version' => '1',
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'competitor_id' => $competitorId,
            'source_diff_id' => $diffId,
            'signal_class' => 'PRICING_CHANGE_SIGNAL',
            // FACT_OBSERVED: the price on the page changed, and that IS what was observed. The
            // interpretation of WHY is a separate, HYPOTHESIS-classed claim.
            'interpretation_kind' => $overrides['interpretation_kind'] ?? 'FACT_OBSERVED',
            'confidence_band' => 'HIGH',
            'summary_safe' => 'List price rose from 449 to 499 SEK.',
            'proposal_id' => $proposalId,
            'status' => 'PROPOSED',
            'created_at' => $now,
        ]);

        return [
            'competitor_id' => $competitorId,
            'source_ref_id' => $sourceRefId,
            'source_license_card_id' => $cardId,
            'monitored_source_id' => $monitoredSourceId,
            'before_snapshot_id' => $snapshotIds[0],
            'after_snapshot_id' => $snapshotIds[1],
            'source_diff_id' => $diffId,
            'company_signal_id' => $signalId,
        ];
    }

    /**
     * The WHOLE governed chain, run through the real modules rather than inserted:
     *
     *   valkyrie + contract -> mission -> proposal -> evidence -> bundle (frozen)
     *     -> council session + assessment + frozen result
     *     -> arbiter verdict
     *     -> M10 INTERNAL_INFORMATIONAL_RELEASE record
     *
     * Every product test needs this, and every step of it is a gate. Inserting the end state
     * directly would let a test prove behavior over a state the product cannot reach — and the
     * foreign keys would reject most of it anyway, which is the schema saying the same thing.
     *
     * `$signalOverrides` bends one link of the P01 evidence chain so a single refusal path can be
     * isolated; `$councilRecommendation` bends the review outcome.
     *
     * @param array<string, mixed> $signalOverrides
     * @return array<string, mixed>
     */
    public static function governedReleaseChain(
        Database $db,
        Clock $clock,
        array $scope,
        array $signalOverrides = [],
        string $councilRecommendation = 'PROCEED_TO_ARBITER',
        string $proposalType = 'BATTLECARD_BRIEF',
        // Stop after the council freeze, leaving the proposal SUBMITTED and reviewed but NOT yet
        // evaluated. A caller that will run the arbiter itself needs the chain in exactly that
        // state — running it here first would leave the proposal already released, and the second
        // evaluation would (correctly) refuse.
        bool $stopBeforeArbiter = false,
    ): array {
        $m03 = new Registry($db, $clock);
        $m04 = new Proposals($db, $clock);
        $m05 = new EvidenceSpine($db, $clock);
        $m07 = new Council($db, $clock);
        $m08 = new Arbiter($db, $clock, new ReadinessEvaluator($db, $clock));
        $m10 = new DecisionLedger($db, $clock);
        $policies = new PolicySets($db, $clock);

        $policySetId = $policies->seed(PolicySets::P01_COMPETITION_RELEVANCE, $scope['tenant_id'], $scope['workspace_id'])
            ->value()['arbiter_policy_set_id'];

        $producer = $m03->registerValkyrie(
            $scope['tenant_id'], $scope['workspace_id'], 'Competition Monitor',
            'COMPETITION_MONITOR_VALKYRIE', 'Monitors approved competitor sources.',
            $scope['operator_id'], 'ACTIVE'
        );
        $critic = $m03->registerValkyrie(
            $scope['tenant_id'], $scope['workspace_id'], 'Review Critic',
            'REVIEW_CRITIC_VALKYRIE', 'Independent review.', $scope['operator_id'], 'ACTIVE'
        );
        $contractId = $m03->issueContract(
            $producer, $scope['tenant_id'], $scope['workspace_id'], 'p01.monitor',
            'COMPETITION_MONITOR_VALKYRIE', ['OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE']
        )->value()['capability_contract_id'];

        $missionId = $m04->createMission(
            $scope['tenant_id'], $scope['workspace_id'], 'COMPETITION_MONITORING',
            'Monitor competitor pricing', 'Detect and brief material pricing changes.',
            ['OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE'], 'OPERATOR', $scope['operator_id']
        );

        $proposalId = $m04->createDraft([
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'mission_id' => $missionId,
            'producing_valkyrie_id' => $producer,
            'capability_contract_id' => $contractId,
            'proposal_type' => $proposalType,
            'requested_action_class' => 'RELEASE_INTELLIGENCE',
            'title' => 'Competitor A raised list pricing',
            'safe_purpose_summary' => 'Brief the customer on an observed pricing change.',
            'proposed_content_or_action_summary' => 'List price moved 449 -> 499 SEK.',
            'uncertainty_summary' => 'Applicability to existing customers is NOT observed.',
        ])->value()['proposal_id'];

        $chain = self::p01SignalChain($db, $clock, $scope, $missionId, $proposalId, $signalOverrides);

        // The evidence bundle: the frozen basis the verdict is decided on.
        $evidenceId = (string) $db->scalar(
            'SELECT e.evidence_id FROM evidence_objects e
             JOIN source_snapshots s ON s.evidence_id = e.evidence_id
             WHERE s.source_snapshot_id = ?',
            [$chain['after_snapshot_id']]
        );
        $bundleId = $m05->createBundleDraft(
            $scope['tenant_id'], $scope['workspace_id'], $missionId, $proposalId, 'P01 signal basis'
        );
        $m05->addBundleItem($bundleId, $evidenceId);
        $m05->submitBundle($bundleId);

        $m04->submit($proposalId);

        $sessionId = $m07->openSession(
            $scope['tenant_id'], $scope['workspace_id'], $missionId, $proposalId,
            'MULTI_REVIEWER_DEMO', 'COMPETITION_RELEVANCE_REVIEW', 'OPERATOR', $scope['operator_id']
        )->value()['review_session_id'];
        $assignmentId = $m07->assignParticipant($sessionId, $critic, 'EVIDENCE_CRITIC', 'INDEPENDENT_DECLARED_REVIEWER')
            ->value()['participant_assignment_id'];
        $m07->recordAssessment(
            $sessionId, $assignmentId, $critic,
            $councilRecommendation === 'PROCEED_TO_ARBITER' ? 'SUPPORT_PROCEED_TO_ARBITER' : 'HOLD',
            'Reviewed.'
        );
        $m07->freezeResult($sessionId, $councilRecommendation, 'Council result.');

        if ($stopBeforeArbiter) {
            return [
                ...$chain,
                'arbiter_policy_set_id' => $policySetId,
                'producing_valkyrie_id' => $producer,
                'critic_valkyrie_id' => $critic,
                'capability_contract_id' => $contractId,
                'mission_id' => $missionId,
                'proposal_id' => $proposalId,
                'evidence_id' => $evidenceId,
                'evidence_bundle_id' => $bundleId,
                'review_session_id' => $sessionId,
                'verdict' => null,
                'verdict_refusal' => null,
                'release' => null,
            ];
        }

        $verdict = $m08->evaluateProposal($proposalId, $policySetId);

        $release = null;
        if ($verdict->isOk() && $verdict->value()['verdict'] === 'ALLOW_INFORMATIONAL_RELEASE') {
            // The seed releases the way a product does: it hands M10 the verdict identity and
            // nothing else. M10 validates the governed records, mints the record on the basis it
            // read, and flips the envelope to RELEASED itself, in one transaction.
            $release = $m10->appendInternalReleaseDecisionRecord(
                (string) $verdict->value()['arbiter_verdict_id']
            )->value();
        }

        return [
            ...$chain,
            'arbiter_policy_set_id' => $policySetId,
            'producing_valkyrie_id' => $producer,
            'critic_valkyrie_id' => $critic,
            'capability_contract_id' => $contractId,
            'mission_id' => $missionId,
            'proposal_id' => $proposalId,
            'evidence_id' => $evidenceId,
            'evidence_bundle_id' => $bundleId,
            'review_session_id' => $sessionId,
            'verdict' => $verdict->isOk() ? $verdict->value() : null,
            'verdict_refusal' => $verdict->isOk() ? null : $verdict->reasonCode(),
            'release' => $release,
        ];
    }

    /**
     * A complete governed READ-ONLY tool chain, ready for an M06 dispatch.
     *
     * Everything the gate chain reads is seeded through the real modules, not inserted as end
     * state: the runtime host, its adapter, the binding and tool ref, the governed tool and its
     * operation, the Valkyrie contract's tool right, the mission assignment, and the tenant's
     * subscription entitlement. Each of those is a gate M06 will actually consult, so seeding one
     * incorrectly makes the test fail rather than pass for the wrong reason.
     *
     * The whole chain is SAFE_READ_ONLY / OBSERVE. Nothing here can reach an external effect.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public static function governedToolChain(
        Database $db,
        Clock $clock,
        array $scope,
        string $safetyClassification = 'SAFE_READ_ONLY',
        string $actionClass = 'OBSERVE',
        string $toolClass = 'READ_ONLY_SOURCE',
    ): array {
        $now = $clock->now();
        $m03 = new Registry($db, $clock);
        $m03e = new Eligibility($db, $clock);
        $m04 = new Proposals($db, $clock);
        $m06 = new ToolRegistry($db, $clock);

        // --- the runtime host and its adapter ---------------------------------------------------
        $hostId = Ids::new();
        // Each chain gets its OWN host and adapter identity. A shared one would make two chains in
        // the same test share a dispatch surface, so a refusal proved on one could be an artefact
        // of the other's state rather than of the gate under test.
        $suffix = substr($hostId, 0, 8);
        $db->insert('runtime_host_instances', [
            'runtime_host_instance_id' => $hostId,
            'schema_version' => '1',
            'host_family' => 'LOOPIA_TEST_HOST',
            'host_name' => 'loopia-read-host-' . $suffix,
            'host_instance_key' => 'loopia-read-host-' . $suffix,
            'host_version' => '1.0.0',
            'repository_reference_safe' => 'internal://loopia/read-host',
            'licence_status_ref' => 'internal',
            'integration_map_document_ref' => 'docs/SCHEMA_REACHABILITY.md',
            'environment_class' => 'TEST',
            'declared_security_assumptions' => json_encode(['NO_EXTERNAL_EFFECT'], JSON_THROW_ON_ERROR),
            'status' => 'REGISTERED',
            'created_at' => $now,
        ]);

        // The identity payloads must be in the project's CANONICAL form, because that is exactly
        // what AdapterConformance re-derives at every invocation. Writing them any other way makes
        // the adapter non-conformant, which is the intended failure mode for a drifted identity.
        $descriptor = Digest::canonicalize(['adapterId' => 'loopia-read-' . $suffix, 'adapterVersion' => '1.0.0']);
        $manifest = Digest::canonicalize(['capabilities' => ['READ_MODEL']]);
        $profile = Digest::canonicalize(['profile' => 'READ_ONLY', 'version' => '1']);
        $adapterId = Ids::new();
        $identity = [
            'runtime_host_instance_id' => $hostId,
            'adapter_family' => 'LOOPIA_READ',
            'adapter_name' => 'loopia-read-' . $suffix,
            'adapter_version' => '1.0.0',
            'descriptor_identity' => $descriptor,
            'capability_manifest_identity' => $manifest,
            'conformance_profile_identity' => $profile,
        ];
        $db->insert('runtime_adapter_instances', [
            'runtime_adapter_instance_id' => $adapterId,
            'schema_version' => '1',
            ...$identity,
            'identity_digest' => AdapterConformance::identityDigest($identity),
            'status' => 'READY',
            'created_at' => $now,
            'retired_at' => null,
        ]);

        // --- the binding and its tool surface ---------------------------------------------------
        $bindingId = Ids::new();
        $db->insert('runtime_host_bindings', [
            'binding_id' => $bindingId,
            'schema_version' => '1',
            'runtime_host_instance_id' => $hostId,
            'runtime_adapter_instance_id' => $adapterId,
            'binding_type' => 'TOOL_SURFACE',
            'runtime_host_reference' => 'loopia-read-host-' . $suffix . ':tool',
            'runtime_host_version' => '1.0.0',
            'mapping_purpose' => 'Read-only competitor source capture.',
            'permitted_operation_classes' => json_encode([$actionClass], JSON_THROW_ON_ERROR),
            'safety_classification' => $safetyClassification,
            'status' => 'ACTIVE',
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'activation_evidence_refs' => json_encode([], JSON_THROW_ON_ERROR),
            'activated_at' => $now,
            'created_at' => $now,
        ]);

        // --- the governed tool and operation ----------------------------------------------------
        $toolId = $m06->registerTool([
            'tool_key' => 'p01.source.read.' . $suffix,
            'display_name' => 'Competitor source read',
            'purpose_summary' => 'Read an approved competitor source page.',
            'tool_class' => $toolClass,
            'risk_level' => 'T1',
            'maximum_action_class' => $actionClass,
            'runtime_mapping_ref_id' => $bindingId,
            'evidence_capture_required_by_default' => true,
        ]);
        $operationId = $m06->registerOperation([
            'governed_tool_id' => $toolId,
            'operation_key' => 'source.read',
            'operation_summary' => 'Fetch one approved source URL.',
            'operation_class' => 'READ',
            'classified_action_class' => $actionClass,
            'requires_evidence_capture' => true,
        ]);

        $toolRefId = Ids::new();
        $db->insert('runtime_host_tool_refs', [
            'tool_ref_id' => $toolRefId,
            'schema_version' => '1',
            'binding_id' => $bindingId,
            'governed_tool_id' => $toolId,
            'runtime_host_tool_ref' => 'loopia-read-' . $suffix . ':source.read',
            'tool_surface_type' => 'READ',
            'declared_operation_class' => $actionClass,
            'mapping_status' => 'ACTIVE',
            'version' => '1.0.0',
            'created_at' => $now,
        ]);

        // --- the agent, its contract, its tool right and its assignment --------------------------
        $valkyrieId = $m03->registerValkyrie(
            $scope['tenant_id'], $scope['workspace_id'], 'Source Reader',
            'COMPETITION_MONITOR_VALKYRIE', 'Reads approved competitor sources.',
            $scope['operator_id'], 'ACTIVE'
        );
        // The contract and mission both carry the chain's action class, so a refusal on a wider
        // class comes from the gate under test rather than from an upstream plane that never
        // granted it. Making M03 the refuser for every class would prove only that M03 runs first.
        $grantedClasses = array_values(array_unique(['OBSERVE', 'ANALYZE', $actionClass]));
        $contractId = $m03->issueContract(
            $valkyrieId, $scope['tenant_id'], $scope['workspace_id'], 'p01.read',
            'COMPETITION_MONITOR_VALKYRIE', $grantedClasses
        )->value()['capability_contract_id'];
        $toolRightId = $m03e->grantToolRight(
            $contractId, $toolId, ['source.read'], $toolClass, $actionClass
        );

        $missionId = $m04->createMission(
            $scope['tenant_id'], $scope['workspace_id'], 'COMPETITION_MONITORING',
            'Read approved competitor sources', 'Capture source pages for change detection.',
            $grantedClasses, 'OPERATOR', $scope['operator_id']
        );
        $assignmentId = $m04->assignToMission(
            $missionId, $valkyrieId, $contractId, 'SOURCE_READER',
            [$toolId], [$actionClass], $scope['operator_id']
        )->value()['assignment_id'];

        // --- the tenant's commercial entitlement -------------------------------------------------
        // The tier is a PLATFORM catalogue row, not a per-tenant one, so a second chain reuses the
        // existing tier rather than creating a rival definition of the same product.
        $existingTier = $db->scalar(
            "SELECT subscription_tier_id FROM subscription_tiers WHERE tier_key = 'sme_standard'"
        );
        $tierId = $existingTier === null ? Ids::new() : (string) $existingTier;
        if ($existingTier === null) {
            $db->insert('subscription_tiers', [
            'subscription_tier_id' => $tierId,
            'schema_version' => '1',
            'tier_key' => 'sme_standard',
            'tier_version' => '1',
            'display_name' => 'SME Standard',
            'monthly_fee_minor_units' => 249900,
            'currency' => 'SEK',
            'billing_period' => 'MONTHLY',
            'status' => 'ACTIVE',
            'created_at' => $now,
            'activated_at' => $now,
            ]);
        }
        // The entitlement plane references the SAME permission vocabulary M02 uses. A tier cannot
        // grant a permission the platform does not define, which is what stops the commercial
        // plane from inventing authority the access plane never heard of.
        if ($db->one('SELECT permission_key FROM permissions WHERE permission_key = ?', ['competition_monitoring.read']) === null) {
            $db->insert('permissions', [
                'permission_key' => 'competition_monitoring.read',
                'schema_version' => '1',
                'display_name' => 'competition_monitoring.read',
                'created_at' => $now,
            ]);
        }
        if ($db->one(
            'SELECT permission_key FROM subscription_tier_permissions WHERE subscription_tier_id = ? AND permission_key = ?',
            [$tierId, 'competition_monitoring.read']
        ) === null) {
            $db->insert('subscription_tier_permissions', [
                'subscription_tier_id' => $tierId,
                'permission_key' => 'competition_monitoring.read',
            ]);
        }

        // A tenant holds ONE live subscription. A second chain in the same tenant subscribes to the
        // same one rather than opening a rival subscription, which would make the entitlement gate
        // answer from whichever row it happened to read first.
        $existingSubscription = $db->scalar(
            "SELECT tenant_subscription_id FROM tenant_subscriptions
             WHERE tenant_id = ? AND status IN ('TRIAL', 'ACTIVE')",
            [$scope['tenant_id']]
        );
        $subscriptionId = $existingSubscription === null ? Ids::new() : (string) $existingSubscription;
        if ($existingSubscription === null) {
            $db->insert('tenant_subscriptions', [
                'tenant_subscription_id' => $subscriptionId,
                'schema_version' => '1',
                'tenant_id' => $scope['tenant_id'],
                'subscription_tier_id' => $tierId,
                'status' => 'ACTIVE',
                'started_at' => $now,
            ]);
        }

        return [
            'runtime_host_instance_id' => $hostId,
            'runtime_adapter_instance_id' => $adapterId,
            'binding_id' => $bindingId,
            'tool_ref_id' => $toolRefId,
            'runtime_host_tool_ref' => 'loopia-read-' . $suffix . ':source.read',
            'governed_tool_id' => $toolId,
            'governed_tool_operation_id' => $operationId,
            'valkyrie_id' => $valkyrieId,
            'capability_contract_id' => $contractId,
            'tool_right_id' => $toolRightId,
            'mission_id' => $missionId,
            'assignment_id' => $assignmentId,
            'subscription_tier_id' => $tierId,
            'tenant_subscription_id' => $subscriptionId,
        ];
    }
    /**
     * A REAL released P01 briefing, produced by the product rather than inserted.
     *
     * P04 and P05 both route intelligence that P01 already released, so their tests need a
     * genuine `released_briefings` row — one carrying an M10 decision record whose reliance can
     * then be withdrawn to prove the downstream re-checks. Writing the row directly would give
     * the tests a source the product could not have produced, and the very reliance the P04
     * gates read would have nothing behind it.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public static function p01ReleasedBriefing(Database $db, Clock $clock, array $scope): array
    {
        // stopBeforeArbiter: the battlecard module issues the verdict, so the chain must arrive
        // reviewed but unevaluated.
        $governed = self::governedReleaseChain(
            $db, $clock, $scope, [], 'PROCEED_TO_ARBITER', 'COMPANY_SIGNAL_BRIEF', true
        );

        $signalId = Ids::new();
        $db->insert('company_signals', [
            'company_signal_id' => $signalId,
            'schema_version' => '1',
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'competitor_id' => $governed['competitor_id'],
            'source_diff_id' => $governed['source_diff_id'],
            'signal_class' => 'PRICING_CHANGE_SIGNAL',
            'interpretation_kind' => 'FACT_OBSERVED',
            'confidence_band' => 'HIGH',
            'summary_safe' => 'List price line changed.',
            'proposal_id' => $governed['proposal_id'],
            'status' => 'UNDER_REVIEW',
            'created_at' => $clock->now(),
        ]);

        $arbiter = new Arbiter($db, $clock, new ReadinessEvaluator($db, $clock));
        $battlecards = new Battlecards($db, $clock, $arbiter);
        $verdict = $battlecards->issueVerdict([
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'company_signal_id' => $signalId,
            'arbiter_policy_set_id' => $governed['arbiter_policy_set_id'],
            'content' => [
                'why_it_matters_safe' => 'The competitor raised list pricing on its public page.',
                'customer_impact_hypothesis_safe' => 'Existing customers MAY face a renewal increase; not observed.',
                'talk_track_safe' => 'Ask whether the customer has seen a renewal notice.',
                'recommended_response_safe' => 'Reconfirm our own price stability in writing.',
                'rejected_claims_safe' => 'Not supported: that existing contracts were repriced.',
            ],
        ]);

        $ledger = new DecisionLedger($db, $clock);
        $released = (new P01Release(
            $db, $clock, $ledger, new ReleaseOutput($db, $clock, $ledger)
        ))->releaseBriefing([
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'battlecard_brief_id' => $verdict->value()['battlecard_brief_id'],
        ]);

        return [
            ...$governed,
            'company_signal_id' => $signalId,
            'battlecard_brief_id' => $verdict->value()['battlecard_brief_id'],
            'released_briefing_id' => $released->value()['released_briefing_id'],
            'decision_record_id' => $released->value()['decision_record_id'],
        ];
    }

    /**
     * A producer authorized to propose a CRM outreach, and a released source to cite.
     *
     * The P07 producer-authority gate reads five things no other product path needs: an ACTIVE
     * Valkyrie, a current contract, a CRM-outreach OUTPUT-CLASS binding on that contract, a mission
     * permitting that output class, and an assignment granting both ANALYZE and that class. Each
     * is provisioned here through the real modules, because a seed that inserted the end state
     * would let the test pass over authority the product cannot actually grant.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public static function p07OutreachProducer(Database $db, Clock $clock, array $scope): array
    {
        $m03 = new Registry($db, $clock);
        $m04 = new Proposals($db, $clock);

        $producer = $m03->registerValkyrie(
            $scope['tenant_id'], $scope['workspace_id'], 'Outreach Drafter',
            'CRM_EXECUTION_VALKYRIE', 'Drafts consented outreach proposals.',
            $scope['operator_id'], 'ACTIVE'
        );
        $critic = $m03->registerValkyrie(
            $scope['tenant_id'], $scope['workspace_id'], 'Outreach Critic',
            'REVIEW_CRITIC_VALKYRIE', 'Independent compliance review.', $scope['operator_id'], 'ACTIVE'
        );
        $contractId = $m03->issueContract(
            $producer, $scope['tenant_id'], $scope['workspace_id'], 'p07.outreach',
            'CRM_EXECUTION_VALKYRIE', ['OBSERVE', 'ANALYZE']
        )->value()['capability_contract_id'];
        // The OUTPUT-class binding. Distinct from the action classes above: the contract says the
        // agent may analyse, and this says one of the things it may produce is an outreach proposal.
        $m03->bindOutputClass($contractId, 'CRM_OUTREACH_EXECUTION_PROPOSAL', 'ANALYZE');

        $missionId = $m04->createMission(
            $scope['tenant_id'], $scope['workspace_id'], 'CRM_OUTREACH',
            'Consented outreach preparation', 'Prepare consented, pseudonymized outreach for approval.',
            ['OBSERVE', 'ANALYZE'], 'OPERATOR', $scope['operator_id'], 'ACTIVE',
            ['INTERNAL_INFORMATIONAL', 'CRM_OUTREACH_EXECUTION_PROPOSAL']
        );
        $m04->assignToMission(
            $missionId, $producer, $contractId, 'PRIMARY_PRODUCER', [], ['OBSERVE', 'ANALYZE'],
            $scope['operator_id'], 'ACTIVE',
            ['INTERNAL_INFORMATIONAL', 'CRM_OUTREACH_EXECUTION_PROPOSAL']
        );

        // A real released P01 briefing supplies the M10 decision record an outreach must cite.
        $source = self::p01ReleasedBriefing($db, $clock, $scope);

        return [
            'producing_valkyrie_id' => $producer,
            'critic_valkyrie_id' => $critic,
            'capability_contract_id' => $contractId,
            'mission_id' => $missionId,
            'source_decision_record_id' => $source['decision_record_id'],
            'released_briefing_id' => $source['released_briefing_id'],
        ];
    }

}
