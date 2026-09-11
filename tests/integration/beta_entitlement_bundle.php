<?php

declare(strict_types=1);

/**
 * The closed-beta commercial bundle, against real MariaDB.
 *
 * The claims under test are about the COMMERCIAL plane, and the sharpest of them is what the bundle
 * REFUSES. A tier that could be widened by re-running a command, or a subscription that could be
 * replaced without anybody saying so, would turn the entitlement gate from a second check into a
 * way around the first one.
 */

use Allfather\Loopia\Beta\BetaBundle;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());

    return;
}

$clock = Harness::clock();
$scope = Seed::tenantWorkspace($db, $clock, 'beta');
$entitlements = new Entitlements($db, $clock);
$beta = new BetaBundle($db, $entitlements);

// ============================================================ the permission vocabulary comes first

// `defineTier` refuses a permission the PLATFORM does not define, and the bundle does NOT insert
// one to get past it. So before any product registration has declared its vocabulary, the beta tier
// cannot exist — which is the correct ordering rather than an inconvenience.
$premature = $beta->ensureTier();
Assert::true($premature->isRefusal(), 'the beta tier cannot be defined before the permission vocabulary exists');
Assert::same(
    'TIER_PERMISSION_NOT_DEFINED',
    $premature->reasonCode(),
    'and says so by name — the commercial plane cannot mint an authority M02 never heard of',
);
Assert::same(
    null,
    $db->scalar('SELECT subscription_tier_id FROM subscription_tiers WHERE tier_key = ?', [BetaBundle::TIER_KEY]),
    'nothing was written by the refusal',
);

// The permissions enter the vocabulary the way the product registrations put them there.
Seed::role($db, $clock, 'beta_reader', BetaBundle::PERMISSIONS);

// ============================================================ the tier is exactly what it declares

$created = $beta->ensureTier();
Assert::true($created->isOk(), 'with the vocabulary present, the beta tier is defined');
$tierId = (string) $created->value()['subscription_tier_id'];
Assert::same(true, $created->value()['created'] ?? null, 'and reports that it created it');

$tier = $db->one(
    'SELECT tier_key, display_name, monthly_fee_minor_units, currency, billing_period, status
     FROM subscription_tiers WHERE subscription_tier_id = ?',
    [$tierId]
);
Assert::same('LOOPIA_SME_BETA', (string) $tier['tier_key'], 'under the declared key');
Assert::same(0, (int) $tier['monthly_fee_minor_units'], 'at zero charge — nobody is billed by a closed beta');
Assert::same('SEK', (string) $tier['currency'], 'in the declared currency');
Assert::same('MONTHLY', (string) $tier['billing_period'], 'on the declared billing period');
Assert::same('ACTIVE', (string) $tier['status'], 'and ACTIVE, or the entitlement join would grant nothing');

$stored = array_column(
    $db->all(
        'SELECT permission_key FROM subscription_tier_permissions WHERE subscription_tier_id = ?
         ORDER BY permission_key',
        [$tierId]
    ),
    'permission_key'
);
Assert::equalsList(BetaBundle::PERMISSIONS, $stored, 'carrying exactly the six declared read permissions');
Assert::same(6, count($stored), 'six, and not one more');

// EVERY stored permission is one the PLATFORM defines. The FK proves it structurally; this proves
// the bundle did not create them on the way past.
foreach ($stored as $permissionKey) {
    Assert::notSame(
        null,
        $db->one('SELECT permission_key FROM permissions WHERE permission_key = ?', [$permissionKey]),
        "{$permissionKey} was already in the platform vocabulary",
    );
}

// ============================================================ inspection writes nothing

// `inspectTier` is what `--dry-run` calls, and the property that makes a dry run mean anything is
// that it does not create the thing it is checking for. Proved against a database where the tier
// does not exist: an OK answer, and still no row afterwards.
$db->run('DELETE FROM subscription_tier_permissions WHERE subscription_tier_id = ?', [$tierId]);
$db->run('DELETE FROM subscription_tiers WHERE subscription_tier_id = ?', [$tierId]);

$inspected = $beta->inspectTier();
Assert::true($inspected->isOk(), 'inspecting an absent-but-definable tier answers OK');
Assert::same(null, $inspected->value()['subscription_tier_id'], 'reporting that it does not exist yet');
Assert::same(
    0,
    (int) $db->scalar('SELECT COUNT(*) FROM subscription_tiers WHERE tier_key = ?', [BetaBundle::TIER_KEY]),
    'and having created nothing — a dry run that added a row to the global commercial catalogue '
    . 'and then reported "nothing was written" is the one lie a validation mode must not tell',
);

// Re-create it for everything below.
$created = $beta->ensureTier();
Assert::true($created->isOk(), 'the tier is created again for the remaining cases');
$tierId = (string) $created->value()['subscription_tier_id'];

// ============================================================ idempotence

