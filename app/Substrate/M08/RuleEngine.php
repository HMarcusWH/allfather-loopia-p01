<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M08;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P03\FitScoring;

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
    private static function refuse(array $rule, array $codes, string $failClosed, bool $incident): array
    {
        $effect = $codes[0] === 'ARBITER_P03_UNKNOWN_RULE'
            // An unknown rule has no declared effect to honour, so it takes the policy's own
            // fail-closed verdict rather than a guessed one.
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
