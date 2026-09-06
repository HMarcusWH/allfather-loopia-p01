<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M08;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;

/**
 * M08 policy-set definitions and seeding.
 *
 * Ported from the `seed*PolicySet` functions in
 * `packages/allfather-control-substrate/src/m08/arbiter.ts`
 * @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d.
 *
 * Two things about these definitions are easy to misread:
 *
 *  1. **The three seed rules are declared for the RECORD, not for the engine.** `v1_scope_prohibition`,
 *     `m05_evidence_readiness` and `m07_hmwh_required` appear in each set so an auditor reading the
 *     policy can see them — but the engine enforces them as non-overridable seeds regardless of
 *     whether they are present, and evaluates them BEFORE any ordinary rule. Deleting them from a
 *     policy set does not disable them.
 *  2. **`default_fail_closed_verdict` is the answer when a rule fails without a usable effect.** It
 *     is never ALLOW.
 *
 * Deactivating a policy set is a stand-down lever: with it inactive, nothing routed through it can
 * be admitted at all.
 */
final class PolicySets
{
    /**
     * The three non-overridable guarantees, declared in every set for the record.
     *
     * @return list<array<string, mixed>>
     */
    private const SEED_RULES = [
        [
            'rule_key' => 'v1_scope_prohibition',
            'rule_category' => 'V1_SCOPE',
            'rule_summary' => 'Prohibited V1 action classes (external/consequential) are never admissible.',
            'check_type' => 'PROHIBITION_CHECK',
            'failure_effect' => 'BLOCK',
            'priority' => 100,
        ],
        [
            'rule_key' => 'm05_evidence_readiness',
            'rule_category' => 'M05_EVIDENCE',
            'rule_summary' => 'Evidence readiness must be SATISFIED_FOR_EVALUATION for a release route.',
            'check_type' => 'ENUM_STATUS',
            'failure_effect' => 'REQUIRE_MORE_EVIDENCE',
            'priority' => 80,
        ],
        [
            'rule_key' => 'm07_hmwh_required',
            'rule_category' => 'M07_HMWH',
            'rule_summary' => 'A valid HMWH result must exist when the review path requires it.',
            'check_type' => 'REQUIRED_REFERENCE',
            'failure_effect' => 'HOLD',
            'priority' => 60,
        ],
    ];

    /** The P01 competition-relevance policy set. */
    public const P01_COMPETITION_RELEVANCE = [
        'policy_key' => 'P01_COMPETITION_RELEVANCE',
        'policy_version' => 'v1',
        'display_name' => 'P01 competition relevance policy',
        'purpose_summary' => 'P01 competition-monitoring relevance/admissibility for COMPANY_SIGNAL_BRIEF / BATTLECARD_BRIEF.',
        'applicable_proposal_types' => ['COMPANY_SIGNAL_BRIEF', 'BATTLECARD_BRIEF'],
        'applicable_requested_action_classes' => ['ANALYZE', 'RELEASE_INTELLIGENCE'],
        'available_verdicts' => [
            'ALLOW_INFORMATIONAL_RELEASE', 'REQUIRE_MORE_EVIDENCE', 'REQUIRE_REVISION',
            'HOLD', 'BLOCK', 'QUARANTINE', 'REQUIRE_INCIDENT_EVALUATION',
        ],
        'default_fail_closed_verdict' => 'HOLD',
        'rules' => [
            [
                'rule_key' => 'p01_relevance_customer_interest',
                'rule_category' => 'P01_RELEVANCE',
                'rule_summary' => 'The signal must concern a competitor within the customer\'s competitive interest.',
                'check_type' => 'ENUM_STATUS',
                'failure_effect' => 'HOLD',
                'priority' => 50,
            ],
            [
                'rule_key' => 'p01_relevance_source_authority',
                'rule_category' => 'P01_RELEVANCE',
                'rule_summary' => 'The backing source card must permit customer-visible intelligence output.',
                'check_type' => 'ENUM_STATUS',
                'failure_effect' => 'HOLD',
                'priority' => 40,
            ],
            [
                'rule_key' => 'p01_relevance_insufficient_evidence',
                'rule_category' => 'P01_RELEVANCE',
                'rule_summary' => 'A binding release needs a classified structural change (not UNKNOWN).',
                'check_type' => 'PASS_FAIL',
                'failure_effect' => 'REQUIRE_MORE_EVIDENCE',
                'priority' => 30,
            ],
            [
                'rule_key' => 'p01_relevance_stale_input',
                'rule_category' => 'P01_RELEVANCE',
                'rule_summary' => 'The source-card review must be current (not expired).',
                'check_type' => 'ENUM_STATUS',
                'failure_effect' => 'HOLD',
                'priority' => 20,
            ],
        ],
    ];

