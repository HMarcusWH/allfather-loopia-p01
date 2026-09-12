<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$prepare = (string) file_get_contents($root . '/ops/deploy/prepare-release.sh');
$excludes = (string) file_get_contents($root . '/ops/deploy/staging-rsync-excludes.txt');

Assert::contains('git archive "$release"', $prepare, 'release preparation exports the exact Git object, not the developer working tree');
Assert::contains('npm ci', $prepare, 'the prepared tree installs the pinned frontend dependency graph');
Assert::contains('npm test', $prepare, 'the prepared tree runs frontend tests before staging');
Assert::contains('npm run build', $prepare, 'the prepared tree builds the production console');
Assert::contains('npm run stage', $prepare, 'the prepared tree stages the reviewed manifest-last console payload');
Assert::contains('actual_node="${actual_node#v}"', $prepare, 'node --version is normalized to the bare .node-version value before comparison');
Assert::false(str_contains($prepare, 'rsync '), 'release preparation cannot deploy anything');
Assert::false(str_contains($prepare, 'ssh '), 'release preparation cannot reach the host');

Assert::contains('/storage/***', $excludes, 'the destructive root rsync never manages runtime storage');
Assert::contains('/public_html/assets/operator-console/***', $excludes, 'the root rsync never deletes manifest-last console assets');
Assert::contains('/.env', $excludes, 'host configuration is never copied from a release tree');
Assert::contains('/frontend/console/node_modules/***', $excludes, 'private frontend dependencies are never deployed');
Assert::contains('/frontend/console/dist/***', $excludes, 'private frontend build output is never deployed');
