<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M08;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P02\PrivacyPatterns;
use Allfather\Loopia\Product\P03\FitScoring;
use Allfather\Loopia\Product\P05\MarketingPatterns;
use Allfather\Loopia\Product\P07A\ContentPolicy;
use Allfather\Loopia\Product\P07A\PiiFirewall;

/**
 * The ordinary-rule evaluators, one family per product module.
 *
 * Ported from `packages/allfather-control-substrate/src/m08/arbiter.ts`
 * @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d.
 *
 * Two properties hold across EVERY family and are the reason this file exists as one place:
 *
 *  - **A missing context is NOT_APPLICABLE, never a pass by omission.** A rule family governs its
 *    own object kind; asked about a proposal it does not govern, it stands aside rather than
 *    approving.
 *  - **An UNKNOWN rule key FAILS CLOSED to the policy default.** A policy row naming a rule this
 *    engine does not implement must not silently pass — that would let a policy grant permission by
 *    misspelling a rule.
 *
 * Rules are evaluated priority-descending and the FIRST failure decides, so a permissive rule can
 * never reach a later allow.
 */
final class RuleEngine
{
    /** Competitor classes outside the customer's competitive interest. */
    private const P01_OUTSIDE_INTEREST_CLASSES = ['MARKET_REFERENCE', 'OTHER'];

    /** Licence classes that cannot back a customer-visible intelligence output. */
    private const P01_LOW_AUTHORITY_LICENSES = [
        'LICENSED_NO_CUSTOMER_OUTPUT', 'LICENSED_INTERNAL_ONLY', 'PROHIBITED',
    ];

    /**
     * Load the rule context for a proposal.
     *
     * Only the family matching the proposal type is loaded: a `COMPANY_SIGNAL_BRIEF` has no P03
     * fit context to fetch, and querying for one would be work whose only possible answer is null.
     *
     * @return array<string, mixed>
     */
    public static function loadContext(Database $db, string $proposalId, string $proposalType): array
    {
        return match ($proposalType) {
            'COMPANY_SIGNAL_BRIEF', 'BATTLECARD_BRIEF' => ['P01_RELEVANCE' => self::loadP01Context($db, $proposalId)],
            'FIT_HYPOTHESIS', 'PRIORITY_OUTREACH_QUEUE_ADMISSION' => [
                'P03_PROSPECT_ADMISSION' => self::loadP03Context($db, $proposalId, $proposalType),
            ],
            'ENTITY_TWIN_STATE_CHANGE' => [
                'P02_TWIN_STATE_ADMISSION' => self::loadP02Context($db, $proposalId),
            ],
            'INTELLIGENCE_DISTRIBUTION_ROUTING' => [
                'P04_DISTRIBUTION_ADMISSION' => self::loadP04Context($db, $proposalId),
            ],
            'MARKETING_ASSET_DRAFT' => [
                'P05_MARKETING_ASSET_ADMISSION' => self::loadP05Context($db, $proposalId),
            ],
            'CRM_OUTREACH_EXECUTION' => [
                'P07_OUTREACH_EXECUTION_ADMISSION' => self::loadP07Context($db, $proposalId),
            ],
            default => [],
        };
    }

    /**
     * The P01 signal context: the competitor's class and the backing source card's authority.
     *
     * @return array<string, mixed>|null null when the proposal binds no company signal
     */
    public static function loadP01Context(Database $db, string $proposalId): ?array
    {
        return $db->one(
            'SELECT c.competitor_class AS competitor_class,
                    slc.customer_output_allowed AS customer_output_allowed,
                    slc.license_class AS license_class,
                    slc.review_status AS review_status,
                    slc.review_expires_at AS review_expires_at,
                    sd.change_significance AS change_significance,
                    cs.source_diff_id AS source_diff_id
             FROM company_signals cs
             JOIN competitors c ON c.competitor_id = cs.competitor_id
             LEFT JOIN source_diffs sd ON sd.source_diff_id = cs.source_diff_id
             LEFT JOIN monitored_sources ms ON ms.monitored_source_id = sd.monitored_source_id
             LEFT JOIN source_license_cards slc ON slc.source_license_card_id = ms.source_license_card_id
             WHERE cs.proposal_id = ?
             ORDER BY cs.created_at DESC, cs.company_signal_id DESC LIMIT 1',
            [$proposalId]
        );
    }

    /**
     * The P03 fit context: the bound hypothesis, its cited signal, and the catalogue behind it.
     *
     * The proposal TYPE is carried into the context because one rule — the buyer-intent gate —
     * behaves differently on the two admission paths, and it must read that from the context rather
     * than re-deriving it.
     *
     * @return array<string, mixed>|null null when the proposal binds no fit hypothesis
     */
    public static function loadP03Context(Database $db, string $proposalId, string $proposalType): ?array
    {
        $row = $db->one(
            'SELECT fh.fit_band, fh.claim_kind, fh.icp_profile_version_id,
                    cs.signal_class, cs.interpretation_kind, cs.status AS signal_status,
                    cs.summary_safe,
                    cat.catalogue_status,
                    p.attributes_json
             FROM fit_hypotheses fh
             JOIN company_signals cs ON cs.company_signal_id = fh.company_signal_id
             JOIN customer_products p ON p.customer_product_id = fh.customer_product_id
             JOIN customer_product_catalogues cat
               ON cat.customer_product_catalogue_id = p.customer_product_catalogue_id
             WHERE fh.proposal_id = ?
             ORDER BY fh.created_at DESC, fh.fit_hypothesis_id DESC LIMIT 1',
            [$proposalId]
        );
        if ($row === null) {
            return null;
        }
        $row['proposal_type'] = $proposalType;

        // The excluded-segment match is computed HERE, against the SAME tokenizer the P03 scorer
        // uses. Two tokenizers that disagreed would let a segment be excluded by the scorer and
        // admitted by this gate.
        $row['icp_excluded_segment_match'] = null;
        if ($row['icp_profile_version_id'] !== null) {
            $version = $db->one(
                'SELECT excluded_segments_json FROM icp_profile_versions WHERE icp_profile_version_id = ?',
                [(string) $row['icp_profile_version_id']]
            );
            if ($version !== null) {
                $excluded = json_decode((string) $version['excluded_segments_json'], true, 512, JSON_THROW_ON_ERROR);
                $facts = FitScoring::icpTerms(
                    ...json_decode((string) $row['attributes_json'], true, 512, JSON_THROW_ON_ERROR),
                    ...[(string) $row['summary_safe'], (string) $row['signal_class']],
                );
                foreach ($excluded as $segment) {
                    $segmentTerms = FitScoring::icpTerms($segment);
                    if ($segmentTerms !== [] && array_diff($segmentTerms, $facts) === []) {
                        $row['icp_excluded_segment_match'] = $segment;
                        break;
                    }
                }
            }
        }

        return $row;
    }