    /**
     * The P03 prospect-admission policy set.
     *
     * It governs BOTH lead-qualification paths, and the difference between them is the whole point:
     *
     *   FIT_HYPOTHESIS               the Watch List. "There is a reason to look at this."
     *   PRIORITY_OUTREACH_QUEUE_ADMISSION  the Call Now list. "There is a timely reason to act."
     *
     * The buyer-intent rule fires on the SECOND only. A Watch-List entry never asserts buyer
     * intent, so a rule that fired on it would be refusing a claim nobody made. A queue admission
     * DOES assert timeliness, and must cite a buying TRIGGER signal that was directly OBSERVED —
     * absent that, it BLOCKS as an overclaim and opens an incident.
     *
     * That rule is non-overridable by design: it is the highest-priority ordinary rule in the set,
     * so no more permissive rule below it can reach an allow once it has fired.
     */
    public const P03_PROSPECT_ADMISSION = [
        'policy_key' => 'P03_PROSPECT_ADMISSION',
        'policy_version' => 'v1',
        'display_name' => 'P03 prospect admission policy',
        'purpose_summary' => 'Lead-qualification admissibility for FIT_HYPOTHESIS and PRIORITY_OUTREACH_QUEUE_ADMISSION.',
        'applicable_proposal_types' => ['FIT_HYPOTHESIS', 'PRIORITY_OUTREACH_QUEUE_ADMISSION'],
        'applicable_requested_action_classes' => ['ANALYZE', 'RELEASE_INTELLIGENCE'],
        'available_verdicts' => [
            'ALLOW_INFORMATIONAL_RELEASE', 'REQUIRE_MORE_EVIDENCE', 'REQUIRE_REVISION',
            'HOLD', 'BLOCK', 'QUARANTINE', 'REQUIRE_INCIDENT_EVALUATION',
        ],
        'default_fail_closed_verdict' => 'HOLD',
        'rules' => [
            [
                'rule_key' => 'p03_buyer_intent_trigger',
                'rule_category' => 'P03_PROSPECT_ADMISSION',
                'rule_summary' => 'A queue admission must cite a buying-trigger signal that was directly observed; otherwise it is a buyer-intent overclaim.',
                'check_type' => 'PASS_FAIL',
                // BLOCK, not HOLD. An overclaim about a person\'s intent is not something to revise
                // — the claim itself was never available to make.
                'failure_effect' => 'BLOCK',
                'incident_evaluation_if_failed' => 1,
                // The HIGHEST priority in the set, so no more permissive rule below can reach an
                // allow once this one has fired.
                'priority' => 90,
            ],
            [
                'rule_key' => 'p03_icp_excluded_segment',
                'rule_category' => 'P03_PROSPECT_ADMISSION',
                'rule_summary' => 'A prospect matching a tenant-declared excluded segment is not admissible.',
                'check_type' => 'PASS_FAIL',
                'failure_effect' => 'BLOCK',
                'priority' => 80,
            ],
            [
                'rule_key' => 'p03_admission_fit_present',
                'rule_category' => 'P03_PROSPECT_ADMISSION',
                'rule_summary' => 'An UNFIT pairing is never a reason to investigate.',
                'check_type' => 'ENUM_STATUS',
                'failure_effect' => 'REQUIRE_MORE_EVIDENCE',
                'priority' => 50,
            ],
            [
                'rule_key' => 'p03_admission_catalogue_active',
                'rule_category' => 'P03_PROSPECT_ADMISSION',
                'rule_summary' => 'The catalogue behind the cited product must still be ACTIVE.',
                'check_type' => 'ENUM_STATUS',
                'failure_effect' => 'HOLD',
                'priority' => 40,
            ],
            [
                'rule_key' => 'p03_admission_signal_released',
                'rule_category' => 'P03_PROSPECT_ADMISSION',
                'rule_summary' => 'The cited company signal must still be RELEASED.',
                'check_type' => 'ENUM_STATUS',
                'failure_effect' => 'HOLD',
                'priority' => 30,
            ],
        ],
    ];

