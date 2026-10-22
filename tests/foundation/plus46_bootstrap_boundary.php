<?php

declare(strict_types=1);

/** Source-boundary regression guard. Runtime DB integration MUST also be qualified separately. */
use Allfather\Loopia\Tests\Assert;

$root=dirname(__DIR__,2);
$paths=['complete-existing-test-modules.php','bootstrap-test-customer-data.php','bind-existing-test-operator.php'];
foreach ($paths as $path) {
    $code=(string) file_get_contents($root.'/ops/'.$path);
    Assert::contains("PHP_SAPI !== 'cli'",$code, $path.' is never web executable');
    Assert::contains('BetaCli::parseArgs',$code,$path.' has closed CLI argument vocabulary');
    Assert::contains('BetaCli::configuration',$code,$path.' uses private runtime configuration');
    Assert::true(str_contains(str_replace(' ' ,'',$code),"['testing','staging']")
        || str_contains(str_replace(' ' ,'',$code),"['staging','testing']"), $path.' refuses production runtime');
    Assert::contains('FOR UPDATE',$code,$path.' re-checks a locked workspace before writing');
    Assert::contains('hash_equals',$code,$path.' binds operator approval to an exact plan digest');
    Assert::contains('BetaCli::emit',$code,$path.' emits sanitized results');
    Assert::false(str_contains($code,'DB_PASSWORD'),$path.' does not print or handle a DB password');
    Assert::false(str_contains($code,'--password='),$path.' does not accept password argv');
    Assert::false(str_contains($code,'/public_html/uploads'),$path.' has no public staging upload path');
}
$modules=(string) file_get_contents($root.'/ops/complete-existing-test-modules.php');
foreach (['P01Registration','P02Registration','P03Registration','P04Registration','P05Registration'] as $owner) {
    Assert::contains('new '.$owner.'(',$modules,'module registration uses '.$owner.' rather than raw SQL');
}
Assert::contains("'action'=>'CREATE'",$modules,'module creation has explicit plan state');
Assert::contains("'action'=>'REUSE'",$modules,'module replay reuses compatible active registrations');
Assert::contains("'action'=>'BLOCK'",$modules,'incompatible registrations are not duplicated');
Assert::contains('roles_granted', $modules,'module command reports no role assignment');
Assert::contains('subscription_changed',$modules,'module command reports no subscription change');
$data=(string) file_get_contents($root.'/ops/bootstrap-test-customer-data.php');
foreach (['registerTwin(','appendVersion(','declareVersion(','registerCompetitor(','registerCatalogue(','addProduct('] as $call) {
    Assert::contains($call,$data,'business command uses owner method '.$call);
}
Assert::contains("'DRAFT'",$data,'competitor nominees do not pretend to be active monitoring');
Assert::contains("'INTERNAL_DRAFT'",$data,'entity version does not pretend to be released');
Assert::contains('APPROVED_TENANT_OWNED_CARD_REQUIRED',$data,'product catalogue needs reviewed licence');
Assert::contains('EVIDENCE_SCOPE_OR_HASH_MISMATCH',$data,'imported M05 evidence is scope/hash bound');
Assert::false(str_contains($data,'released_briefings'), 'bootstrap does not fabricate released P01 intelligence');
Assert::false(str_contains($data,'marketing_asset_drafts'), 'bootstrap does not fabricate P05 releases');
Assert::false(str_contains($data,'monitor_execution_grants'), 'bootstrap does not mint execution grants');
$login=(string) file_get_contents($root.'/ops/bind-existing-test-operator.php');
Assert::contains('createLogin($operator',$login,'login binds existing operator id');
Assert::false(str_contains($login,'newOperator('),'login does not create a governed operator');
Assert::contains('readPassword(true',$login,'password from stdin only');
Assert::contains('ROLE_TENANT_ADMIN',$login,'login checks exact approved existing roles');
Assert::contains('ROLE_WORKSPACE_OPERATOR',$login,'login checks exact approved existing roles');
Assert::contains('must_change_password',$login,'login retains mandatory password-change posture');

// A READ-ONLY verify result must not reuse the plan's success exit status.
// The runtime predicates are unit-tested separately; source assertions keep each private CLI wired.
foreach ([
    'complete-existing-test-modules.php' => 'modulesComplete($before)',
    'bootstrap-test-customer-data.php' => 'customerComplete($before)',
    'bind-existing-test-operator.php' => 'loginComplete($before)',
] as $path => $predicate) {
    $code=(string)file_get_contents($root.'/ops/'.$path);
    Assert::contains($predicate,$code,$path.' checks full replay completion before success');
    Assert::contains("?'VERIFIED':'INCOMPLETE'",$code,$path.' exposes an explicit verification verdict');
    Assert::contains('$complete?0:5',$code,$path.' exits nonzero on pending/blocked verification');
}
Assert::contains('contract_valid_until',$modules,'registration replay rejects expired capability contracts');
Assert::contains('c.valid_from',$modules,'registration replay checks contract effective time');
Assert::contains('$clock->now()',$modules,'registration authority clock refreshed for locked plan');
Assert::contains('review_expires_at',$data,'catalogue review age is re-checked');
Assert::contains('array_diff($actualKeys,$expectedKeys)',$data,'unknown active offer is not silently reused');
Assert::contains("'manifest_hash'=>$manifestHash",$data,'digest is bound to already validated bytes');
Assert::false(str_contains($data,"file_get_contents(".'$args'."['manifest'])"),
    'the parsed manifest path is never reopened during approval digest');
Assert::contains("r.status='ACTIVE'",$login,'binding rejects superseded role definitions');
Assert::contains("m.status='ACTIVE'",$login,'binding ignores historical inactive memberships');