    /**
     * The signal classes that count as BUYING TRIGGERS.
     *
     * A closed set, and deliberately short. Each is a change that plausibly creates a moment — a
     * price move, a launch, an expansion, a compliance obligation. What makes them triggers is that
     * something happened at a knowable time, not that anyone inferred a desire from them.
     *
     * A class outside this set is not a weaker trigger; it is not a trigger, and a queue admission
     * citing one is asserting timeliness it cannot support.
     *
     * @var list<string>
     */
    /**
     * The profiling vocabulary a twin state may never carry.
     *
     * DETERMINISTIC MARKERS, not a classifier — the same honesty the other detectors carry. They
     * catch the named thing; the structural guarantee is that a twin has no column a profile could
     * live in, and this is the layer over it for the free-text fields that do exist.
     *
     * @var list<string>
     */
    private const P02_PROFILING_TERMS = [
        'psychographic', 'personality profile', 'emotional state', 'mental health',
        'political affiliation', 'religious belief', 'sexual orientation', 'private life',
        'health condition', 'sensitive trait',
    ];

    /** @var list<string> */
    private const P02_EMPLOYEE_SCORING_TERMS = [
        'employee score', 'productivity score', 'loyalty score', 'performance ranking',
        'covert monitoring', 'surveillance of staff', 'employee ranking',
    ];

    /**
     * The buyer-intent vocabulary — the same invariant P03 holds, held again at the twin gate.
     *
     * @var list<string>
     */
    private const P02_BUYER_INTENT_TERMS = [
        'intends to buy', 'wants to buy', 'ready to purchase', 'will purchase',
        'plans to purchase', 'in market to buy', 'intent to purchase',
    ];

    /**
     * Licence classes that are not public-sourced.
     *
     * An EXTERNAL twin describes somebody else's company, so whatever it says must have come from a
     * public source; a licensed or prohibited class is the failure.
     *
     * @var list<string>
     */
    private const NON_PUBLIC_LICENSE_CLASSES = [
        'LICENSED_INTERNAL_ONLY', 'LICENSED_NO_CUSTOMER_OUTPUT', 'PROHIBITED',
    ];

    public const BUYER_INTENT_TRIGGER_CLASSES = [
        'PRICING_CHANGE_SIGNAL',
        'PRODUCT_LAUNCH_SIGNAL',
        'HIRING_OR_EXPANSION_SIGNAL',
        'REGULATORY_OR_COMPLIANCE_SIGNAL',
    ];

    /**
     * The P03_PROSPECT_ADMISSION checks.
     *
     * A missing fit context makes them NOT_APPLICABLE — they govern lead-qualification admissions
     * only, and a proposal with no bound hypothesis has nothing here to judge.
     *
     * @param array<string, mixed>|null $ctx
     * @return array{decision: string, reason_codes: list<string>, severity: ?string, incident: bool, result: ?array<string, mixed>}
     */
    private static function evalP03(array $rule, ?array $ctx, string $failClosed): array
    {
        if ($ctx === null) {
            return self::notApplicable($rule);
        }

        $incident = (int) ($rule['incident_evaluation_if_failed'] ?? 0) === 1;
        $ruleKey = (string) $rule['rule_key'];
        $failed = null;
        $reason = '';

        switch ($ruleKey) {
            case 'p03_buyer_intent_trigger':
                // THE BUYER-INTENT INVARIANT.
                //
                // It fires ONLY on the queue path. A Watch-List entry ("there is a reason to look")
                // never asserts buyer intent, so refusing it here would be refusing a claim nobody
                // made. A QUEUE admission ("there is a timely reason to act") does assert
                // timeliness, and may do so only when its cited signal is a buying TRIGGER class
                // AND was directly OBSERVED.
                //
                // Both halves are required. A trigger class that was only HYPOTHESIZED is a guess
                // about a moment; an observed fact of the wrong class is a real event with no
                // bearing on buying. Neither supports "act now".
                $failed = (string) $ctx['proposal_type'] === 'PRIORITY_OUTREACH_QUEUE_ADMISSION'
                    && !(
                        in_array((string) $ctx['signal_class'], self::BUYER_INTENT_TRIGGER_CLASSES, true)
                        && (string) $ctx['interpretation_kind'] === 'FACT_OBSERVED'
                    );
                $reason = 'BUYER_INTENT_OVERCLAIM';
                break;

            case 'p03_icp_excluded_segment':
                // Tenant-declared segment CLASSES, never people. The match is whole-term over facts
                // the proposal already carries: absent facts mean no guessing and no fire.
                $failed = $ctx['icp_excluded_segment_match'] !== null;
                $reason = 'ICP_EXCLUDED_SEGMENT_MATCH';
                break;

            case 'p03_admission_fit_present':
                // The derivation already refuses UNFIT; the verdict re-checks the persisted band as
                // defence in depth against a row edited after derivation.
                $failed = (string) $ctx['fit_band'] === 'UNFIT';
                $reason = 'INSUFFICIENT_FIT_EVIDENCE';
                break;

            case 'p03_admission_catalogue_active':
                $failed = (string) $ctx['catalogue_status'] !== 'ACTIVE';
                $reason = 'STALE_CATALOGUE';
                break;

            case 'p03_admission_signal_released':
                $failed = (string) $ctx['signal_status'] !== 'RELEASED';
                $reason = 'SIGNAL_NO_LONGER_RELEASED';
                break;

            default:
                // An unknown rule key in a known family fails CLOSED. A rule the engine cannot
                // evaluate is not a rule that passed.
                return self::refuse(
                    $rule, ['ARBITER_P03_UNKNOWN_RULE', 'ARBITER_P03_PROSPECT_ADMISSION_DENIED'],
                    $failClosed, $incident
                );
        }

        if ($failed !== true) {
            return ['decision' => 'PASS', 'reason_codes' => [], 'severity' => null, 'incident' => false, 'result' => null];
        }

        return self::refuse(
            $rule, [$reason, 'ARBITER_P03_PROSPECT_ADMISSION_DENIED'], $failClosed, $incident
        );
    }

