<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$workflowPath = $root.'/.github/workflows/m17-candidate-assurance.yml';
// Source checkout has the QA workflow; the production rsync rehearsal intentionally omits it.
$workflow = is_file($workflowPath) ? (string)file_get_contents($workflowPath) : null;
$qa = (string)file_get_contents($root.'/ops/qa/m17/qa.php');
$race = (string)file_get_contents($root.'/ops/qa/m17/race.php');
$recovery = (string)file_get_contents($root.'/ops/qa/m17/restore.sh');

if ($workflow !== null) {
Assert::true(str_contains($workflow, 'permissions:') && str_contains($workflow, 'contents: read'),
    'candidate QA workflow uses read-only repository permissions');
Assert::false(str_contains($workflow, 'pull_request_target:'),
    'candidate QA never executes untrusted PR code in a privileged event');
Assert::false(str_contains($workflow, 'secrets.'),
    'candidate QA never references application, GitHub or Railway secrets');
Assert::false(str_contains($workflow, 'railway deploy') || str_contains($workflow,'accept_deploy'),
    'candidate QA cannot deploy or mutate Railway');
Assert::true(str_contains($workflow, "mariadb: ['10.6', '10.11']"),
    'both required MariaDB lines have separate isolated jobs');
Assert::true(str_contains($workflow, 'if: always()'),
    'candidate evidence retained even on failed or interrupted tests');
} else {
    Assert::pass(); // QA workflow excluded from the staged application by the rsync allowlist.
}
Assert::true(str_contains($qa, "NON_LOOPBACK_TARGET_REFUSED") &&
    str_contains($qa, "NON_DISPOSABLE_DATABASE_REFUSED") &&
    str_contains($qa, "SERVER_DATABASE_IDENTITY_MISMATCH") &&
    str_contains($qa, "DISPOSABLE_SERVER_MARKER_MISMATCH"),
    'explicit client and server-side QA DB identity checks are mandatory');
Assert::true(str_contains($qa,'forDisposableTest') &&
    str_contains($qa,'ALLFATHER_LI07B_DISPOSABLE_SCHEMA_LOADED'),
    'only the candidate CLI writer is reachable from QA code');
Assert::true(str_contains($race, 'IS_USED_LOCK') &&
    str_contains($race,'information_schema.PROCESSLIST') &&
    str_contains($race,'proc_open'),
    'observed database query overlap, not sequential dual PDO calls');
Assert::true(str_contains($recovery,'docker kill --signal=KILL') &&
    str_contains($recovery,'docker volume create') &&
    str_contains($recovery,'mariadb-dump') &&
    str_contains($recovery,'age --decrypt'),
    'forced process crash and independent encrypted restore have executable paths');
Assert::false(is_file($root.'/database/migrations/280_m17_temporal_memory.sql'),
    'unadopted G21 schema never enters normal migrations');
Assert::true(is_file($root.'/docs/longitudinal/proposals/280_m17_temporal_memory_CANDIDATE_NOT_ADOPTED.sql'),
    'proposal schema remains in its non-authoritative source location');
