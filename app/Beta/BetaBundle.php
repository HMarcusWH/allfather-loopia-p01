<?php

declare(strict_types=1);

namespace Allfather\Loopia\Beta;

use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P01\Entitlements;

/**
 * The closed-beta commercial bundle: one platform tier, zero charge, read-only.
 *
 * WHY THIS IS NOT INSIDE A PRODUCT. The bundle spans P01 through P05, so putting it in
 * `P01\Registration` would make one product the owner of four others' commercial definition. It is
 * a PLATFORM concern — a tier is a platform row — and it lives at the platform level accordingly.
 * What it is NOT is a second entitlement system: every write goes through `P01\Entitlements`, which
 * is the one component that may define a tier or subscribe a tenant.
 *
 * WHAT THE BUNDLE MAY DO. The entitlement plane narrows; it never grants. This tier is a SECOND
 * gate in front of M02, and it carries exactly the six read permissions the wired beta surfaces
 * ask for — the ones `Surfacing`, `TwinRelease`, `RelationshipRelease`, `P03\Release`,
 * `P04\Release` and `P05\Release` name as their own `REQUIRED_PERMISSION`. Not one more. An invited
 * partner whose role does not carry a permission is denied by M02 whatever this tier says, and a
 * tenant whose tier lacks one is denied whatever their role says.
 *
 * WHAT IS DELIBERATELY ABSENT, and why each absence is load-bearing:
 *
 *   NO WRITE PERMISSION. The beta is an observation exercise. `.review`, `.propose`, `.upload` and
 *   `.approve` are all authorities somebody could act on, and none of them is being sold here.
 *
 *   NO P07 PERMISSION. `outreach.*` and `external_message.approve_or_send` sit on the plane this
 *   projection structurally cannot reach; granting them commercially would assert an authority the
 *   code has no path to exercise, which is the kind of claim this repository exists to refuse.
 *
 *   NO SEND, PUBLISH OR EXPORT PERMISSION. The release ceiling is `TENANT_INTERNAL`, and a
 *   commercial gate is not where that gets widened.
 *
 *   NO `decision.read`. The M10 browser read gates on M02 ALONE — `DecisionLedgerAdapter` calls the
 *   access guard and never the entitlement plane — so adding it to the tier would grant nothing and
 *   would misstate what the tenant bought. It stays a role concern.
 *
 *   NO `ROLE_BETA_PARTNER`. Roles are the authority plane and already exist; inventing a beta role
 *   would put authority in the commercial plane by the back door.
 *
 * THE TIER DEFINITION IS CHECKED, NOT ASSUMED. The product registrations reuse a tier found by key
 * without inspecting it, which is safe for a tier only they write. This one is reachable from an
 * operator CLI, so a tier already present under this key with a DIFFERENT permission set, status or
 * price is refused rather than reused or repaired. Silently widening an existing beta tier is
 * exactly how an entitlement plane becomes an authority plane.
 */
final class BetaBundle
{
    /** The one platform tier this bundle defines. */
    public const TIER_KEY = 'LOOPIA_SME_BETA';

    public const TIER_DISPLAY_NAME = 'Loopia SME Closed Beta';

    /** Zero charge. `no_external_charge_executed` is CHECK-pinned true and nobody is billed here. */
    public const MONTHLY_FEE_MINOR_UNITS = 0;

    public const CURRENCY = 'SEK';

    public const BILLING_PERIOD = 'MONTHLY';

    /** A closed beta is a trial, in the schema's own vocabulary. */
    public const SUBSCRIPTION_STATUS = 'TRIAL';

    /**
     * The exact permission set, sorted so a comparison is an equality rather than a search.
     *
     * Each one is the `REQUIRED_PERMISSION` of a browser read that is actually wired:
     *   battlecard.read              P01 released battlecards
     *   entity_twin.read             P02 entity AND relationship snapshots
     *   marketing_asset.read         P05 internal marketing drafts
     *   priority_outreach_queue.read P03 priority outreach queue
     *   prospect.read                P03 monitored prospects
     *   role_briefing.read           P04 role distributions
     *
     * @var list<string>
     */
    public const PERMISSIONS = [
        'battlecard.read',
        'entity_twin.read',
        'marketing_asset.read',
        'priority_outreach_queue.read',
        'prospect.read',
        'role_briefing.read',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Entitlements $entitlements,
    ) {
    }