    /**
     * The P04 distribution context: the bound briefing, the role's configuration, and whether the
     * cited source is STILL released.
     *
     * The routing's own governance facts — sensitivity class and legal basis — are read out of the
     * SUBMITTED proposal's release scope, not from a caller argument. That JSON froze into the
     * submission digest, so the verdict judges exactly the facts the producer committed to and
     * cannot be handed different ones at evaluation time.
     *
     * @return array<string, mixed>|null null when the proposal binds no distribution briefing
     */
    public static function loadP04Context(Database $db, string $proposalId): ?array
    {
        $row = $db->one(
            'SELECT b.target_org_role, b.briefing_summary_safe, b.redaction_profile,
                    b.released_briefing_id, b.released_prospect_output_id,
                    b.tenant_id, b.workspace_id,
                    pe.requested_release_or_action_scope AS scope
             FROM role_distribution_briefings b
             JOIN proposal_envelopes pe ON pe.proposal_id = b.proposal_id
             WHERE b.proposal_id = ?
             ORDER BY b.created_at DESC, b.role_distribution_briefing_id DESC LIMIT 1',
            [$proposalId]
        );
        if ($row === null) {
            return null;
        }

        // Parsed defensively. A malformed or absent scope reads as ROUTINE with no legal basis,
        // which is the safe direction: the routing loses the SENSITIVE claim it can no longer
        // substantiate rather than keeping the claim and losing the checks that come with it.
        $row['sensitivity_class'] = 'ROUTINE';
        $row['legal_basis_recorded'] = false;
        if ($row['scope'] !== null) {
            $parsed = json_decode((string) $row['scope'], true);
            if (is_array($parsed)) {
                if (($parsed['sensitivityClass'] ?? null) === 'SENSITIVE') {
                    $row['sensitivity_class'] = 'SENSITIVE';
                }
                $row['legal_basis_recorded'] = is_string($parsed['legalBasisRef'] ?? null)
                    && $parsed['legalBasisRef'] !== '';
            }
        }

        $configured = $db->one(
            'SELECT 1 AS hit FROM console_redaction_policy_projections
             WHERE tenant_id = ? AND workspace_id = ? AND target_org_role = ? LIMIT 1',
            [(string) $row['tenant_id'], (string) $row['workspace_id'], (string) $row['target_org_role']]
        );
        $row['target_configured'] = $configured !== null;
        $row['redaction_profile_empty'] = self::redactionProfileIsEmpty((string) $row['redaction_profile']);
        $row['source_reliance'] = self::sourceRelianceForBriefing(
            $db,
            $row['released_briefing_id'] === null ? null : (string) $row['released_briefing_id'],
            $row['released_prospect_output_id'] === null ? null : (string) $row['released_prospect_output_id'],
        );

        return $row;
    }

    /**
     * The current reliance of the M10 record behind a briefing's cited source.
     *
     * A LATER supersession wins over the record's own status: a record can be superseded without
     * its own row changing, and reading only that row would report a withdrawn release as live.
     */
    private static function sourceRelianceForBriefing(
        Database $db,
        ?string $releasedBriefingId,
        ?string $releasedProspectOutputId,
    ): ?string {
        $row = $releasedBriefingId !== null
            ? $db->one(
                'SELECT decision_record_id FROM released_briefings WHERE released_briefing_id = ?',
                [$releasedBriefingId]
            )
            : ($releasedProspectOutputId !== null
                ? $db->one(
                    'SELECT decision_record_id FROM released_prospect_outputs WHERE released_prospect_output_id = ?',
                    [$releasedProspectOutputId]
                )
                : null);
        if ($row === null) {
            return null;
        }

        $superseded = $db->one(
            'SELECT s.new_current_reliance_status AS reliance
             FROM decision_supersession_records s
             JOIN decision_records dr ON dr.decision_record_id = s.successor_decision_record_id
             WHERE s.original_decision_record_id = ?
             ORDER BY dr.record_sequence_number DESC LIMIT 1',
            [(string) $row['decision_record_id']]
        );
        if ($superseded !== null) {
            return (string) $superseded['reliance'];
        }

        $record = $db->one(
            'SELECT current_reliance_status FROM decision_records WHERE decision_record_id = ?',
            [(string) $row['decision_record_id']]
        );

        return $record === null ? null : (string) $record['current_reliance_status'];
    }

    /**
     * Is a redaction profile empty?
     *
     * Unparseable counts as empty. A profile the engine cannot read is a profile that redacts
     * nothing it can prove, and the sensitive path must not clear on one.
     */
    private static function redactionProfileIsEmpty(string $redactionProfile): bool
    {
        $parsed = json_decode($redactionProfile, true);
        if (!is_array($parsed)) {
            return true;
        }

        return $parsed === [];
    }