    /**
     * P04 intelligence-distribution admission.
     *
     * The set governs INTELLIGENCE_DISTRIBUTION_ROUTING only. Distribution ROUTES intelligence that
     * M10 already recorded as released; it derives no new fact, and the verdict decides whether an
     * already-true briefing may be shown to a particular internal role — never whether anything may
     * leave the tenant. External delivery is not in this vocabulary at all.
     */
    /**
     * P02 entity-twin state-change admission.
     *
     * The set governs ENTITY_TWIN_STATE_CHANGE, and what it admits is an INTERNAL snapshot release:
     * that the tenant's own people may read a recorded twin state. Nothing here publishes, sends,
     * or reaches an external party.
     *
     * FIVE OF ITS RULES ARE NON-OVERRIDABLE AND CARRY AN INCIDENT, and they sit at the top of the
     * priority order for a reason: once a twin state has been caught profiling a person, scoring an
     * employee, carrying private external data, leaking first-party material into an external twin,
     * or mutating a released snapshot directly, no more permissive rule below can reach an allow.
     * They are the privacy and integrity floor of the whole module.
     *
     * The rest grade downward from BLOCK (an unsupported claim, an unapproved source, a licence
     * that forbids the use) through REQUIRE_MORE_EVIDENCE (no uncertainty summary) to HOLD (a
     * conflict nobody has resolved, a stale source, a missing retention or release ceiling) — the
     * difference being whether the state is WRONG or merely UNFINISHED.
     */
    public const P02_TWIN_STATE_ADMISSION = [
        'policy_key' => 'P02_TWIN_STATE_ADMISSION',
        'policy_version' => 'v1',
        'display_name' => 'P02 entity twin state admission policy',
        'purpose_summary' => 'P02 entity-twin state-change admission for ENTITY_TWIN_STATE_CHANGE. Internal evidence-backed twin-state snapshots only; never an unsupported claim, never a person/employee psychographic-or-scoring overreach, never private external data, never a first-party leak into an external twin, never a buyer-intent overclaim, never a direct mutation of a released snapshot.',
        'applicable_proposal_types' => ['ENTITY_TWIN_STATE_CHANGE'],
        'applicable_requested_action_classes' => ['RELEASE_INTELLIGENCE'],
        'available_verdicts' => [
            'ALLOW_INFORMATIONAL_RELEASE', 'REQUIRE_MORE_EVIDENCE', 'REQUIRE_REVISION',
            'HOLD', 'BLOCK', 'QUARANTINE', 'REQUIRE_INCIDENT_EVALUATION',
        ],
        'default_fail_closed_verdict' => 'HOLD',
        'rules' => [
            // --- the five non-overridable privacy/integrity rules ---------------------------------
            [
                'rule_key' => 'p02_person_profile_overreach',
                'rule_category' => 'P02_TWIN_STATE_ADMISSION',
                'rule_summary' => 'No twin state field may carry psychographic / emotional / sensitive-trait / health / political / religious / sexual / private-life profiling.',
                'check_type' => 'PROHIBITION_CHECK',
                'failure_effect' => 'BLOCK',
                'incident_evaluation_if_failed' => 1,
                'priority' => 58,
            ],
            [
                'rule_key' => 'p02_employee_scoring_or_surveillance',
                'rule_category' => 'P02_TWIN_STATE_ADMISSION',
                'rule_summary' => 'No twin state field may carry employee productivity or loyalty scoring, or covert surveillance.',
                'check_type' => 'PROHIBITION_CHECK',
                'failure_effect' => 'BLOCK',
                'incident_evaluation_if_failed' => 1,
                'priority' => 57,
            ],
            [
                'rule_key' => 'p02_external_entity_private_data',
                'rule_category' => 'P02_TWIN_STATE_ADMISSION',
                'rule_summary' => 'An EXTERNAL entity twin may carry no private (non-public-sourced) data.',
                'check_type' => 'PROHIBITION_CHECK',
                'failure_effect' => 'BLOCK',
                'incident_evaluation_if_failed' => 1,
                'priority' => 56,
            ],
            [
                'rule_key' => 'p02_first_party_external_leakage',
                'rule_category' => 'P02_TWIN_STATE_ADMISSION',
                'rule_summary' => 'First-party material may not be carried into an EXTERNAL entity twin.',
                'check_type' => 'PROHIBITION_CHECK',
                'failure_effect' => 'BLOCK',
                'incident_evaluation_if_failed' => 1,
                'priority' => 55,
            ],
            [
                'rule_key' => 'p02_direct_mutation_of_released_snapshot',
                'rule_category' => 'P02_TWIN_STATE_ADMISSION',
                'rule_summary' => 'A released snapshot is immutable; a change must supersede it, never edit it.',
                'check_type' => 'PROHIBITION_CHECK',
                'failure_effect' => 'BLOCK',
                'incident_evaluation_if_failed' => 1,
                'priority' => 54,
            ],
            // --- the substantive BLOCKs -------------------------------------------------------------
            [
                'rule_key' => 'p02_unsupported_twin_claim',
                'rule_category' => 'P02_TWIN_STATE_ADMISSION',
                'rule_summary' => 'Every asserted twin state field must rest on cited evidence.',
                'check_type' => 'PASS_FAIL',
                'failure_effect' => 'BLOCK',
                'priority' => 50,
            ],
            [
                'rule_key' => 'p02_buyer_intent_overclaim',
                'rule_category' => 'P02_TWIN_STATE_ADMISSION',
                'rule_summary' => 'A twin state may describe what was observed; it may not assert that a company intends to buy.',
                'check_type' => 'PROHIBITION_CHECK',
                'failure_effect' => 'BLOCK',
                'priority' => 48,
            ],
            [
                'rule_key' => 'p02_relationship_edge_unsupported',
                'rule_category' => 'P02_TWIN_STATE_ADMISSION',
                'rule_summary' => 'An asserted relationship edge must cite evidence and a confidence classification.',
                'check_type' => 'PASS_FAIL',
                'failure_effect' => 'BLOCK',
                'priority' => 46,
            ],
            [
                'rule_key' => 'p02_source_not_approved',
                'rule_category' => 'P02_TWIN_STATE_ADMISSION',
                'rule_summary' => 'The backing source card must be approved at verdict time.',
                'check_type' => 'ENUM_STATUS',
                'failure_effect' => 'BLOCK',
                'priority' => 44,
            ],
            [
                'rule_key' => 'p02_license_restricts_use',
                'rule_category' => 'P02_TWIN_STATE_ADMISSION',
                'rule_summary' => 'The backing licence class must permit this use.',
                'check_type' => 'ENUM_STATUS',
                'failure_effect' => 'BLOCK',
                'priority' => 42,
            ],
            // --- unfinished rather than wrong -------------------------------------------------------
            [
                'rule_key' => 'p02_uncertainty_missing',
                'rule_category' => 'P02_TWIN_STATE_ADMISSION',
                'rule_summary' => 'An uncertainty summary is required for release.',
                'check_type' => 'PASS_FAIL',
                'failure_effect' => 'REQUIRE_MORE_EVIDENCE',
                'priority' => 40,
            ],
            [
                'rule_key' => 'p02_conflicting_evidence_unresolved',
                'rule_category' => 'P02_TWIN_STATE_ADMISSION',
                'rule_summary' => 'A recorded evidence conflict must be resolved before release.',
                'check_type' => 'PASS_FAIL',
                'failure_effect' => 'HOLD',
                'priority' => 38,
            ],
            [
                'rule_key' => 'p02_stale_or_superseded_source',
                'rule_category' => 'P02_TWIN_STATE_ADMISSION',
                'rule_summary' => 'The backing source must not be revoked, superseded or past its review window.',
                'check_type' => 'ENUM_STATUS',
                'failure_effect' => 'HOLD',
                'priority' => 36,
            ],
            [
                'rule_key' => 'p02_retention_policy_missing',
                'rule_category' => 'P02_TWIN_STATE_ADMISSION',
                'rule_summary' => 'The backing card must declare a retention class.',
                'check_type' => 'PASS_FAIL',
                'failure_effect' => 'HOLD',
                'priority' => 34,
            ],
            [
                'rule_key' => 'p02_release_ceiling_missing',
                'rule_category' => 'P02_TWIN_STATE_ADMISSION',
                'rule_summary' => 'The twin version must declare a claim ceiling.',
                'check_type' => 'PASS_FAIL',
                'failure_effect' => 'HOLD',
                'priority' => 32,
            ],
        ],
    ];