    /**
     * The beta tier, created once per deployment and never silently altered.
     *
     * Three outcomes, and the third is the one worth having:
     *   absent                          -> defined, exactly as declared above
     *   present and exactly as declared -> reused
     *   present and different           -> REFUSED, naming the facets that differ
     */
    public function ensureTier(): Outcome
    {
        $rows = $this->db->all(
            'SELECT subscription_tier_id, status, monthly_fee_minor_units, currency, billing_period
             FROM subscription_tiers WHERE tier_key = ?
             ORDER BY subscription_tier_id',
            [self::TIER_KEY]
        );

        if (count($rows) > 1) {
            // Two definitions of one product make "what does the beta include?" a question with two
            // answers, and `tenantTierGrants` would take whichever the join reached first.
            return Outcome::refuse('BETA_TIER_AMBIGUOUS', [
                'tier_key' => self::TIER_KEY,
                'definition_count' => count($rows),
            ], 'POLICY', 'S4');
        }

        if ($rows === []) {
            // `defineTier` refuses a permission the platform does not define, so the beta tier can
            // only be created after the product registrations have declared their vocabulary. That
            // ordering is deliberate: the commercial plane must not mint an authority M02 never
            // heard of, and this bundle does NOT insert into `permissions` to work around it.
            $defined = $this->entitlements->defineTier(
                self::TIER_KEY,
                self::TIER_DISPLAY_NAME,
                self::MONTHLY_FEE_MINOR_UNITS,
                self::PERMISSIONS,
                self::CURRENCY,
                self::BILLING_PERIOD,
            );
            if (!$defined->isOk()) {
                return $defined;
            }

            // Both branches answer with the same shape, `created` included. A caller that had to
            // know which branch ran in order to read the result would find that out by guessing.
            return Outcome::ok([
                'subscription_tier_id' => (string) $defined->value()['subscription_tier_id'],
                'created' => true,
            ]);
        }

        $tier = $rows[0];
        $tierId = (string) $tier['subscription_tier_id'];
        $mismatches = [];

        if ((string) $tier['status'] !== 'ACTIVE') {
            $mismatches[] = 'status';
        }
        if ((int) $tier['monthly_fee_minor_units'] !== self::MONTHLY_FEE_MINOR_UNITS) {
            $mismatches[] = 'monthly_fee_minor_units';
        }
        if ((string) $tier['currency'] !== self::CURRENCY) {
            $mismatches[] = 'currency';
        }
        if ((string) $tier['billing_period'] !== self::BILLING_PERIOD) {
            $mismatches[] = 'billing_period';
        }
        if ($this->tierPermissions($tierId) !== self::PERMISSIONS) {
            $mismatches[] = 'permissions';
        }

        if ($mismatches !== []) {
            // NOT repaired. An operator who meets this has a tier somebody else defined, and
            // converging on ours would overwrite a commercial decision this component did not make.
            return Outcome::refuse('BETA_TIER_DEFINITION_MISMATCH', [
                'tier_key' => self::TIER_KEY,
                'mismatched' => $mismatches,
            ], 'POLICY', 'S4');
        }

        return Outcome::ok(['subscription_tier_id' => $tierId, 'created' => false]);
    }

