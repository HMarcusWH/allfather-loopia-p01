<?php

declare(strict_types=1);

/**
 * M02 access evaluation, proved against real MariaDB.
 *
 * The default-deny rule (M02-DEC-008) is the whole point: absence of a matching active permission
 * and scope is never permission. These tests exercise each refusal in the canonical order, because
 * the order determines which reason code and which severity a caller gets — and an excluded
 * permission must be caught BEFORE any role lookup, so that requesting one is an S5 event even for
 * a principal that holds nothing.
 */

use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M02\Permissions;
use Allfather\Loopia\Substrate\M02\Seed as M02Seed;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());

    return;
}

$clock = Harness::clock();
$vocab = new M02Seed($db, $clock);
$seeded = $vocab->ensureVocabulary();
Assert::true($seeded['permissions'] > 50, 'the permission vocabulary seeds');
Assert::same(15, $seeded['roles'], 'all 15 canonical roles seed, including the narrow scheduler observer seat');

// Idempotent: a redeploy must not duplicate the vocabulary.
$again = $vocab->ensureVocabulary();
Assert::same(['permissions' => 0, 'roles' => 0], $again, 'seeding twice is a no-op');

$acme = Seed::tenantWorkspace($db, $clock, 'acme');
$rival = Seed::tenantWorkspace($db, $clock, 'rival');
$guard = new AccessGuard($db, $clock);

$monitorRole = $vocab->roleIdFor('ROLE_COMPETITION_MONITOR');
Seed::membership($db, $clock, $acme['tenant_id'], $acme['workspace_id'], $acme['operator_id'], (string) $monitorRole);

// --- the happy path -------------------------------------------------------------------------------

$allowed = $guard->evaluate(
    'OPERATOR', $acme['operator_id'], $acme['tenant_id'], $acme['workspace_id'],
    'competition_monitoring.read', 'COMPETITOR'
);
Assert::same('ALLOW', $allowed['decision'], 'a held permission in scope is allowed');
Assert::equalsList(['GRANTED'], $allowed['reason_codes'], 'and carries the GRANTED reason code');

// --- THE excluded-permission hard deny ---------------------------------------------------------------
// Checked BEFORE any role or scope evaluation, so even a tenant admin gets S5 + incident.

$adminRole = $vocab->roleIdFor('ROLE_TENANT_ADMIN');
$adminOperator = Seed::operator($db, $clock, $acme['tenant_id'], 'Admin');
Seed::membership($db, $clock, $acme['tenant_id'], $acme['workspace_id'], $adminOperator, (string) $adminRole);

foreach (Permissions::EXCLUDED as $excluded) {
    $refused = $guard->evaluate(
        'OPERATOR', $adminOperator, $acme['tenant_id'], $acme['workspace_id'], $excluded, 'ANY'
    );
    Assert::same('DENY', $refused['decision'], "excluded permission is hard-denied: {$excluded}");
    Assert::equalsList(
        ['EXCLUDED_PERMISSION_REQUESTED'],
        $refused['reason_codes'],
        "and by the canonical code: {$excluded}",
    );
    Assert::same('S5', $refused['severity'], "at release-blocking severity: {$excluded}");
    Assert::same(true, $refused['incident_required'], "and opens an incident: {$excluded}");
}

// The exclusions that carry the projection's action ceiling, named explicitly so a reader sees
// that "cannot send / publish / trade / score an employee" is an authority-model property.
foreach ([
    'crm.send', 'external_message.approve_or_send', 'campaign.modify', 'public_publish',
    'trade.propose_or_execute', 'employee_profile.productivity_score',
    'employee_profile.loyalty_score', 'stakeholder_profile.psychological_inference',
    'ledger.modify_history', 'integrity.override',
] as $ceiling) {
    Assert::true(Permissions::isExcluded($ceiling), "the action ceiling excludes {$ceiling}");
}