    public const P04_DISTRIBUTION_ADMISSION = [
        'policy_key' => 'P04_DISTRIBUTION_ADMISSION',
        'policy_version' => 'v1',
        'display_name' => 'P04 intelligence distribution admission policy',
        'purpose_summary' => 'Internal role-routing of M10-recorded P01/P03 intelligence; never an individual identifier, never an unauthorized role, never a sensitive briefing without a recorded role/legal basis.',
        'applicable_proposal_types' => ['INTELLIGENCE_DISTRIBUTION_ROUTING'],
        'applicable_requested_action_classes' => ['RELEASE_INTELLIGENCE'],
        'available_verdicts' => [
            'ALLOW_INFORMATIONAL_RELEASE', 'REQUIRE_MORE_EVIDENCE', 'REQUIRE_REVISION',
            'HOLD', 'BLOCK', 'QUARANTINE', 'REQUIRE_INCIDENT_EVALUATION',
        ],
        'default_fail_closed_verdict' => 'HOLD',
        'rules' => [
            [
                'rule_key' => 'p04_individual_identifier_forbidden',
                'rule_category' => 'P04_DISTRIBUTION_ADMISSION',
                'rule_summary' => 'The composed briefing must carry no structured individual identifier; defence-in-depth over the ROLE_CLASS and delivery-assurance storage pins.',
                'check_type' => 'PASS_FAIL',
                // BLOCK and an incident, at the highest priority in the set: once a briefing has
                // been caught carrying a person, no more permissive rule below can reach an allow.
                'failure_effect' => 'BLOCK',
                'incident_evaluation_if_failed' => 1,
                'priority' => 55,
            ],
            [
                'rule_key' => 'p04_unauthorized_role',
                'rule_category' => 'P04_DISTRIBUTION_ADMISSION',
                'rule_summary' => 'The target org-role must be a configured distribution recipient.',
                'check_type' => 'ENUM_STATUS',
                'failure_effect' => 'BLOCK',
                'priority' => 50,
            ],
            [
                'rule_key' => 'p04_no_legal_basis_for_sensitive',
                'rule_category' => 'P04_DISTRIBUTION_ADMISSION',
                'rule_summary' => 'A SENSITIVE briefing must cite a recorded role/legal basis.',
                'check_type' => 'PASS_FAIL',
                'failure_effect' => 'BLOCK',
                'priority' => 40,
            ],
            [
                'rule_key' => 'p04_redaction_required',
                'rule_category' => 'P04_DISTRIBUTION_ADMISSION',
                'rule_summary' => 'A SENSITIVE briefing must declare a non-empty redaction profile.',
                // HOLD, not BLOCK: an unredacted sensitive briefing is a briefing that is not
                // finished, and configuring the redaction is a repair the operator can make.
                'check_type' => 'PASS_FAIL',
                'failure_effect' => 'HOLD',
                'priority' => 30,
            ],
            [
                'rule_key' => 'p04_source_not_released',
                'rule_category' => 'P04_DISTRIBUTION_ADMISSION',
                'rule_summary' => 'The cited P01/P03 source must still be relied upon at verdict time.',
                'check_type' => 'ENUM_STATUS',
                'failure_effect' => 'HOLD',
                'priority' => 20,
            ],
        ],
    ];

