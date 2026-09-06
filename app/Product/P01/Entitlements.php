<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;

/**
 * P01 commercial entitlements — what the tenant's subscription grants.
 *
 * THE ENTITLEMENT PLANE NARROWS; IT NEVER GRANTS. A tier can only take away from what M02 already
 * allowed. It is a SECOND gate, not a second authority: a caller M02 denied is denied whatever the
 * tier says, and a caller whose tier lacks a permission is denied whatever their role says.
 *
 * The distinction matters because the two answer different questions. M02 asks "is this person
 * allowed to do this?" — an access question. The tier asks "has this organization paid for this?" —
 * a commercial one. Collapsing them would let a billing change silently become an authority change,
 * or a role grant silently become a purchase.
 *
 * A tier may also only grant permissions the PLATFORM defines: `subscription_tier_permissions`
 * references the permission vocabulary, so the commercial plane cannot invent an authority the
 * access plane never heard of.
 */
final class Entitlements
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Does the tenant's LIVE subscription to an ACTIVE tier grant this permission?
     *
     * "Live" is TRIAL or ACTIVE. A suspended, cancelled or expired subscription grants nothing —
     * and neither does a live subscription to a tier the platform has retired, which is why the
     * tier's own status is joined rather than assumed from the subscription's.
     */
    public function tenantTierGrants(string $tenantId, string $permissionKey): bool
    {
        return $this->db->one(
            "SELECT 1 AS hit
             FROM tenant_subscriptions ts
             JOIN subscription_tiers st ON st.subscription_tier_id = ts.subscription_tier_id
                  AND st.status = 'ACTIVE'
             JOIN subscription_tier_permissions stp ON stp.subscription_tier_id = st.subscription_tier_id
             WHERE ts.tenant_id = ? AND ts.status IN ('TRIAL', 'ACTIVE') AND stp.permission_key = ?
             LIMIT 1",
            [$tenantId, $permissionKey]
        ) !== null;
    }

    /**
     * The tenant's live tier, or null.
     *
     * @return array<string, mixed>|null
     */
    public function activeTier(string $tenantId): ?array
    {
        return $this->db->one(
            "SELECT st.subscription_tier_id, st.tier_key, st.monthly_fee_minor_units, st.currency,
                    st.billing_period, ts.tenant_subscription_id, ts.status AS subscription_status,
                    ts.started_at
             FROM tenant_subscriptions ts
             JOIN subscription_tiers st ON st.subscription_tier_id = ts.subscription_tier_id
                  AND st.status = 'ACTIVE'
             WHERE ts.tenant_id = ? AND ts.status IN ('TRIAL', 'ACTIVE')
             ORDER BY ts.started_at DESC LIMIT 1",
            [$tenantId]
        );
    }

    /**
     * Define a tier and the permissions it grants.
     *
     * A permission the platform does not define is refused rather than silently created: the
     * commercial plane must not be able to mint an authority the access plane never heard of.
     *
     * @param list<string> $permissionKeys
     */
    public function defineTier(
        string $tierKey,
        string $displayName,
        int $monthlyFeeMinorUnits,
        array $permissionKeys,
        string $currency = 'SEK',
        string $billingPeriod = 'MONTHLY',
    ): Outcome {
        foreach ($permissionKeys as $permissionKey) {
            $defined = $this->db->one(
                'SELECT permission_key FROM permissions WHERE permission_key = ?',
                [$permissionKey]
            );
            if ($defined === null) {
                return Outcome::refuse('TIER_PERMISSION_NOT_DEFINED', [
                    'permission_key' => $permissionKey,
                ], 'POLICY', 'S4');
            }
        }

        $tierId = Ids::new();
        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use (
            $tierId, $tierKey, $displayName, $monthlyFeeMinorUnits, $currency,
            $billingPeriod, $permissionKeys, $now
        ): Outcome {
            $tx->insert('subscription_tiers', [
                'subscription_tier_id' => $tierId,
                'schema_version' => '1',
                'tier_key' => $tierKey,
                'tier_version' => '1',
                'display_name' => $displayName,
                'monthly_fee_minor_units' => $monthlyFeeMinorUnits,
                'currency' => $currency,
                'billing_period' => $billingPeriod,
                'status' => 'ACTIVE',
                'created_at' => $now,
                'activated_at' => $now,
            ]);
            foreach (array_unique($permissionKeys) as $permissionKey) {
                $tx->insert('subscription_tier_permissions', [
                    'subscription_tier_id' => $tierId,
                    'permission_key' => $permissionKey,
                ]);
            }

            return Outcome::ok(['subscription_tier_id' => $tierId]);
        });
    }

    /** Subscribe a tenant to a tier. */
    public function subscribe(string $tenantId, string $subscriptionTierId, string $status = 'ACTIVE'): Outcome
    {
        $tier = $this->db->one(
            'SELECT status FROM subscription_tiers WHERE subscription_tier_id = ?',
            [$subscriptionTierId]
        );
        if ($tier === null) {
            return Outcome::refuse('SUBSCRIPTION_TIER_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $tier['status'] !== 'ACTIVE') {
            return Outcome::refuse('SUBSCRIPTION_TIER_NOT_ACTIVE', [
                'status' => (string) $tier['status'],
            ], 'POLICY');
        }
        // One live subscription per tenant. Two would make "what has this tenant paid for?" a
        // question with two answers, and the entitlement gate would take whichever it read first.
        $existing = $this->db->one(
            "SELECT tenant_subscription_id FROM tenant_subscriptions
             WHERE tenant_id = ? AND status IN ('TRIAL', 'ACTIVE')",
            [$tenantId]
        );
        if ($existing !== null) {
            return Outcome::refuse('TENANT_ALREADY_SUBSCRIBED', [
                'tenant_subscription_id' => (string) $existing['tenant_subscription_id'],
            ], 'POLICY');
        }

        $subscriptionId = Ids::new();
        $this->db->insert('tenant_subscriptions', [
            'tenant_subscription_id' => $subscriptionId,
            'schema_version' => '1',
            'tenant_id' => $tenantId,
            'subscription_tier_id' => $subscriptionTierId,
            'status' => $status,
            'started_at' => $this->clock->now(),
        ]);

        return Outcome::ok(['tenant_subscription_id' => $subscriptionId]);
    }
}