// The scheduler service role is a deliberately narrow canonical seat, never a Workspace Operator
// synonym. Keep its exact four-permission surface pinned in the projection.
$schedulerRole = $vocab->roleIdFor('ROLE_SYSTEM_SCHEDULER_OBSERVER');
Assert::true($schedulerRole !== null, 'the scheduler observer role is seeded');
$schedulerPermissions = array_map(
    static fn (array $row): string => (string) $row['permission_key'],
    $db->all('SELECT permission_key FROM role_permissions WHERE role_id = ? ORDER BY permission_key', [$schedulerRole])
);
$expectedSchedulerPermissions = ['evidence.read', 'mission.read', 'mission.transition', 'tool.invoke'];
Assert::same($expectedSchedulerPermissions, $schedulerPermissions, 'scheduler observer grants exactly the four canonical observation permissions');

// No seeded role may grant an excluded permission. If one did, the whole exclusion list would be
// decoration.
foreach (Permissions::allRolePermissions() as $granted) {
    Assert::true(!Permissions::isExcluded($granted), "no role grants the excluded permission {$granted}");
}

// --- default-deny: a permission nobody holds ----------------------------------------------------------

$notGranted = $guard->evaluate(
    'OPERATOR', $acme['operator_id'], $acme['tenant_id'], $acme['workspace_id'],
    'outreach.execute', 'OUTREACH'
);
Assert::same('DENY', $notGranted['decision'], 'a permission the role does not grant is denied');
Assert::equalsList(['PERMISSION_NOT_GRANTED'], $notGranted['reason_codes'], 'refused by name');

// --- THE cross-tenant scope breach ---------------------------------------------------------------------

$crossTenant = $guard->evaluate(
    'OPERATOR', $acme['operator_id'], $rival['tenant_id'], $rival['workspace_id'],
    'competition_monitoring.read', 'COMPETITOR'
);
Assert::same('DENY', $crossTenant['decision'], 'an operator cannot act in another tenant');
Assert::equalsList(['TENANT_SCOPE_MISMATCH'], $crossTenant['reason_codes'], 'refused by name');
Assert::same('S4', $crossTenant['severity'], 'a cross-tenant principal is a scope BREACH, not a routine miss');
Assert::same(true, $crossTenant['incident_required'], 'and opens an incident');

// --- cross-workspace inside the right tenant -------------------------------------------------------------

$otherWorkspace = Seed::tenantWorkspace($db, $clock, 'acme2');
$crossWorkspace = $guard->evaluate(
    'OPERATOR', $acme['operator_id'], $acme['tenant_id'], $otherWorkspace['workspace_id'],
    'competition_monitoring.read', 'COMPETITOR'
);
Assert::same('DENY', $crossWorkspace['decision'], 'a workspace in another tenant is denied');
Assert::equalsList(['WORKSPACE_SCOPE_MISMATCH'], $crossWorkspace['reason_codes'], 'refused by name');

// --- a workspace is REQUIRED ------------------------------------------------------------------------------

$noWorkspace = $guard->evaluate(
    'OPERATOR', $acme['operator_id'], $acme['tenant_id'], null,
    'competition_monitoring.read', 'COMPETITOR'
);
Assert::same('DENY', $noWorkspace['decision'], 'a missing workspace is a refusal, not a wildcard');
Assert::equalsList(['WORKSPACE_REQUIRED'], $noWorkspace['reason_codes'], 'refused by name');

// --- lifecycle: suspension revokes access immediately --------------------------------------------------------

$db->run('UPDATE operators SET status = ? WHERE operator_id = ?', ['SUSPENDED', $acme['operator_id']]);
$suspended = $guard->evaluate(
    'OPERATOR', $acme['operator_id'], $acme['tenant_id'], $acme['workspace_id'],
    'competition_monitoring.read', 'COMPETITOR'
);
Assert::same('DENY', $suspended['decision'], 'a suspended operator loses access at once');
Assert::equalsList(['IDENTITY_NOT_ACTIVE'], $suspended['reason_codes'], 'refused by name');
$db->run('UPDATE operators SET status = ? WHERE operator_id = ?', ['ACTIVE', $acme['operator_id']]);

