<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M08;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;

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

    /** @return array{decision: string, reason_codes: list<string>, severity: null, incident: false, result: null} */
    private static function notApplicable(array $rule): array
    {
        return ['decision' => 'NOT_APPLICABLE', 'reason_codes' => [], 'severity' => null, 'incident' => false, 'result' => null];
    }
}
