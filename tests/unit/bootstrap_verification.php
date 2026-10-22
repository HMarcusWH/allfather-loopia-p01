<?php

declare(strict_types=1);

use Allfather\Loopia\Ops\BootstrapVerification as V;
use Allfather\Loopia\Tests\Assert;

$modules = ['blocked' => [], 'modules' => []];
foreach (['P01','P02','P03','P04','P05'] as $key) {
    $modules['modules'][$key] = ['action' => 'REUSE'];
}
Assert::true(V::modulesComplete($modules), 'all five existing modules complete');
$pending = $modules;
$pending['modules']['P03'] = ['action' => 'CREATE'];
Assert::false(V::modulesComplete($pending), 'a missing P03 registration is NOT verified');
$pending['modules']['P03'] = ['action' => 'BLOCK'];
Assert::false(V::modulesComplete($pending), 'a blocked registration is NOT verified');
$pending = $modules;
unset($pending['modules']['P04']);
Assert::false(V::modulesComplete($pending), 'a missing module key is NOT verified');
$pending = $modules;
$pending['blocked'] = ['P02'];
Assert::false(V::modulesComplete($pending), 'blocked module list refuses verification');

$login = ['blocked_by' => [], 'would_create_login' => false,
    'would_create_trial' => false, 'existing_login_id' => 'existing-user'];
Assert::true(V::loginComplete($login), 'bound existing account and trial complete');
$pending = $login;
$pending['would_create_login'] = true;
Assert::false(V::loginComplete($pending), 'missing account cannot verify');
$pending = $login;
$pending['would_create_trial'] = true;
Assert::false(V::loginComplete($pending), 'missing beta trial cannot verify');
$pending = $login;
$pending['existing_login_id'] = null;
Assert::false(V::loginComplete($pending), 'login identifier must actually exist');
$pending = $login;
$pending['blocked_by'] = ['NONACTIVE_ROLE'];
Assert::false(V::loginComplete($pending), 'role refusal cannot verify');

$customer = [
    'blocked_by' => [], 'sections' => ['company','icp','competitors','catalogue'],
    'intended' => [
        'company' => ['action' => 'REUSE'],
        'icp' => ['action' => 'REUSE'],
        'competitors' => ['a' => ['action' => 'REUSE'], 'b' => ['action' => 'REUSE']],
        'catalogue' => ['action' => 'REUSE', 'products' => [
            'a' => ['action' => 'REUSE'], 'b' => ['action' => 'REUSE'],
        ]],
    ],
];
Assert::true(V::customerComplete($customer), 'all reviewed business records complete');
$pending = $customer;
$pending['intended']['company']['action'] = 'APPEND_VERSION';
Assert::false(V::customerComplete($pending), 'pending company version cannot verify');
$pending = $customer;
$pending['intended']['competitors']['a']['action'] = 'CREATE_DRAFT';
Assert::false(V::customerComplete($pending), 'pending competitor cannot verify');
$pending = $customer;
$pending['intended']['catalogue']['products']['b']['action'] = 'CREATE';
Assert::false(V::customerComplete($pending), 'pending offer cannot verify');
$pending = $customer;
$pending['intended']['catalogue']['products']['b']['action'] = 'BLOCK';
Assert::false(V::customerComplete($pending), 'blocked offer cannot verify');
$pending = $customer;
$pending['intended']['catalogue']['products'] = [];
Assert::false(V::customerComplete($pending), 'empty catalogue cannot verify');
$pending = $customer;
$pending['blocked_by'] = ['permission:customer_catalogue.upload'];
Assert::false(V::customerComplete($pending), 'blocked permission cannot verify');
$pending = $customer;
$pending['sections'] = ['unknown'];
Assert::false(V::customerComplete($pending), 'unknown section cannot verify');
