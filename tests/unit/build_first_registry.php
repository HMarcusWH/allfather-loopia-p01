<?php

declare(strict_types=1);

use Allfather\Loopia\Ops\BuildFirstRegistry;
use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$data = BuildFirstRegistry::loadAndValidate($root);
$d = $data['decisions'];
$c = $data['capabilities'];
$s = $data['schema'];
$lock = json_decode((string) file_get_contents($root . '/upstream.lock.json'), true, 64, JSON_THROW_ON_ERROR);

Assert::throws(static function () use ($d, $c, $s, $lock): void {
    $bad = $d; $bad['decisions'][0]['execution_authorized'] = true;
    BuildFirstRegistry::validate($bad, $c, $s, $lock);
}, \DomainException::class, 'decision matrix cannot grant execution');
Assert::throws(static function () use ($d, $c, $s, $lock): void {
    $bad = $d; $bad['decisions'][2]['independent_approvals']['M05/source'] = 'APPROVED';
    BuildFirstRegistry::validate($bad, $c, $s, $lock);
}, \DomainException::class, 'incomplete U03 ratification cannot be promoted');
Assert::throws(static function () use ($d, $c, $s, $lock): void {
    $bad = $c; $bad['live_factory_bindings'] = ['MANUAL_HISTORICAL_ANALYSIS'];
    BuildFirstRegistry::validate($d, $bad, $s, $lock);
}, \DomainException::class, 'metadata cannot wire native factories');
Assert::throws(static function () use ($d, $c, $s, $lock): void {
    $bad = $c; $bad['build_packages'][1]['depends_on'] = ['RT-02'];
    BuildFirstRegistry::validate($d, $bad, $s, $lock);
}, \DomainException::class, 'dependency graph cannot point backward from wave 1 to 15');
Assert::throws(static function () use ($d, $c, $s, $lock): void {
    $bad = $s; $bad['any_live_migrations_applied'] = true;
    BuildFirstRegistry::validate($d, $c, $bad, $lock);
}, \DomainException::class, 'schema proposal cannot install itself');
Assert::throws(static function () use ($d, $c, $s, $lock): void {
    $bad = $s; $bad['families'][1]['tables'][0]['name'] = $bad['families'][0]['tables'][0]['name'];
    BuildFirstRegistry::validate($d, $c, $bad, $lock);
}, \DomainException::class, 'duplicate table name across schema families refused');
Assert::throws(static function () use ($d, $c, $s, $lock): void {
    $bad = $s; $bad['families'][0]['tables'][0]['foreign_key_intents'][0]['columns'] = [];
    BuildFirstRegistry::validate($d, $c, $bad, $lock);
}, \DomainException::class, 'missing proposed FK column blocks schema registry');
Assert::throws(static function () use ($d, $c, $s, $lock): void {
    $bad = $s; $bad['families'][0]['tables'][0]['indexes'][0]['candidate_columns'] = ['invented_column'];
    BuildFirstRegistry::validate($d, $c, $bad, $lock);
}, \DomainException::class, 'index must reference a declared candidate column');
Assert::throws(static function () use ($d, $c, $s, $lock): void {
    $bad = $s; $bad['families'][0]['tables'][0]['new_raw_source_bytes'] = true;
    BuildFirstRegistry::validate($d, $c, $bad, $lock);
}, \DomainException::class, 'raw source bytes cannot migrate into second evidence store');
Assert::throws(static function () use ($d, $c, $s, $lock): void {
    $bad = $c; $bad['source_snapshot']['base_tree'] = str_repeat('0', 40);
    BuildFirstRegistry::validate($d, $bad, $s, $lock);
}, \DomainException::class, 'source identity drift fails closed');
Assert::throws(static function () use ($d, $c, $s, $lock): void {
    $bad = $lock; $bad['claim_boundary']['production_launch_authorized'] = true;
    BuildFirstRegistry::validate($d, $c, $s, $bad);
}, \DomainException::class, 'production GO cannot be inferred from registry');