    /**
     * P05 marketing-asset draft admission.
     *
     * The set governs MARKETING_ASSET_DRAFT only, and what it admits is an INTERNAL release — that
     * the tenant's own people may read a draft in the console. There is no verdict here that
     * publishes anything, because there is nothing in the plane to publish with.
     */
    public const P05_MARKETING_ASSET_ADMISSION = [
        'policy_key' => 'P05_MARKETING_ASSET_ADMISSION',
        'policy_version' => 'v1',
        'display_name' => 'P05 marketing asset draft admission policy',
        'purpose_summary' => 'Internal marketing-draft admission; never an uncited competitor claim, a forbidden marketing pattern, a stale source, a non-review asset, or a missing evidence basis.',
        'applicable_proposal_types' => ['MARKETING_ASSET_DRAFT'],
        'applicable_requested_action_classes' => ['RELEASE_INTELLIGENCE'],
        'available_verdicts' => [
            'ALLOW_INFORMATIONAL_RELEASE', 'REQUIRE_MORE_EVIDENCE', 'REQUIRE_REVISION',
            'HOLD', 'BLOCK', 'QUARANTINE', 'REQUIRE_INCIDENT_EVALUATION',
        ],
        'default_fail_closed_verdict' => 'HOLD',
        'rules' => [
            [
                'rule_key' => 'p05_uncited_competitor_claim',
                'rule_category' => 'P05_MARKETING_ASSET_ADMISSION',
                'rule_summary' => 'Copy making a comparative claim must cite governed evidence for it.',
                'check_type' => 'PASS_FAIL',
                // BLOCK. A comparative claim with nothing behind it is the one kind of marketing
                // copy that can defame somebody, and revising the wording would not supply the
                // evidence it lacks.
                'failure_effect' => 'BLOCK',
                'priority' => 55,
            ],
            [
                'rule_key' => 'p05_forbidden_marketing_pattern',
                'rule_category' => 'P05_MARKETING_ASSET_ADMISSION',
                'rule_summary' => 'Copy must carry no dark-pattern, deception or sensitive-trait-targeting marker.',
                'check_type' => 'PROHIBITION_CHECK',
                // BLOCK and an incident. No citation makes fake scarcity honest, and copy that
                // reached the arbiter carrying it is a question about how it was produced.
                'failure_effect' => 'BLOCK',
                'incident_evaluation_if_failed' => 1,
                'priority' => 54,
            ],
            [
                'rule_key' => 'p05_source_still_released',
                'rule_category' => 'P05_MARKETING_ASSET_ADMISSION',
                'rule_summary' => 'The cited P01/P03 source must still be relied upon at verdict time.',
                'check_type' => 'ENUM_STATUS',
                'failure_effect' => 'BLOCK',
                'priority' => 50,
            ],
            [
                'rule_key' => 'p05_draft_only',
                'rule_category' => 'P05_MARKETING_ASSET_ADMISSION',
                'rule_summary' => 'Only an IN_REVIEW draft may receive an admission verdict.',
                'check_type' => 'ENUM_STATUS',
                'failure_effect' => 'REQUIRE_REVISION',
                'priority' => 45,
            ],
            [
                'rule_key' => 'p05_evidence_basis_present',
                'rule_category' => 'P05_MARKETING_ASSET_ADMISSION',
                'rule_summary' => 'The proposal must bind a draft-basis evidence object in the draft\'s own scope.',
                'check_type' => 'REQUIRED_REFERENCE',
                'failure_effect' => 'REQUIRE_MORE_EVIDENCE',
                'priority' => 40,
            ],
        ],
    ];

