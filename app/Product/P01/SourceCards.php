<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;

/**
 * P01 source authority — the per-source governance artifact and its projection onto M05.
 *
 * A source card is recorded as DATA in `source_license_cards` and PROJECTS onto the certified M05
 * `source_references` row. It grants nothing that row does not already permit: the card describes
 * what a source is licensed for, and the reference is what the capture path actually consults.
 *
 * Ingestion is FAIL-CLOSED. Unknown, unapproved, prohibited, contract-bound-without-a-contract, or
 * beyond-public-sensitivity sources never reach an `ACTIVE_FOR_CAPTURE` reference. The card is
 * recorded ANYWAY, in a non-capturable state — a source refused with no row would make "we never
 * considered it" and "we considered it and refused" indistinguishable, and the second is the one an
 * auditor needs to see.
 */
final class SourceCards
{
    /** Source types that are public-web reads, and therefore owe a robots and terms review. */
    private const PUBLIC_WEB_TYPES = ['website', 'rss', 'press_release', 'search_api'];

    /** Licence classes that are contract-bound: usable only with a governing contract on file. */
    private const CONTRACT_BOUND_LICENSES = [
        'LICENSED_INTERNAL_ONLY',
        'LICENSED_DERIVED_OUTPUT_ALLOWED',
        'LICENSED_NO_CUSTOMER_OUTPUT',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * The minimum P01 acceptance check, as a PURE default-deny evaluator.
     *
     * Anything not provably public, approved and non-prohibited fails closed. It is pure so the
     * same card always yields the same admissibility — a source's admissibility must not depend on
     * when it was asked.
     *
     * @param array<string, mixed> $card
     * @return array{admissible: bool, reason_code?: string}
     */
    public static function evaluateAdmissibility(array $card): array
    {
        if (($card['review_status'] ?? 'draft') !== 'approved') {
            return ['admissible' => false, 'reason_code' => 'CARD_NOT_APPROVED'];
        }
        if (($card['license_class'] ?? '') === 'PROHIBITED') {
            return ['admissible' => false, 'reason_code' => 'LICENSE_PROHIBITED'];
        }
        if (($card['allowed_use_class'] ?? '') === 'prohibited') {
            return ['admissible' => false, 'reason_code' => 'USE_CLASS_PROHIBITED'];
        }

        // P01 is public and non-sensitive ONLY. Anything above `low` on either risk axis cannot map
        // to PUBLIC_OR_NON_SENSITIVE, so it can never be captured on this path.
        $pii = $card['pii_risk_class'] ?? 'none';
        $sensitive = $card['sensitive_data_risk_class'] ?? 'none';
        if (in_array($pii, ['medium', 'high'], true) || in_array($sensitive, ['medium', 'high'], true)) {
            return ['admissible' => false, 'reason_code' => 'SENSITIVITY_BEYOND_PUBLIC_SCOPE'];
        }

        if (in_array($card['source_type'] ?? '', self::PUBLIC_WEB_TYPES, true)) {
            if (!($card['robots_policy_checked'] ?? false) || !($card['terms_policy_checked'] ?? false)) {
                return ['admissible' => false, 'reason_code' => 'PUBLIC_WEB_TERMS_OR_ROBOTS_NOT_REVIEWED'];
            }
        }

        $contractBound = in_array($card['license_class'] ?? '', self::CONTRACT_BOUND_LICENSES, true)
            || ($card['source_type'] ?? '') === 'licensed_api';
        if ($contractBound && (!is_string($card['contract_reference_id'] ?? null) || trim($card['contract_reference_id']) === '')) {
            return ['admissible' => false, 'reason_code' => 'CONTRACT_REQUIRED_BUT_MISSING'];
        }

        return ['admissible' => true];
    }

    /**
     * Record the source card AND its projected M05 reference in ONE transaction.
     *
     * The reference reaches `ACTIVE_FOR_CAPTURE` only when the card is admissible AND its
     * sensitivity resolves to public. Otherwise it is recorded in a non-capturable state: still
     * auditable, still provenance, but not something the capture path will act on.
     *
     * @param array<string, mixed> $card
     * @return array<string, mixed>
     */
    public function project(array $card): array
    {
        $admissibility = self::evaluateAdmissibility($card);
        $sensitivity = self::mapSensitivity($card);
        $now = $this->clock->now();
        $sourceRefId = Ids::new();
        $cardId = Ids::new();

        // Default-deny, and BOTH conditions matter: an admissible card whose sensitivity is not
        // public is still not capturable, because the sensitivity is what the capture path reads.
        $ingestable = $admissibility['admissible'] && $sensitivity === 'PUBLIC_OR_NON_SENSITIVE';
        $referenceStatus = $ingestable
            ? 'ACTIVE_FOR_CAPTURE'
            : ($sensitivity === 'PUBLIC_OR_NON_SENSITIVE' ? 'REGISTERED' : 'RESTRICTED');

        $this->db->transaction(function (Database $tx) use (
            $card, $sourceRefId, $cardId, $sensitivity, $referenceStatus, $now
        ): void {
            $tx->insert('source_references', [
                'source_ref_id' => $sourceRefId,
                'schema_version' => '1',
                'tenant_id' => $card['tenant_id'],
                // Tenant-wide, not workspace-scoped: a licence is a property of the SOURCE and the
                // tenant's agreement with it, not of the workspace that happens to look first.
                'workspace_id' => null,
                'source_type' => self::mapSourceType((string) $card['source_type']),
                'source_location_safe' => $card['source_url_safe'] ?? null,
                // The use scope carries BOTH halves of the licence, because a consumer needs both:
                // what the licence class permits and what use class the operator declared.
                'lawful_or_permitted_use_scope' => sprintf(
                    '%s/%s',
                    (string) $card['license_class'],
                    (string) $card['allowed_use_class'],
                ),
                'content_sensitivity_class' => $sensitivity,
                'status' => $referenceStatus,
                'registered_at' => $now,
            ]);

            $tx->insert('source_license_cards', [
                'source_license_card_id' => $cardId,
                'schema_version' => '1',
                'tenant_id' => $card['tenant_id'],
                'source_ref_id' => $sourceRefId,
                'source_name' => $card['source_name'],
                'contract_reference_id' => is_string($card['contract_reference_id'] ?? null) && trim($card['contract_reference_id']) !== '' ? trim($card['contract_reference_id']) : null,
                'source_url_safe' => $card['source_url_safe'] ?? null,
                'owner_company' => $card['owner_company'] ?? null,
                'source_type' => $card['source_type'],
                'allowed_use_class' => $card['allowed_use_class'],
                'license_class' => $card['license_class'],
                'customer_output_allowed' => ($card['customer_output_allowed'] ?? false) ? 1 : 0,
                'redistribution_allowed' => ($card['redistribution_allowed'] ?? false) ? 1 : 0,
                'commercial_use_allowed' => ($card['commercial_use_allowed'] ?? false) ? 1 : 0,
                'derived_summary_allowed' => ($card['derived_summary_allowed'] ?? false) ? 1 : 0,
                'raw_excerpt_customer_visible' => ($card['raw_excerpt_customer_visible'] ?? false) ? 1 : 0,
                'robots_policy_checked' => ($card['robots_policy_checked'] ?? false) ? 1 : 0,
                'terms_policy_checked' => ($card['terms_policy_checked'] ?? false) ? 1 : 0,
                'rate_limit_policy' => $card['rate_limit_policy'] ?? null,
                'caching_policy' => $card['caching_policy'] ?? 'no_cache',
                'retention_class' => $card['retention_class'] ?? 'short',
                'pii_risk_class' => $card['pii_risk_class'] ?? 'none',
                'sensitive_data_risk_class' => $card['sensitive_data_risk_class'] ?? 'none',
                'review_status' => $card['review_status'],
                'blocked_reason' => $card['blocked_reason'] ?? null,
                'approved_by' => $card['approved_by'] ?? null,
                'approved_at' => $card['approved_at'] ?? null,
                'review_expires_at' => $card['review_expires_at'] ?? null,
                'warn_after_ms' => $card['warn_after_ms'] ?? null,
                'error_after_ms' => $card['error_after_ms'] ?? null,
                'recheck_terms_after_ms' => $card['recheck_terms_after_ms'] ?? null,
                // A stale-backed output DOWNGRADES to hypothesis by default rather than being
                // blocked or silently allowed: the observation was real, but its currency is not
                // established, and "we saw this at some point" is a hypothesis about now.
                'stale_output_behavior' => $card['stale_output_behavior'] ?? 'downgrade_to_hypothesis',
                // WHOSE material this is. It defaults to PUBLIC because the P01 sources this
                // function was written for are somebody else's public pages; a tenant's OWN
                // catalogue must declare `TENANT_OWNED`, and P03 refuses a catalogue card that
                // does not. Defaulting the other way would let any public card stand as authority
                // over a tenant's own product list.
                'data_ownership' => $card['data_ownership'] ?? 'PUBLIC',
                'created_at' => $now,
            ]);
        });

        if ($ingestable) {
            return [
                'outcome' => 'PROJECTED',
                'source_license_card_id' => $cardId,
                'source_ref_id' => $sourceRefId,
                'source_reference_status' => 'ACTIVE_FOR_CAPTURE',
            ];
        }

        return [
            'outcome' => 'RECORDED_NOT_INGESTABLE',
            'source_license_card_id' => $cardId,
            'source_ref_id' => $sourceRefId,
            'source_reference_status' => $referenceStatus,
            'reason_code' => $admissibility['admissible']
                ? 'SENSITIVITY_BEYOND_PUBLIC_SCOPE'
                : $admissibility['reason_code'],
        ];
    }

    /**
     * Append one reviewed robots/terms attestation for this card.
     *
     * P01 owns source_terms_reviews. Callers may supply review evidence, but they do not write this
     * table directly. This method validates card scope and the review event shape; the append-only
     * database triggers preserve the resulting history.
     *
     * @param array{
     *   tenant_id:string,source_license_card_id:string,reviewed_by:string,reviewed_at:string,
     *   robots_policy_checked:bool,terms_policy_checked:bool,terms_snapshot_hash:?string
     * } $review
     */
    public function recordTermsReview(array $review): Outcome
    {
        $required = ['tenant_id','source_license_card_id','reviewed_by','reviewed_at','robots_policy_checked','terms_policy_checked','terms_snapshot_hash'];
        if (array_diff(array_keys($review), $required) !== [] || array_diff($required, array_keys($review)) !== []) {
            return Outcome::refuse('SOURCE_TERMS_REVIEW_INVALID');
        }
        foreach (['tenant_id','source_license_card_id','reviewed_by','reviewed_at'] as $key) {
            if (!is_string($review[$key]) || trim($review[$key]) === '') {
                return Outcome::refuse('SOURCE_TERMS_REVIEW_INVALID');
            }
        }
        if (!Clock::isCanonical($review['reviewed_at'])
            || !is_bool($review['robots_policy_checked'])
            || !is_bool($review['terms_policy_checked'])
            || ($review['terms_snapshot_hash'] !== null
                && (!is_string($review['terms_snapshot_hash']) || preg_match('/^[a-f0-9]{64}$/D', $review['terms_snapshot_hash']) !== 1))) {
            return Outcome::refuse('SOURCE_TERMS_REVIEW_INVALID');
        }
        $card = $this->db->one(
            'SELECT tenant_id FROM source_license_cards WHERE source_license_card_id=?',
            [$review['source_license_card_id']]
        );
        if ($card === null) {
            return Outcome::refuse('SOURCE_CARD_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $card['tenant_id'] !== $review['tenant_id']) {
            return Outcome::refuse('SOURCE_CARD_TENANT_MISMATCH', [], 'SCOPE', 'S4');
        }

        $id = Ids::new();
        $this->db->insert('source_terms_reviews', [
            'source_terms_review_id' => $id,
            'schema_version' => '1',
            'source_license_card_id' => $review['source_license_card_id'],
            'robots_url' => null,
            'terms_url' => null,
            'robots_policy_checked' => $review['robots_policy_checked'] ? 1 : 0,
            'terms_policy_checked' => $review['terms_policy_checked'] ? 1 : 0,
            'terms_snapshot_hash' => $review['terms_snapshot_hash'],
            'reviewed_by' => $review['reviewed_by'],
            'reviewed_at' => $review['reviewed_at'],
            'expires_at' => null,
        ]);

        return Outcome::ok(['source_terms_review_id' => $id]);
    }

    /** Map the card's source type onto the M05 `source_references` vocabulary. */
    private static function mapSourceType(string $type): string
    {
        return match ($type) {
            'customer_upload' => 'UPLOADED_ARTIFACT',
            'manual' => 'DOCUMENT',
            'website', 'rss', 'press_release', 'public_api', 'licensed_api', 'search_api' => 'WEB_RESOURCE',
            default => 'OTHER',
        };
    }

    /**
     * Map the card's two risk axes onto the M05 sensitivity class.
     *
     * The WORSE of the two governs. A source with no PII but high sensitive-data risk is not a
     * public source, and taking the better axis would let one clean axis launder the other.
     *
     * @param array<string, mixed> $card
     */
    private static function mapSensitivity(array $card): string
    {
        $risks = [$card['pii_risk_class'] ?? 'none', $card['sensitive_data_risk_class'] ?? 'none'];
        if (in_array('high', $risks, true)) {
            return 'PROHIBITED_IN_V1';
        }
        if (in_array('medium', $risks, true)) {
            return 'RESTRICTED_REVIEW';
        }

        return 'PUBLIC_OR_NON_SENSITIVE';
    }
}