$again = $beta->ensureTier();
Assert::true($again->isOk(), 're-running converges rather than refusing');
Assert::same($tierId, (string) $again->value()['subscription_tier_id'], 'on the same tier');
Assert::same(false, $again->value()['created'] ?? null, 'and says it created nothing');
Assert::same(
    1,
    (int) $db->scalar('SELECT COUNT(*) FROM subscription_tiers WHERE tier_key = ?', [BetaBundle::TIER_KEY]),
    'one tier, one definition — two would make "what does the beta include?" a question with two answers',
);

// ============================================================ a different definition fails closed

// One permission added to the stored tier, exactly as a well-meaning widening would look. It has to
// be a permission the PLATFORM defines, or the FK refuses it before the bundle ever sees it — which
// is itself the structural half of this rule, and is why the widening has to be staged this way to
// test the application half at all.
Seed::role($db, $clock, 'ledger_reader', ['decision.read']);
$db->insert('subscription_tier_permissions', [
    'subscription_tier_id' => $tierId,
    'permission_key' => 'decision.read',
]);
$widened = $beta->ensureTier();
Assert::true($widened->isRefusal(), 'a tier carrying a permission the bundle does not declare is refused');
Assert::same('BETA_TIER_DEFINITION_MISMATCH', $widened->reasonCode(), 'by name');
Assert::true(
    in_array('permissions', $widened->detail['mismatched'] ?? [], true),
    'naming the facet that differs',
);
Assert::same(
    7,
    (int) $db->scalar('SELECT COUNT(*) FROM subscription_tier_permissions WHERE subscription_tier_id = ?', [$tierId]),
    'and the existing definition is NOT repaired — converging on ours would overwrite a decision this component did not make',
);
$db->run(
    'DELETE FROM subscription_tier_permissions WHERE subscription_tier_id = ? AND permission_key = ?',
    [$tierId, 'decision.read']
);
Assert::true($beta->ensureTier()->isOk(), 'restoring the exact definition makes it usable again');

// A price on the beta tier is the same class of mismatch.
$db->run('UPDATE subscription_tiers SET monthly_fee_minor_units = 100 WHERE subscription_tier_id = ?', [$tierId]);
$priced = $beta->ensureTier();
Assert::true($priced->isRefusal(), 'a priced beta tier is refused');
Assert::true(
    in_array('monthly_fee_minor_units', $priced->detail['mismatched'] ?? [], true),
    'naming the price as the difference',
);
$db->run('UPDATE subscription_tiers SET monthly_fee_minor_units = 0 WHERE subscription_tier_id = ?', [$tierId]);

// A retired tier grants nothing, so reusing one silently would produce a tenant that appears
// subscribed and is denied everywhere.
$db->run("UPDATE subscription_tiers SET status = 'RETIRED' WHERE subscription_tier_id = ?", [$tierId]);
$retired = $beta->ensureTier();
Assert::true($retired->isRefusal(), 'a retired tier under this key is refused rather than reactivated');
Assert::true(in_array('status', $retired->detail['mismatched'] ?? [], true), 'naming the status');
$db->run("UPDATE subscription_tiers SET status = 'ACTIVE' WHERE subscription_tier_id = ?", [$tierId]);

// ============================================================ subscribing a tenant

$subscribed = $beta->subscribeTenant($scope['tenant_id']);
Assert::true($subscribed->isOk(), 'the tenant is subscribed');
Assert::same('TRIAL', $subscribed->value()['status'] ?? null, 'and the result reports the status it was created with');
Assert::same(
    'TRIAL',
    (string) $db->scalar(
        'SELECT status FROM tenant_subscriptions WHERE tenant_subscription_id = ?',
        [(string) $subscribed->value()['tenant_subscription_id']]
    ),
    'as a TRIAL — a closed beta is a trial in the schema\'s own vocabulary',
);

// The gate actually opens, for exactly the six and nothing else.
foreach (BetaBundle::PERMISSIONS as $permission) {
    Assert::true(
        $entitlements->tenantTierGrants($scope['tenant_id'], $permission),
        "the tier grants {$permission}",
    );
}
foreach (['decision.read', 'outreach.execute', 'crm.send', 'customer_catalogue.upload'] as $notGranted) {
    Assert::false(
        $entitlements->tenantTierGrants($scope['tenant_id'], $notGranted),
        "and grants no {$notGranted}",
    );
}

// Idempotent: re-running an onboarding command must converge.
$resubscribed = $beta->subscribeTenant($scope['tenant_id']);
Assert::true($resubscribed->isOk(), 'subscribing again converges');
Assert::same(false, $resubscribed->value()['created'] ?? null, 'without creating a second subscription');

// THE REPORTED STATUS IS THE ROW'S OWN, not the constant a new subscription would carry. A tenant
// whose beta trial has been moved to ACTIVE is still correctly on the beta tier, and answering
// TRIAL for it would tell an operator something untrue on the run least likely to be checked.
$db->run(
    "UPDATE tenant_subscriptions SET status = 'ACTIVE' WHERE tenant_id = ? AND status = 'TRIAL'",
    [$scope['tenant_id']]
);
$converged = $beta->subscribeTenant($scope['tenant_id']);
Assert::true($converged->isOk(), 'an ACTIVE beta subscription still converges');
Assert::same(
    'ACTIVE',
    $converged->value()['status'] ?? null,
    'and the report states the status the row actually has',
);
$db->run(
    "UPDATE tenant_subscriptions SET status = 'TRIAL' WHERE tenant_id = ? AND status = 'ACTIVE'",
    [$scope['tenant_id']]
);
Assert::same(
    1,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM tenant_subscriptions WHERE tenant_id = ? AND status IN ('TRIAL', 'ACTIVE')",
        [$scope['tenant_id']]
    ),
    'the one-live-subscription rule still holds',
);