    /**
     * P07 outreach-execution admission.
     *
     * The requested action class is `ANALYZE`, and that is not a typo. What this set admits is a
     * DECISION ABOUT an outreach — whether one would be permissible — recorded as a frozen
     * artifact. It admits no dispatch, because this projection has no channel to dispatch on.
     */
    public const P07_OUTREACH_EXECUTION_ADMISSION = [
        'policy_key' => 'P07_OUTREACH_EXECUTION_ADMISSION',
        'policy_version' => 'v1',
        'display_name' => 'P07 outreach execution admission policy',
        'purpose_summary' => 'Synthetic outreach admission; never without affirmative consent and a recorded lawful basis, never carrying un-pseudonymized PII, never prohibited content, never inside a channel cooldown.',
        'applicable_proposal_types' => ['CRM_OUTREACH_EXECUTION'],
        'applicable_requested_action_classes' => ['ANALYZE'],
        'available_verdicts' => [
            'ALLOW_INFORMATIONAL_RELEASE', 'REQUIRE_MORE_EVIDENCE', 'REQUIRE_REVISION',
            'HOLD', 'BLOCK', 'QUARANTINE', 'REQUIRE_INCIDENT_EVALUATION',
        ],
        'default_fail_closed_verdict' => 'HOLD',
        'rules' => [
            [
                'rule_key' => 'p07_consent_lawful_basis',
                'rule_category' => 'P07_OUTREACH_EXECUTION_ADMISSION',
                'rule_summary' => 'Outreach requires affirmative consent AND a recorded lawful basis, re-derived at verdict time.',
                'check_type' => 'PASS_FAIL',
                // The HIGHEST priority in the set, BLOCK, and an incident. Contacting somebody who
                // did not agree to be contacted is not a draft to revise.
                'failure_effect' => 'BLOCK',
                'incident_evaluation_if_failed' => 1,
                'priority' => 56,
            ],
            [
                'rule_key' => 'p07_pii_not_pseudonymized',
                'rule_category' => 'P07_OUTREACH_EXECUTION_ADMISSION',
                'rule_summary' => 'The outreach content must carry no un-pseudonymized individual identifier.',
                'check_type' => 'PASS_FAIL',
                // Also BLOCK with an incident: content reaching the arbiter with raw PII in it means
                // the firewall was bypassed, which is a question about the pipeline, not the copy.
                'failure_effect' => 'BLOCK',
                'incident_evaluation_if_failed' => 1,
                'priority' => 54,
            ],
            [
                'rule_key' => 'p07_content_policy',
                'rule_category' => 'P07_OUTREACH_EXECUTION_ADMISSION',
                'rule_summary' => 'The outreach content must carry no prohibited claim marker.',
                'check_type' => 'PASS_FAIL',
                // HOLD, not BLOCK. Unlike consent and PII, this one IS a wording problem, and the
                // verdict says so by leaving the proposal where a revision can reach it.
                'failure_effect' => 'HOLD',
                'priority' => 40,
            ],
            [
                'rule_key' => 'p07_channel_cooldown',
                'rule_category' => 'P07_OUTREACH_EXECUTION_ADMISSION',
                'rule_summary' => 'A prior non-blocked outreach to the same recipient and channel within the cooldown window bars another.',
                'check_type' => 'PASS_FAIL',
                'failure_effect' => 'HOLD',
                'priority' => 30,
            ],
            [
                'rule_key' => 'p07_source_not_released',
                'rule_category' => 'P07_OUTREACH_EXECUTION_ADMISSION',
                'rule_summary' => 'The cited source release must still be relied upon at verdict time.',
                'check_type' => 'ENUM_STATUS',
                'failure_effect' => 'HOLD',
                'priority' => 20,
            ],
        ],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Create and ACTIVATE a policy set for a scope.
     *
     * @param array<string, mixed> $definition one of the constants above
     */
    public function seed(array $definition, string $tenantId, string $workspaceId): Outcome
    {
        $existing = $this->db->one(
            'SELECT arbiter_policy_set_id FROM arbiter_policy_sets
             WHERE policy_key = ? AND tenant_id = ? AND workspace_id = ? AND status = ?',
            [$definition['policy_key'], $tenantId, $workspaceId, 'ACTIVE']
        );
        if ($existing !== null) {
            return Outcome::ok([
                'arbiter_policy_set_id' => (string) $existing['arbiter_policy_set_id'],
                'created' => false,
            ]);
        }

        $policySetId = Ids::new();
        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use ($policySetId, $definition, $tenantId, $workspaceId, $now): Outcome {
            $tx->insert('arbiter_policy_sets', [
                'arbiter_policy_set_id' => $policySetId,
                'schema_version' => '1',
                'tenant_id' => $tenantId,
                'workspace_id' => $workspaceId,
                'policy_key' => $definition['policy_key'],
                'policy_version' => $definition['policy_version'],
                'display_name' => $definition['display_name'],
                'purpose_summary' => $definition['purpose_summary'],
                'applicable_proposal_types' => json_encode($definition['applicable_proposal_types'], JSON_THROW_ON_ERROR),
                'applicable_requested_action_classes' => json_encode($definition['applicable_requested_action_classes'], JSON_THROW_ON_ERROR),
                'available_verdicts' => json_encode($definition['available_verdicts'], JSON_THROW_ON_ERROR),
                'default_fail_closed_verdict' => $definition['default_fail_closed_verdict'],
                // Both CHECK-pinned to 1. A material outcome ALWAYS requires a decision-ledger
                // record, and a policy set NEVER approves or executes — those are M09's, behind a
                // separate seat. Neither can be configured away by a policy author.
                'requires_decision_ledger_for_material_outcome' => 1,
                'does_not_approve_or_execute' => 1,
                'status' => 'ACTIVE',
                'created_at' => $now,
                'activated_at' => $now,
            ]);

            foreach ([...self::SEED_RULES, ...$definition['rules']] as $rule) {
                $tx->insert('arbiter_policy_rules', [
                    'arbiter_policy_rule_id' => Ids::new(),
                    'schema_version' => '1',
                    'arbiter_policy_set_id' => $policySetId,
                    'policy_version' => $definition['policy_version'],
                    'rule_key' => $rule['rule_key'],
                    'rule_category' => $rule['rule_category'],
                    'rule_summary' => $rule['rule_summary'],
                    'check_type' => $rule['check_type'],
                    'failure_effect' => $rule['failure_effect'],
                    'incident_evaluation_if_failed' => (int) ($rule['incident_evaluation_if_failed'] ?? 0),
                    'priority' => $rule['priority'],
                    'status' => 'ACTIVE',
                    'created_at' => $now,
                ]);
            }

            return Outcome::ok(['arbiter_policy_set_id' => $policySetId, 'created' => true]);
        });
    }

    /**
     * Deactivate a policy set — the stand-down lever.
     *
     * Nothing routed through a deactivated set can be admitted, and the set stays queryable as
     * history rather than being deleted.
     */
    public function deactivate(string $policySetId, string $reasonCode): Outcome
    {
        $affected = $this->db->run(
            'UPDATE arbiter_policy_sets SET status = ? WHERE arbiter_policy_set_id = ? AND status = ?',
            ['SUPERSEDED', $policySetId, 'ACTIVE']
        );

        return $affected === 1
            ? Outcome::ok(['arbiter_policy_set_id' => $policySetId, 'status' => 'SUPERSEDED', 'reason_code' => $reasonCode])
            : Outcome::refuse('ARBITER_POLICY_NOT_ACTIVE', ['arbiter_policy_set_id' => $policySetId], 'POLICY');
    }
}