    /**
     * Put one tenant on the beta tier.
     *
     * The one-live-subscription rule is the schema's, not this method's, and it is not worked
     * around. A tenant already on a DIFFERENT live tier fails closed unless the operator has
     * explicitly asked to replace it, in which case the previous subscription is transitioned to
     * `CANCELLED` — a member of the storage CHECK, not a string invented here — inside the same
     * transaction that creates the new one.
     */
    public function subscribeTenant(string $tenantId, bool $replaceLiveSubscription = false): Outcome
    {
        $tenant = $this->db->one('SELECT status FROM tenants WHERE tenant_id = ?', [$tenantId]);
        if ($tenant === null) {
            return Outcome::refuse('TENANT_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $tenant['status'] !== 'ACTIVE') {
            return Outcome::refuse('TENANT_NOT_ACTIVE', [
                'status' => (string) $tenant['status'],
            ], 'POLICY');
        }

        $tier = $this->ensureTier();
        if (!$tier->isOk()) {
            return $tier;
        }
        $tierId = (string) $tier->value()['subscription_tier_id'];

        $existing = $this->db->one(
            "SELECT tenant_subscription_id, subscription_tier_id FROM tenant_subscriptions
             WHERE tenant_id = ? AND status IN ('TRIAL', 'ACTIVE')",
            [$tenantId]
        );

        if ($existing !== null && (string) $existing['subscription_tier_id'] === $tierId) {
            // Already exactly where the caller wants it. Re-running an onboarding command must
            // converge rather than refuse.
            return Outcome::ok([
                'subscription_tier_id' => $tierId,
                'tenant_subscription_id' => (string) $existing['tenant_subscription_id'],
                'created' => false,
            ]);
        }

        if ($existing !== null && !$replaceLiveSubscription) {
            return Outcome::refuse('TENANT_ALREADY_SUBSCRIBED', [
                'tenant_subscription_id' => (string) $existing['tenant_subscription_id'],
                'remedy' => 'pass --replace-live-subscription to transition the existing subscription',
            ], 'POLICY');
        }

        return $this->db->transaction(function (Database $tx) use ($tenantId, $tierId, $existing): Outcome {
            if ($existing !== null) {
                $tx->run(
                    "UPDATE tenant_subscriptions SET status = 'CANCELLED'
                     WHERE tenant_subscription_id = ? AND status IN ('TRIAL', 'ACTIVE')",
                    [(string) $existing['tenant_subscription_id']]
                );
            }

            // Through `Entitlements`, which re-checks the one-live rule against the row we have
            // just transitioned. The rule is enforced by the component that owns it either way.
            $subscribed = $this->entitlements->subscribe($tenantId, $tierId, self::SUBSCRIPTION_STATUS);
            if (!$subscribed->isOk()) {
                return $subscribed;
            }

            return Outcome::ok([
                'subscription_tier_id' => $tierId,
                'tenant_subscription_id' => (string) $subscribed->value()['tenant_subscription_id'],
                'replaced_tenant_subscription_id' => $existing === null
                    ? null
                    : (string) $existing['tenant_subscription_id'],
                'created' => true,
            ]);
        });
    }

    /**
     * A sanitized view of one tenant's beta commercial state, for an operator support reply.
     *
     * Identifiers and statuses only. Nothing here is a credential, and nothing crosses a tenant.
     *
     * @return array<string, mixed>
     */
    public function describeTenant(string $tenantId): array
    {
        $live = $this->entitlements->activeTier($tenantId);

        return [
            'tier_key' => self::TIER_KEY,
            'tier_permissions' => self::PERMISSIONS,
            'monthly_fee_minor_units' => self::MONTHLY_FEE_MINOR_UNITS,
            'currency' => self::CURRENCY,
            'live_subscription' => $live === null ? null : [
                'tenant_subscription_id' => (string) $live['tenant_subscription_id'],
                'tier_key' => (string) $live['tier_key'],
                'status' => (string) $live['subscription_status'],
                'started_at' => (string) $live['started_at'],
                'is_beta_tier' => (string) $live['tier_key'] === self::TIER_KEY,
            ],
        ];
    }

    /** @return list<string> the tier's permission keys, sorted for exact comparison */
    private function tierPermissions(string $tierId): array
    {
        $keys = array_map(
            static fn (array $row): string => (string) $row['permission_key'],
            $this->db->all(
                'SELECT permission_key FROM subscription_tier_permissions WHERE subscription_tier_id = ?
                 ORDER BY permission_key',
                [$tierId]
            )
        );
        sort($keys, SORT_STRING);

        return $keys;
    }
}