    /**
     * The P04_DISTRIBUTION_ADMISSION checks.
     *
     * A missing distribution context makes them NOT_APPLICABLE — they govern intelligence-routing
     * proposals only.
     *
     * @param array<string, mixed>|null $ctx
     * @return array{decision: string, reason_codes: list<string>, severity: ?string, incident: bool, result: ?array<string, mixed>}
     */
    /**
     * The P02 twin-state context: the proposed version, its twin, its uncertainty summary, and the
     * source card behind it.
     *
     * The state fields the prohibition rules read are the twin's own descriptive columns plus the
     * uncertainty prose — the places a person, a score or an intent claim can actually be written.
     *
     * @return array<string, mixed>|null null when the proposal binds no twin state change
     */
    public static function loadP02Context(Database $db, string $proposalId): ?array
    {
        $row = $db->one(
            'SELECT p.entity_twin_version_id, p.asserted_relationship_version_id,
                    p.requested_visibility_scope, p.released_entity_twin_snapshot_id,
                    p.supersedes_release_id,
                    v.claim_ceiling, v.entity_twin_id,
                    t.is_first_party, t.is_external, t.display_name, t.legal_name,
                    t.industry, t.segment, t.jurisdiction,
                    u.summary_safe AS uncertainty_summary_safe,
                    u.has_conflict, u.conflict_summary_safe
             FROM entity_twin_state_change_proposals p
             JOIN entity_twin_versions v ON v.entity_twin_version_id = p.entity_twin_version_id
             JOIN entity_twins t ON t.entity_twin_id = v.entity_twin_id
             LEFT JOIN entity_twin_uncertainty_summaries u
                    ON u.entity_twin_version_id = v.entity_twin_version_id
             WHERE p.proposal_id = ?
             ORDER BY p.created_at DESC, p.entity_twin_state_change_proposal_id DESC LIMIT 1',
            [$proposalId]
        );
        if ($row === null) {
            return null;
        }

        // The evidence behind this version. A version with no source link asserts a state nobody
        // recorded a basis for, which is what `p02_unsupported_twin_claim` exists to catch.
        $row['source_link_count'] = (int) $db->scalar(
            'SELECT COUNT(*) FROM entity_twin_source_links WHERE entity_twin_version_id = ?',
            [(string) $row['entity_twin_version_id']]
        );

        // The card behind the most recent source link, for the licence and freshness rules.
        $card = $db->one(
            'SELECT slc.review_status, slc.review_expires_at, slc.license_class,
                    slc.retention_class, sr.status AS reference_status
             FROM entity_twin_source_links l
             JOIN source_references sr ON sr.source_ref_id = l.source_ref_id
             LEFT JOIN source_license_cards slc ON slc.source_ref_id = sr.source_ref_id
             WHERE l.entity_twin_version_id = ?
             ORDER BY l.created_at DESC, l.entity_twin_source_link_id DESC LIMIT 1',
            [(string) $row['entity_twin_version_id']]
        );
        $row['card'] = $card;

        // Every free-text field a prohibited claim could be written into, concatenated once so the
        // five prohibition rules each scan the same surface rather than each choosing their own.
        $row['state_text'] = implode(' ', array_filter([
            (string) ($row['display_name'] ?? ''),
            (string) ($row['legal_name'] ?? ''),
            (string) ($row['industry'] ?? ''),
            (string) ($row['segment'] ?? ''),
            (string) ($row['uncertainty_summary_safe'] ?? ''),
            (string) ($row['conflict_summary_safe'] ?? ''),
        ]));

        // An asserted relationship edge, with the two things the rule requires of one: a declared
        // confidence and at least one cited basis. Evidence lives in its own link table rather than
        // on the version, so the count comes from there — a version row alone proves nothing.
        if ($row['asserted_relationship_version_id'] !== null) {
            $relationship = $db->one(
                'SELECT confidence FROM entity_relationship_versions WHERE relationship_version_id = ?',
                [(string) $row['asserted_relationship_version_id']]
            );
            if ($relationship !== null) {
                $relationship['evidence_link_count'] = (int) $db->scalar(
                    'SELECT COUNT(*) FROM entity_relationship_evidence_links
                     WHERE relationship_version_id = ?',
                    [(string) $row['asserted_relationship_version_id']]
                );
            }
            $row['relationship'] = $relationship;
        }

        return $row;
    }

    /**
     * The P02 twin-state rules.
     *
     * The five prohibition rules are first in priority and each opens an incident: a twin state
     * that reached the arbiter carrying a person, an employee score, private external data, a
     * first-party leak or a direct mutation of a released snapshot is a defect upstream, not a
     * draft to revise. The rest grade from BLOCK through REQUIRE_MORE_EVIDENCE to HOLD, the
     * difference being whether the state is WRONG or merely UNFINISHED.
     */
    private static function evalP02(array $rule, ?array $ctx, string $failClosed): array
    {
        if ($ctx === null) {
            return self::notApplicable($rule);
        }

        $incident = (int) ($rule['incident_evaluation_if_failed'] ?? 0) === 1;
        $ruleKey = (string) $rule['rule_key'];
        $card = $ctx['card'] ?? null;
        $text = (string) $ctx['state_text'];
        $failed = null;
        $reason = '';

        switch ($ruleKey) {
            case 'p02_person_profile_overreach':
                // Two ways this fails, and both matter: a structured individual identifier, or the
                // vocabulary of profiling. The shared detector is the same one P04 and P07-A use,
                // deliberately — a gate and the audit that checks it must not disagree about what
                // PII is.
                $failed = PrivacyPatterns::containsIndividualIdentifier($text)
                    || self::mentionsAny($text, self::P02_PROFILING_TERMS);
                $reason = 'PERSON_PROFILE_OVERREACH';
                break;

            case 'p02_employee_scoring_or_surveillance':
                $failed = self::mentionsAny($text, self::P02_EMPLOYEE_SCORING_TERMS);
                $reason = 'EMPLOYEE_SCORING_OR_SURVEILLANCE';
                break;

            case 'p02_external_entity_private_data':
                // An EXTERNAL twin describes somebody else's company. Whatever it says must have
                // come from a public source, so a non-public reference is the failure.
                $failed = (int) $ctx['is_external'] === 1
                    && $card !== null
                    && in_array((string) ($card['license_class'] ?? ''), self::NON_PUBLIC_LICENSE_CLASSES, true);
                $reason = 'EXTERNAL_ENTITY_PRIVATE_DATA';
                break;

            case 'p02_first_party_external_leakage':
                // A twin cannot be both. A row claiming first-party AND external is the shape a
                // leak takes: our own material carried into somebody else's record.
                $failed = (int) $ctx['is_external'] === 1 && (int) $ctx['is_first_party'] === 1;
                $reason = 'FIRST_PARTY_EXTERNAL_LEAKAGE';
                break;

            case 'p02_direct_mutation_of_released_snapshot':
                // A released snapshot is immutable. A change that names one must SUPERSEDE it; a
                // change that names one without superseding is an edit.
                $failed = $ctx['released_entity_twin_snapshot_id'] !== null
                    && $ctx['supersedes_release_id'] === null;
                $reason = 'DIRECT_MUTATION_OF_RELEASED_SNAPSHOT';
                break;

            case 'p02_unsupported_twin_claim':
                $failed = (int) $ctx['source_link_count'] === 0;
                $reason = 'UNSUPPORTED_TWIN_CLAIM';
                break;

            case 'p02_buyer_intent_overclaim':
                // The same invariant P03 holds at its own gate, held again here: a twin may
                // describe what was observed. It may not assert that a company wants to buy.
                $failed = self::mentionsAny($text, self::P02_BUYER_INTENT_TERMS);
                $reason = 'BUYER_INTENT_OVERCLAIM';
                break;

            case 'p02_relationship_edge_unsupported':
                $relationship = $ctx['relationship'] ?? null;
                $failed = $ctx['asserted_relationship_version_id'] !== null
                    && ($relationship === null
                        || (int) ($relationship['evidence_link_count'] ?? 0) === 0
                        || ($relationship['confidence'] ?? null) === null);
                $reason = 'RELATIONSHIP_EDGE_UNSUPPORTED';
                break;

            case 'p02_source_not_approved':
                $failed = $card !== null && (string) ($card['review_status'] ?? '') !== 'approved';
                $reason = 'SOURCE_NOT_APPROVED';
                break;

            case 'p02_license_restricts_use':
                $failed = $card !== null
                    && (string) ($card['license_class'] ?? '') === 'PROHIBITED';
                $reason = 'LICENSE_RESTRICTS_USE';
                break;

            case 'p02_uncertainty_missing':
                $failed = ($ctx['uncertainty_summary_safe'] ?? null) === null
                    || trim((string) $ctx['uncertainty_summary_safe']) === '';
                $reason = 'UNCERTAINTY_MISSING';
                break;

            case 'p02_conflicting_evidence_unresolved':
                $failed = (int) ($ctx['has_conflict'] ?? 0) === 1;
                $reason = 'CONFLICTING_EVIDENCE_UNRESOLVED';
                break;

            case 'p02_stale_or_superseded_source':
                $failed = $card !== null && (
                    !in_array((string) ($card['reference_status'] ?? ''), ['ACTIVE_FOR_CAPTURE', 'REGISTERED'], true)
                    || (($card['review_expires_at'] ?? null) !== null
                        && (string) $card['review_expires_at'] < (new Clock())->now())
                );
                $reason = 'STALE_OR_SUPERSEDED_SOURCE';
                break;

            case 'p02_retention_policy_missing':
                $failed = $card !== null && ($card['retention_class'] ?? null) === null;
                $reason = 'RETENTION_POLICY_MISSING';
                break;

            case 'p02_release_ceiling_missing':
                $failed = ($ctx['claim_ceiling'] ?? null) === null
                    || trim((string) $ctx['claim_ceiling']) === '';
                $reason = 'RELEASE_CEILING_MISSING';
                break;

            default:
                return self::refuse(
                    $rule, ['ARBITER_P02_UNKNOWN_RULE', 'ARBITER_P02_TWIN_STATE_ADMISSION_DENIED'],
                    $failClosed, $incident
                );
        }

        if ($failed !== true) {
            return ['decision' => 'PASS', 'reason_codes' => [], 'severity' => null, 'incident' => false, 'result' => null];
        }

        return self::refuse($rule, [$reason, 'ARBITER_P02_TWIN_STATE_ADMISSION_DENIED'], $failClosed, $incident);
    }

