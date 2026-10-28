<?php

declare(strict_types=1);

use Allfather\Loopia\Ops\BuildFirstReceipt;
use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$receipt = BuildFirstReceipt::compose($root, 'BF-01');
Assert::same('SOURCE_ONLY_BUILD_READBACK', $receipt['kind'], 'receipt cannot present as independent CI');
Assert::same('BF-01', $receipt['package_id'], 'receipt keeps requested package identity');
Assert::same(false, $receipt['source']['git_identity_verified'], 'caller SHA is not independently verified');
Assert::same(10, count($receipt['source']['measured_files']), 'allowlist hashes ten reviewed sources');
Assert::same(64, $receipt['interface_contracts']['indexed'], 'all planned cross-package definitions accounted for');
Assert::same('NOT_RUN_DEFERRED_V01_V11', $receipt['qualification']['full_system'], 'full system QA is deferred honestly');
Assert::same(0, $receipt['authority']['new_execution_grants'], 'receipt makes no grant');
Assert::same(0, $receipt['authority']['migrations_installed'], 'receipt installs no SQL');
Assert::same('NOT_QUERIED', $receipt['authority']['existing_runtime_admissions'], 'source cannot claim live grants absent host evidence');
$declared = str_repeat('a', 40);
$receipt2 = BuildFirstReceipt::compose($root, 'BF-01', $declared);
Assert::same($declared, $receipt2['source']['declared_git_sha'], 'declared SHA retained without false independent verification');
Assert::throws(static fn () => BuildFirstReceipt::compose($root, 'BF-99'), \DomainException::class, 'unknown package refused');
Assert::throws(static fn () => BuildFirstReceipt::compose($root, 'BF-01', 'not-a-sha'), \DomainException::class, 'wrong SHA format refused');
