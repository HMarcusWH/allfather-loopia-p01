<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;

/**
 * Usage metering — an append-only ledger of what was consumed.
 *
 * **RECORDING ONLY, AND THE LINE IS HARD.** This records billable usage. It executes no charge,
 * issues no invoice, contacts no payment provider, and has no code path that could. Actually
 * charging a customer is a consequential external action — a D/E class this projection refuses at
 * four independent planes — and metering is the ANALYZE-class act of writing down what happened.
 *
 * The distinction is not a comment. `no_external_charge_executed` is CHECK-pinned to 1 in storage,
 * so a row asserting a charge was executed cannot be written at all, and the table is append-only,
 * so a recorded quantity cannot be quietly revised after the fact.
 *
 * THE RATE IS SNAPSHOTTED AT RECORD TIME. `billable_minor_units` is computed and stored rather than
 * derived on read, so a later price change never rewrites history: what a period cost is what it
 * cost under the rates in force then. Deriving it on read would make every past total a function of
 * today's price list.
 *
 * The summary is a statement of what WOULD be billed. Nobody is billed by it.
 */
final class Metering
{
    /**
     * The closed set of things this projection meters.
     *
     * Storage pins the same three. A dimension outside the set is refused here by name rather than
     * reaching the CHECK as a driver error.
     *
     * @var list<string>
     */
    public const METERED_DIMENSIONS = [
        'TOKEN_USAGE',
        'FIT_MATCHING_TOKEN_USAGE',
        'DISTRIBUTION_SEAT_USAGE',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Entitlements $entitlements,
    ) {
    }

    /**
     * Append one metered-usage row.
     *
     * Quantity and unit rate must both be non-negative integers. A negative quantity is refused
     * rather than clamped: a negative consumption is not a small consumption, it is a caller bug or
     * an attempt to reverse a record in a table that is append-only by design.
     *
     * @param array{
     *     tenant_id: string, quantity: int, workspace_id?: ?string, subscription_tier_id?: ?string,
     *     metered_dimension?: string, unit_rate_minor_units?: int, currency?: string,
     *     correlation_ref?: ?string
     * } $input
     */
    public function recordUsage(array $input): Outcome
    {
        $dimension = $input['metered_dimension'] ?? 'TOKEN_USAGE';
        if (!in_array($dimension, self::METERED_DIMENSIONS, true)) {
            return Outcome::refuse('METERED_DIMENSION_UNKNOWN', [
                'metered_dimension' => $dimension,
            ], 'POLICY');
        }
        $quantity = $input['quantity'];
        if ($quantity < 0) {
            return Outcome::refuse('INVALID_QUANTITY', ['quantity' => $quantity], 'POLICY');
        }
        $unitRate = $input['unit_rate_minor_units'] ?? 0;
        if ($unitRate < 0) {
            return Outcome::refuse('INVALID_UNIT_RATE', ['unit_rate_minor_units' => $unitRate], 'POLICY');
        }

        $recordId = Ids::new();
        $this->db->insert('usage_metering_records', [
            'usage_metering_record_id' => $recordId,
            'schema_version' => '1',
            'tenant_id' => $input['tenant_id'],
            'workspace_id' => $input['workspace_id'] ?? null,
            'subscription_tier_id' => $input['subscription_tier_id'] ?? null,
            'metered_dimension' => $dimension,
            'quantity' => $quantity,
            'unit_rate_minor_units' => $unitRate,
            'currency' => $input['currency'] ?? 'SEK',
            // Snapshotted, not derived. See the class docblock.
            'billable_minor_units' => $quantity * $unitRate,
            'correlation_ref' => $input['correlation_ref'] ?? null,
            // Written explicitly rather than left to the column default, because it is the CLAIM
            // this row makes: nothing was charged. The CHECK makes any other value unwritable.
            'no_external_charge_executed' => 1,
            'recorded_at' => $this->clock->now(),
        ]);

        return Outcome::ok([
            'usage_metering_record_id' => $recordId,
            'billable_minor_units' => $quantity * $unitRate,
        ]);
    }

    /**
     * A regenerable period summary — what WOULD be billed, paired with the tier's base fee.
     *
     * Regenerable rather than stored: a summary row would be a second place the truth lives, and
     * the metering rows are already the record. Recomputing it means a summary can never disagree
     * with the ledger it describes.
     *
     * @return array<string, mixed>
     */
    public function summarizeTenantUsage(
        string $tenantId,
        string $meteredDimension = 'TOKEN_USAGE',
        ?string $periodStart = null,
        ?string $periodEnd = null,
    ): array {
        $totals = $this->db->one(
            'SELECT COUNT(*) AS record_count,
                    COALESCE(SUM(quantity), 0) AS total_quantity,
                    COALESCE(SUM(billable_minor_units), 0) AS total_usage_billable_minor_units
             FROM usage_metering_records
             WHERE tenant_id = ? AND metered_dimension = ?
               AND (? IS NULL OR recorded_at >= ?) AND (? IS NULL OR recorded_at <= ?)',
            [$tenantId, $meteredDimension, $periodStart, $periodStart, $periodEnd, $periodEnd]
        );
        $tier = $this->entitlements->activeTier($tenantId);

        return [
            'tenant_id' => $tenantId,
            'metered_dimension' => $meteredDimension,
            'record_count' => (int) ($totals['record_count'] ?? 0),
            'total_quantity' => (int) ($totals['total_quantity'] ?? 0),
            'total_usage_billable_minor_units' => (int) ($totals['total_usage_billable_minor_units'] ?? 0),
            'monthly_fee_minor_units' => $tier === null ? 0 : (int) $tier['monthly_fee_minor_units'],
            'currency' => $tier === null ? 'SEK' : (string) $tier['currency'],
            'tier_key' => $tier === null ? null : (string) $tier['tier_key'],
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            // Restated on every summary, deliberately. A number that looks like an invoice total is
            // exactly the thing somebody will eventually treat as one.
            'no_external_charge_executed' => true,
        ];
    }
}
