<?php

declare(strict_types=1);

use Allfather\Loopia\Ops\BuildFirstRegistry;
use Allfather\Loopia\Tests\Assert;

$data = BuildFirstRegistry::loadAndValidate(dirname(__DIR__, 2));
Assert::same(10, count($data['decisions']['decisions']), 'U01-U10 decisions are fully enumerated');
Assert::same(29, count($data['capabilities']['build_packages']), '29 package graph captured');
Assert::same(12, count($data['schema']['families']), '12 proposed schema families captured');
Assert::same(false, $data['schema']['any_live_migrations_applied'], 'no candidate schema installed');
Assert::same([], $data['capabilities']['live_factory_bindings'], 'no candidate live factory wiring');
Assert::same('NOT_QUERIED', $data['capabilities']['runtime_grant_state'], 'source does not invent runtime grant evidence');
Assert::same(['A','B','C'], $data['capabilities']['existing_runtime_ceiling'], 'A/B/C ceiling retained');
Assert::equalsList(BuildFirstRegistry::FAMILIES, array_column($data['schema']['families'], 'label'), 'schema family labels are complete');