$db->run('UPDATE workspaces SET status = ? WHERE workspace_id = ?', ['SUSPENDED', $acme['workspace_id']]);
$suspendedWorkspace = $guard->evaluate(
    'OPERATOR', $acme['operator_id'], $acme['tenant_id'], $acme['workspace_id'],
    'competition_monitoring.read', 'COMPETITOR'
);
Assert::equalsList(['WORKSPACE_NOT_ACTIVE'], $suspendedWorkspace['reason_codes'], 'a suspended workspace denies');
$db->run('UPDATE workspaces SET status = ? WHERE workspace_id = ?', ['ACTIVE', $acme['workspace_id']]);

$db->run('UPDATE tenants SET status = ? WHERE tenant_id = ?', ['SUSPENDED', $acme['tenant_id']]);
$suspendedTenant = $guard->evaluate(
    'OPERATOR', $acme['operator_id'], $acme['tenant_id'], $acme['workspace_id'],
    'competition_monitoring.read', 'COMPETITOR'
);
Assert::equalsList(['TENANT_NOT_ACTIVE'], $suspendedTenant['reason_codes'], 'a suspended tenant denies');
$db->run('UPDATE tenants SET status = ? WHERE tenant_id = ?', ['ACTIVE', $acme['tenant_id']]);

// --- membership revocation is the stand-down lever ---------------------------------------------------------------

$db->run(
    'UPDATE workspace_memberships SET status = ? WHERE principal_id = ? AND workspace_id = ?',
    ['REVOKED', $acme['operator_id'], $acme['workspace_id']]
);
$revoked = $guard->evaluate(
    'OPERATOR', $acme['operator_id'], $acme['tenant_id'], $acme['workspace_id'],
    'competition_monitoring.read', 'COMPETITOR'
);
Assert::same('DENY', $revoked['decision'], 'revoking the membership revokes the access');
Assert::equalsList(['ROLE_ASSIGNMENT_NOT_ACTIVE'], $revoked['reason_codes'], 'refused by name');

// --- unmapped identities --------------------------------------------------------------------------------------------

$unmapped = $guard->evaluate(
    'OPERATOR', \Allfather\Loopia\Core\Ids::new(), $acme['tenant_id'], $acme['workspace_id'],
    'competition_monitoring.read', 'COMPETITOR'
);
Assert::equalsList(['IDENTITY_NOT_MAPPED'], $unmapped['reason_codes'], 'an unknown operator is not mapped');

// A VALKYRIE or SYSTEM actor holds no M02 membership and cannot borrow one.
foreach (['VALKYRIE', 'SYSTEM'] as $actorType) {
    $borrowed = $guard->evaluate(
        $actorType, $acme['operator_id'], $acme['tenant_id'], $acme['workspace_id'],
        'competition_monitoring.read', 'COMPETITOR'
    );
    Assert::same('DENY', $borrowed['decision'], "a {$actorType} actor cannot borrow an operator's membership");
}

// --- the audit ledger -------------------------------------------------------------------------------------------------

$denials = (int) $db->scalar('SELECT COUNT(*) FROM access_evaluation_records WHERE decision = ?', ['DENY']);
Assert::true($denials > 20, 'every denial is retained in the append-only audit ledger');

$retainedAllows = (int) $db->scalar('SELECT COUNT(*) FROM access_evaluation_records WHERE decision = ?', ['ALLOW']);
Assert::same(0, $retainedAllows, 'an ALLOW is not retained unless the caller asks — the ledger is for refusals');

$explicit = $guard->evaluate(
    'OPERATOR', $adminOperator, $acme['tenant_id'], $acme['workspace_id'],
    'tenant.administer', 'TENANT', null, null, retainAllowDecision: true
);
Assert::same('ALLOW', $explicit['decision'], 'a tenant admin may administer');
Assert::same(
    1,
    (int) $db->scalar('SELECT COUNT(*) FROM access_evaluation_records WHERE decision = ?', ['ALLOW']),
    'and an explicitly-retained ALLOW IS recorded',
);