    /** Case-insensitive whole-phrase presence, for the prohibition vocabularies. */
    private static function mentionsAny(string $haystack, array $needles): bool
    {
        $lower = strtolower($haystack);
        foreach ($needles as $needle) {
            if (str_contains($lower, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private static function evalP04(array $rule, ?array $ctx, string $failClosed): array
    {
        if ($ctx === null) {
            return self::notApplicable($rule);
        }

        $incident = (int) ($rule['incident_evaluation_if_failed'] ?? 0) === 1;
        $ruleKey = (string) $rule['rule_key'];
        $failed = null;
        $reason = '';

        switch ($ruleKey) {
            case 'p04_individual_identifier_forbidden':
                // THE PRIVACY INVARIANT, at the admission rather than only at the storage pin. The
                // pins stop a column from holding a person; this stops the free-text summary from
                // carrying one past them. It BLOCKs and opens an incident: a briefing that reached
                // the arbiter with an identifier in it is a defect upstream, not a draft to revise.
                $failed = PrivacyPatterns::containsIndividualIdentifier((string) $ctx['briefing_summary_safe']);
                $reason = 'INDIVIDUAL_IDENTIFIER_FORBIDDEN';
                break;

            case 'p04_unauthorized_role':
                // A role becomes a distribution target only once an admin configured its redaction
                // policy. An unconfigured role is not a role with an empty policy — it is a
                // recipient nobody authorized.
                $failed = $ctx['target_configured'] !== true;
                $reason = 'UNAUTHORIZED_ROLE';
                break;

            case 'p04_no_legal_basis_for_sensitive':
                $failed = (string) $ctx['sensitivity_class'] === 'SENSITIVE'
                    && $ctx['legal_basis_recorded'] !== true;
                $reason = 'NO_LEGAL_BASIS_FOR_SENSITIVE';
                break;

            case 'p04_redaction_required':
                $failed = (string) $ctx['sensitivity_class'] === 'SENSITIVE'
                    && $ctx['redaction_profile_empty'] === true;
                $reason = 'REDACTION_REQUIRED';
                break;

            case 'p04_source_not_released':
                // Re-checked at VERDICT time, not just at composition: a source can be superseded
                // or withdrawn in between, and routing it then would distribute a claim the ledger
                // no longer stands behind. A null reliance — no resolvable record — fails too.
                $failed = $ctx['source_reliance'] === null
                    || (string) $ctx['source_reliance'] !== 'ACTIVE_AS_RECORDED';
                $reason = 'SOURCE_NOT_RELEASED';
                break;

            default:
                return self::refuse(
                    $rule, ['ARBITER_P04_UNKNOWN_RULE', 'ARBITER_P04_DISTRIBUTION_ADMISSION_DENIED'],
                    $failClosed, $incident
                );
        }

        if ($failed !== true) {
            return ['decision' => 'PASS', 'reason_codes' => [], 'severity' => null, 'incident' => false, 'result' => null];
        }

        return self::refuse(
            $rule, [$reason, 'ARBITER_P04_DISTRIBUTION_ADMISSION_DENIED'], $failClosed, $incident
        );
    }

    /**
     * The P05 marketing context: the bound draft, its comparative citations, and its bases.
     *
     * `comparative_citation_count` counts citations whose CLAIM TEXT is itself comparative, not
     * citations in general. A draft comparing itself to a competitor and then citing evidence for
     * an unrelated claim has cited nothing for the claim that needed it.
     *
     * @return array<string, mixed>|null null when the proposal binds no marketing draft
     */
    public static function loadP05Context(Database $db, string $proposalId): ?array
    {
        $row = $db->one(
            'SELECT marketing_asset_draft_id, draft_content_safe, basis_evidence_id, asset_status,
                    tenant_id, workspace_id, released_briefing_id, released_prospect_output_id
             FROM marketing_asset_drafts WHERE proposal_id = ?
             ORDER BY created_at DESC, marketing_asset_draft_id DESC LIMIT 1',
            [$proposalId]
        );
        if ($row === null) {
            return null;
        }

        $comparative = 0;
        $citations = $db->all(
            'SELECT claim_text_safe FROM marketing_claim_citations WHERE marketing_asset_draft_id = ?',
            [(string) $row['marketing_asset_draft_id']]
        );
        foreach ($citations as $citation) {
            if (MarketingPatterns::hasComparativeClaim((string) $citation['claim_text_safe'])) {
                $comparative++;
            }
        }
        $row['comparative_citation_count'] = $comparative;

        // The basis must exist AND be in the draft's own scope. A basis id pointing at another
        // workspace's evidence is not a weaker basis; it is a citation of something this draft
        // cannot see.
        $row['basis_evidence_in_scope'] = $row['basis_evidence_id'] !== null
            && $db->one(
                'SELECT 1 AS hit FROM evidence_objects
                 WHERE evidence_id = ? AND tenant_id = ? AND workspace_id = ?',
                [
                    (string) $row['basis_evidence_id'],
                    (string) $row['tenant_id'],
                    (string) $row['workspace_id'],
                ]
            ) !== null;

        $row['source_reliance'] = self::sourceRelianceForBriefing(
            $db,
            $row['released_briefing_id'] === null ? null : (string) $row['released_briefing_id'],
            $row['released_prospect_output_id'] === null ? null : (string) $row['released_prospect_output_id'],
        );

        return $row;
    }

    /**
     * The P05_MARKETING_ASSET_ADMISSION checks.
     *
     * A missing context FAILS CLOSED when the proposal IS a marketing draft, rather than standing
     * aside. This family is stricter than the others on purpose: a marketing proposal with no
     * draft bound to it is not a proposal these rules have nothing to say about — it is one whose
     * content nobody can see, and content is the entire question here.
     *
     * @param array<string, mixed>|null $ctx
     * @return array{decision: string, reason_codes: list<string>, severity: ?string, incident: bool, result: ?array<string, mixed>}
     */
    private static function evalP05(array $rule, ?array $ctx, string $proposalType, string $failClosed): array
    {
        $incident = (int) ($rule['incident_evaluation_if_failed'] ?? 0) === 1;

        if ($ctx === null) {
            if ($proposalType !== 'MARKETING_ASSET_DRAFT') {
                return self::notApplicable($rule);
            }

            return self::refuse(
                $rule,
                ['P05_MARKETING_ASSET_CONTEXT_MISSING', 'ARBITER_P05_MARKETING_ASSET_DENIED'],
                $failClosed,
                $incident,
                useFailClosedEffect: true,
            );
        }

        $ruleKey = (string) $rule['rule_key'];
        $failed = null;
        $reason = '';

        switch ($ruleKey) {
            case 'p05_uncited_competitor_claim':
                // BOTH halves. Copy that makes no comparative claim needs no comparative citation,
                // and copy that makes one needs at least one citation whose own text is comparative.
                $failed = MarketingPatterns::hasComparativeClaim((string) $ctx['draft_content_safe'])
                    && (int) $ctx['comparative_citation_count'] === 0;
                $reason = 'P05_UNCITED_COMPETITOR_CLAIM';
                break;

            case 'p05_forbidden_marketing_pattern':
                $failed = MarketingPatterns::hasForbiddenPattern((string) $ctx['draft_content_safe']);
                $reason = 'P05_FORBIDDEN_MARKETING_PATTERN';
                break;

            case 'p05_source_still_released':
                // A null reliance — no resolvable record — fails too. Unknown is not permitted.
                $failed = $ctx['source_reliance'] === null
                    || (string) $ctx['source_reliance'] !== 'ACTIVE_AS_RECORDED';
                $reason = 'SOURCE_NOT_RELEASED';
                break;

            case 'p05_draft_only':
                // IN_REVIEW, not DRAFT: a verdict is issued about an asset that was submitted for
                // one, and an asset still open for edits is not that.
                $failed = (string) $ctx['asset_status'] !== 'IN_REVIEW';
                $reason = 'P05_DRAFT_ONLY';
                break;

            case 'p05_evidence_basis_present':
                $failed = $ctx['basis_evidence_id'] === null || $ctx['basis_evidence_in_scope'] !== true;
                $reason = 'P05_EVIDENCE_BASIS_MISSING';
                break;

            default:
                return self::refuse(
                    $rule, ['ARBITER_P05_UNKNOWN_RULE', 'ARBITER_P05_MARKETING_ASSET_DENIED'],
                    $failClosed, $incident
                );
        }

        if ($failed !== true) {
            return ['decision' => 'PASS', 'reason_codes' => [], 'severity' => null, 'incident' => false, 'result' => null];
        }

        return self::refuse(
            $rule, [$reason, 'ARBITER_P05_MARKETING_ASSET_DENIED'], $failClosed, $incident
        );
    }

    /**
     * The channel cooldown window: one day.
     *
     * A rate limit expressed as a floor on time between contacts to the same person on the same
     * channel. It is not a spam heuristic — it is the difference between outreach and pestering,
     * and it is measured per RECIPIENT rather than per campaign so no amount of re-framing gets
     * around it.
     */
    public const P07_COOLDOWN_SECONDS = 86400;

    /**
     * The P07 outreach context: the bound outreach row, its re-derived consent, and its cooldown.
     *
     * CONSENT IS RE-DERIVED HERE, not read from the proposal. The producer recorded which consent
     * entry it relied on; this recomputes the EFFECTIVE consent from the registry by the same
     * recency rule the consent module uses. A withdrawal recorded between the submission and the
     * verdict therefore blocks, which is the entire point of re-deriving rather than trusting.
     *
     * @return array<string, mixed>|null null when the proposal binds no outreach row
     */
    public static function loadP07Context(Database $db, string $proposalId): ?array
    {
        $row = $db->one(
            'SELECT outreach_execution_proposal_id, tenant_id, workspace_id, recipient_pseudonym_ref,
                    channel, content_to_scan_safe, source_decision_record_id, created_at
             FROM outreach_execution_proposals WHERE proposal_id = ?
             ORDER BY created_at DESC, outreach_execution_proposal_id DESC LIMIT 1',
            [$proposalId]
        );
        if ($row === null) {
            return null;
        }

        $consent = $db->one(
            'SELECT consent_state, lawful_basis FROM consent_registry
             WHERE tenant_id = ? AND workspace_id = ? AND recipient_pseudonym_ref = ? AND channel = ?
             ORDER BY recorded_at DESC, consent_registry_entry_id DESC LIMIT 1',
            [
                (string) $row['tenant_id'],
                (string) $row['workspace_id'],
                (string) $row['recipient_pseudonym_ref'],
                (string) $row['channel'],
            ]
        );
        // Default DENY, and the reason defaults to the weakest claim the evidence supports. An
        // absent registry row is not "unknown consent" — it is no lawful basis.
        $row['consent_permitted'] = false;
        $row['consent_reason'] = 'NO_LAWFUL_BASIS';
        if ($consent !== null) {
            $state = (string) $consent['consent_state'];
            $basis = (string) $consent['lawful_basis'];
            if ($state === 'WITHDRAWN') {
                $row['consent_reason'] = 'CONSENT_WITHDRAWN';
            } elseif ($state === 'OPTED_OUT') {
                $row['consent_reason'] = 'CONSENT_OPTED_OUT';
            } elseif ($state === 'NO_BASIS' || $basis === 'NONE') {
                $row['consent_reason'] = 'NO_LAWFUL_BASIS';
            } elseif ($state === 'OPTED_IN') {
                $row['consent_permitted'] = true;
            }
        }

        // A prior NON-BLOCKED outreach to the same recipient and channel inside the window. Blocked
        // proposals are excluded because an outreach that was refused never reached anybody, and
        // counting it would penalize the operator for the system's own refusal.
        $cutoff = gmdate(
            'Y-m-d\TH:i:s.v\Z',
            strtotime((string) $row['created_at']) - self::P07_COOLDOWN_SECONDS
        );
        $prior = $db->one(
            "SELECT 1 AS hit FROM outreach_execution_proposals
             WHERE tenant_id = ? AND workspace_id = ? AND recipient_pseudonym_ref = ? AND channel = ?
               AND outreach_execution_proposal_id <> ? AND proposal_status <> 'BLOCKED'
               AND created_at >= ?
             LIMIT 1",
            [
                (string) $row['tenant_id'],
                (string) $row['workspace_id'],
                (string) $row['recipient_pseudonym_ref'],
                (string) $row['channel'],
                (string) $row['outreach_execution_proposal_id'],
                $cutoff,
            ]
        );
        $row['cooldown_active'] = $prior !== null;

        $row['source_reliance'] = self::decisionRecordReliance(
            $db,
            (string) $row['source_decision_record_id']
        );

        return $row;
    }

    /**
     * A decision record's current reliance: the latest supersession wins, else the record's status.
     */
    private static function decisionRecordReliance(Database $db, string $decisionRecordId): ?string
    {
        $superseded = $db->one(
            'SELECT s.new_current_reliance_status AS reliance
             FROM decision_supersession_records s
             JOIN decision_records dr ON dr.decision_record_id = s.successor_decision_record_id
             WHERE s.original_decision_record_id = ?
             ORDER BY dr.record_sequence_number DESC LIMIT 1',
            [$decisionRecordId]
        );
        if ($superseded !== null) {
            return (string) $superseded['reliance'];
        }

        $record = $db->one(
            'SELECT current_reliance_status FROM decision_records WHERE decision_record_id = ?',
            [$decisionRecordId]
        );

        return $record === null ? null : (string) $record['current_reliance_status'];
    }

    /**
     * The P07_OUTREACH_EXECUTION_ADMISSION checks.
     *
     * @param array<string, mixed>|null $ctx
     * @return array{decision: string, reason_codes: list<string>, severity: ?string, incident: bool, result: ?array<string, mixed>}
     */
    private static function evalP07(array $rule, ?array $ctx, string $failClosed): array
    {
        if ($ctx === null) {
            return self::notApplicable($rule);
        }

        $incident = (int) ($rule['incident_evaluation_if_failed'] ?? 0) === 1;
        $ruleKey = (string) $rule['rule_key'];
        $failed = null;
        $reason = '';

        switch ($ruleKey) {
            case 'p07_consent_lawful_basis':
                // THE CONSENT INVARIANT, re-derived rather than trusted. The reason code comes from
                // the context, so the verdict names WHICH act the recipient performed — a
                // withdrawal and an opt-out are different facts about a person.
                $failed = $ctx['consent_permitted'] !== true;
                $reason = (string) $ctx['consent_reason'];
                break;

            case 'p07_pii_not_pseudonymized':
                // Defence in depth over the firewall. Content carrying a raw identifier here means
                // it got past the one function that was supposed to see one.
                $failed = PiiFirewall::containsRawPii((string) $ctx['content_to_scan_safe']);
                $reason = 'PII_NOT_PSEUDONYMIZED';
                break;

            case 'p07_content_policy':
                $failed = ContentPolicy::hasProhibitedContent((string) $ctx['content_to_scan_safe']);
                $reason = 'CONTENT_POLICY_VIOLATION';
                break;

            case 'p07_channel_cooldown':
                $failed = $ctx['cooldown_active'] === true;
                $reason = 'CHANNEL_COOLDOWN_ACTIVE';
                break;

            case 'p07_source_not_released':
                $failed = $ctx['source_reliance'] === null
                    || (string) $ctx['source_reliance'] !== 'ACTIVE_AS_RECORDED';
                $reason = 'SOURCE_NOT_RELEASED';
                break;

            default:
                return self::refuse(
                    $rule, ['ARBITER_P07_UNKNOWN_RULE', 'ARBITER_P07_OUTREACH_EXECUTION_DENIED'],
                    $failClosed, $incident
                );
        }

        if ($failed !== true) {
            return ['decision' => 'PASS', 'reason_codes' => [], 'severity' => null, 'incident' => false, 'result' => null];
        }

        return self::refuse(
            $rule, [$reason, 'ARBITER_P07_OUTREACH_EXECUTION_DENIED'], $failClosed, $incident
        );
    }

    /**
     * Evaluate one ordinary rule.
     *
     * @param array<string, mixed> $rule    an `arbiter_policy_rules` row
     * @param array<string, mixed> $context the loaded family contexts
     * @return array{decision: string, reason_codes: list<string>, severity: ?string, incident: bool, result: ?array<string, mixed>}
     */
    public static function evaluate(array $rule, array $context, string $proposalType, string $failClosed): array
    {
        $category = (string) $rule['rule_category'];

        return match ($category) {
            'P01_RELEVANCE' => self::evalP01($rule, $context['P01_RELEVANCE'] ?? null, $failClosed),
            'P03_PROSPECT_ADMISSION' => self::evalP03($rule, $context['P03_PROSPECT_ADMISSION'] ?? null, $failClosed),
            'P02_TWIN_STATE_ADMISSION' => self::evalP02($rule, $context['P02_TWIN_STATE_ADMISSION'] ?? null, $failClosed),
            'P04_DISTRIBUTION_ADMISSION' => self::evalP04($rule, $context['P04_DISTRIBUTION_ADMISSION'] ?? null, $failClosed),
            'P05_MARKETING_ASSET_ADMISSION' => self::evalP05(
                $rule, $context['P05_MARKETING_ASSET_ADMISSION'] ?? null, $proposalType, $failClosed
            ),
            'P07_OUTREACH_EXECUTION_ADMISSION' => self::evalP07(
                $rule, $context['P07_OUTREACH_EXECUTION_ADMISSION'] ?? null, $failClosed
            ),
            // A category this engine does not implement stands aside rather than passing: it has no
            // context to judge, and inventing an answer would be worse than declining to give one.
            default => self::notApplicable($rule),
        };
    }

    /**
     * The four P01_RELEVANCE checks.
     *
     * A missing signal context makes them NOT_APPLICABLE — they govern competition signals only.
     * On a failure the verdict is the RULE's declared `failure_effect` (coerced to the policy
     * default when unrecognized), and the reason is the specific P01 reason plus the family code,
     * so the transcript names both what failed and which family refused.
     *
     * @param array<string, mixed>|null $ctx
     * @return array{decision: string, reason_codes: list<string>, severity: ?string, incident: bool, result: ?array<string, mixed>}
     */
    private static function evalP01(array $rule, ?array $ctx, string $failClosed): array
    {
        if ($ctx === null) {
            return self::notApplicable($rule);
        }

        $incident = (int) ($rule['incident_evaluation_if_failed'] ?? 0) === 1;
        $ruleKey = (string) $rule['rule_key'];

        $failed = null;
        $reason = '';

        switch ($ruleKey) {
            case 'p01_relevance_customer_interest':
                // The signal must concern a competitor within the customer's competitive interest.
                $failed = in_array((string) $ctx['competitor_class'], self::P01_OUTSIDE_INTEREST_CLASSES, true);
                $reason = 'OUTSIDE_CUSTOMER_INTEREST';
                break;

            case 'p01_relevance_source_authority':
                // The backing source card must permit customer-visible intelligence output. Either
                // condition failing is enough — a card that allows output but carries a
                // no-customer-output licence is still not authority for one.
                $licenseClass = $ctx['license_class'] === null ? null : (string) $ctx['license_class'];
                $failed = (int) $ctx['customer_output_allowed'] !== 1
                    || ($licenseClass !== null && in_array($licenseClass, self::P01_LOW_AUTHORITY_LICENSES, true));
                $reason = 'SOURCE_AUTHORITY_TOO_LOW';
                break;

            case 'p01_relevance_insufficient_evidence':
                // A binding release needs a CLASSIFIED structural change. An unclassified diff
                // (UNKNOWN) is not evidence of anything in particular.
                $failed = $ctx['source_diff_id'] === null
                    || (string) ($ctx['change_significance'] ?? 'UNKNOWN') === 'UNKNOWN';
                $reason = 'INSUFFICIENT_EVIDENCE';
                break;

            case 'p01_relevance_stale_input':
                // The source-card review must be current. Both an explicit `expired` status and a
                // passed expiry timestamp count — a card whose window lapsed without anyone
                // re-classifying it is just as stale as one marked so.
                $expiresAt = $ctx['review_expires_at'] === null ? null : (string) $ctx['review_expires_at'];
                $failed = (string) ($ctx['review_status'] ?? '') === 'expired'
                    || ($expiresAt !== null && $expiresAt < (new Clock())->now());
                $reason = 'STALE_INPUT';
                break;

            default:
                // An unknown P01 rule key fails closed to the policy default rather than silently
                // passing: a policy must not be able to grant permission by misspelling a rule.
                $codes = ['ARBITER_P01_UNKNOWN_RULE', 'ARBITER_P01_RELEVANCE_DENIED'];

                return [
                    'decision' => 'FAIL',
                    'reason_codes' => $codes,
                    'severity' => 'S2',
                    'incident' => $incident,
                    'result' => Verdicts::failureEffectToResult($failClosed, $codes, $failClosed)
                        + ['incident_evaluation_required' => $incident],
                ];
        }

        if (!$failed) {
            return ['decision' => 'PASS', 'reason_codes' => [], 'severity' => null, 'incident' => false, 'result' => null];
        }

        $codes = [$reason, 'ARBITER_P01_RELEVANCE_DENIED'];
        $result = Verdicts::failureEffectToResult((string) $rule['failure_effect'], $codes, $failClosed);
        $result['severity'] = 'S2';
        $result['incident_evaluation_required'] = $incident;

        return [
            'decision' => 'FAIL',
            'reason_codes' => $codes,
            'severity' => 'S2',
            'incident' => $incident,
            'result' => $result,
        ];
    }

    /**
     * A rule refusal, carrying the rule's OWN declared failure effect.
     *
     * The effect comes from the rule, not from the family: `p03_buyer_intent_trigger` BLOCKs while
     * `p03_admission_catalogue_active` HOLDs, and flattening them to one verdict would erase the
     * difference between "this claim was never available" and "this needs revisiting".
     *
     * @param array<string, mixed> $rule
     * @param list<string> $codes
     * @return array{decision: string, reason_codes: list<string>, severity: string, incident: bool, result: array<string, mixed>}
     */
    private static function refuse(
        array $rule,
        array $codes,
        string $failClosed,
        bool $incident,
        bool $useFailClosedEffect = false,
    ): array {
        $effect = $useFailClosedEffect || str_ends_with($codes[0], '_UNKNOWN_RULE')
            // An unknown rule — or a family whose context could not be loaded at all — has no
            // declared effect to honour, so it takes the policy's own fail-closed verdict rather
            // than a guessed one.
            ? $failClosed
            : (string) $rule['failure_effect'];
        $result = Verdicts::failureEffectToResult($effect, $codes, $failClosed);
        $severity = $incident ? 'S4' : 'S2';
        $result['severity'] = $severity;
        $result['incident_evaluation_required'] = $incident;

        return [
            'decision' => 'FAIL',
            'reason_codes' => $codes,
            'severity' => $severity,
            'incident' => $incident,
            'result' => $result,
        ];
    }

    /** @return array{decision: string, reason_codes: list<string>, severity: null, incident: false, result: null} */
    private static function notApplicable(array $rule): array
    {
        return ['decision' => 'NOT_APPLICABLE', 'reason_codes' => [], 'severity' => null, 'incident' => false, 'result' => null];
    }
}