// ============================================================ the one-live-subscription rule

// A tenant already on a DIFFERENT live tier. The bundle must not work around the rule that made
// `Entitlements::subscribe` refuse; it must fail closed and say what would change it.
$other = Seed::tenantWorkspace($db, $clock, 'rival');
$otherTier = $entitlements->defineTier('SOME_OTHER_TIER', 'Other', 1000, ['battlecard.read']);
Assert::true($otherTier->isOk(), 'a second commercial tier exists');
$otherTierId = (string) $otherTier->value()['subscription_tier_id'];
Assert::true(
    $entitlements->subscribe($other['tenant_id'], $otherTierId, 'ACTIVE')->isOk(),
    'and the rival tenant is on it',
);

$blocked = $beta->subscribeTenant($other['tenant_id']);
Assert::true($blocked->isRefusal(), 'a tenant on a different live tier is not silently moved onto the beta');
Assert::same('TENANT_ALREADY_SUBSCRIBED', $blocked->reasonCode(), 'and the refusal names the existing subscription');
Assert::same(
    $otherTierId,
    (string) $db->scalar(
        "SELECT subscription_tier_id FROM tenant_subscriptions
         WHERE tenant_id = ? AND status IN ('TRIAL', 'ACTIVE')",
        [$other['tenant_id']]
    ),
    'the existing subscription is untouched',
);

// The deliberate replacement, which an operator has to ask for by name.
$replaced = $beta->subscribeTenant($other['tenant_id'], true);
Assert::true($replaced->isOk(), 'an explicit replacement is permitted');
Assert::same(
    'CANCELLED',
    (string) $db->scalar(
        'SELECT status FROM tenant_subscriptions WHERE tenant_subscription_id = ?',
        [(string) $replaced->value()['replaced_tenant_subscription_id']]
    ),
    'and transitions the previous subscription through a state the storage CHECK already admits',
);
Assert::same(
    1,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM tenant_subscriptions WHERE tenant_id = ? AND status IN ('TRIAL', 'ACTIVE')",
        [$other['tenant_id']]
    ),
    'leaving exactly one live subscription, transactionally',
);
Assert::false(
    $entitlements->tenantTierGrants($other['tenant_id'], 'decision.read'),
    'and the replaced tier grants nothing any more',
);

// ============================================================ scope refusals

Assert::same(
    'TENANT_NOT_FOUND',
    $beta->subscribeTenant(Ids::new())->reasonCode(),
    'a tenant that does not exist is refused rather than created',
);

$db->run("UPDATE tenants SET status = 'SUSPENDED' WHERE tenant_id = ?", [$scope['tenant_id']]);
Assert::same(
    'TENANT_NOT_ACTIVE',
    $beta->subscribeTenant($scope['tenant_id'])->reasonCode(),
    'and a suspended tenant is not subscribed onto a beta it cannot use',
);
$db->run("UPDATE tenants SET status = 'ACTIVE' WHERE tenant_id = ?", [$scope['tenant_id']]);

// ============================================================ the entitlement gate only narrows

// The sharpest property of the whole plane: an operator with NO role is denied every one of the six
// even though the tenant's tier grants all of them. The tier is a second gate, never a second
// authority.
$strangerOperator = Seed::operator($db, $clock, $scope['tenant_id'], 'No roles here');
$access = new Allfather\Loopia\Substrate\M02\AccessGuard($db, $clock);
foreach (BetaBundle::PERMISSIONS as $permission) {
    $decision = $access->evaluate(
        'OPERATOR',
        $strangerOperator,
        $scope['tenant_id'],
        $scope['workspace_id'],
        $permission,
        'beta_boundary_probe',
    );
    Assert::notSame(
        'ALLOW',
        $decision['decision'],
        "a paid-for tier grants no access without a role: {$permission}",
    );
}

// And the mirror: the tenant's own describe view is sanitized and states the same thing.
$described = $beta->describeTenant($scope['tenant_id']);
Assert::same(BetaBundle::TIER_KEY, $described['tier_key'], 'the sanitized description names the beta tier');
Assert::equalsList(BetaBundle::PERMISSIONS, $described['tier_permissions'], 'and its exact permissions');
Assert::same(true, $described['live_subscription']['is_beta_tier'] ?? null, 'and reports the live subscription is the beta one');
$encoded = json_encode($described, JSON_THROW_ON_ERROR);
foreach (['password', 'hash', 'token', 'secret'] as $leak) {
    Assert::false(stripos($encoded, $leak) !== false, "the commercial description leaks no {$leak}");
}
