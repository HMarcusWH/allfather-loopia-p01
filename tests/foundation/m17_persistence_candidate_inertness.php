<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Substrate\M17\MemoryStore;

$root=dirname(__DIR__,2);
Assert::same('li07b.candidate-memory-store.v1',MemoryStore::VERSION,
    'U04 candidate persistence identity explicitly versioned');
Assert::false(is_file($root.'/database/migrations/280_m17_temporal_memory.sql'),
    'canonical G21 migration must not be installed without U04');
$proposal=$root.'/docs/longitudinal/proposals/280_m17_temporal_memory_CANDIDATE_NOT_ADOPTED.sql';
Assert::true(is_file($proposal),'schema candidate is separate from auto-migrator');
$sql=(string)file_get_contents($proposal);
Assert::same(7,preg_match_all('/CREATE TABLE IF NOT EXISTS temporal_|CREATE TABLE IF NOT EXISTS compression_lineage_links/i',$sql),
    'candidate schema has seven M17 families');
Assert::same(14,preg_match_all('/CREATE TRIGGER m17_[a-z_]+_no_(?:update|delete)/',$sql),
    'append-only UPDATE and DELETE triggers for each table');
Assert::false(str_contains($sql,'DROP TABLE'),'candidate migration never deletes a table');
Assert::contains('NOT A MIGRATION',$sql,'schema installation explicitly prohibited without U04');
Assert::contains('U04 has NOT been ratified',$sql,'owner adoption status is not fabricated');
$source=(string)file_get_contents($root.'/app/Substrate/M17/MemoryStore.php');
Assert::contains('ALLFATHER_LI07B_DISPOSABLE_SCHEMA_LOADED',$source,
    'writer only constructible in disposable-test harness');
Assert::contains('private function __construct',$source,
    'no general runtime MemoryStore constructor');
foreach (['curl_exec(', 'file_get_contents(', 'ModelExecutor', 'M16\\',
          'Provider\\', 'SourceAdmission::evaluate(', '->send(', 'DELETE FROM'] as $forbidden) {
    Assert::false(str_contains($source,$forbidden),'candidate store cannot fetch, model, send or delete: '.$forbidden);
}
Assert::same(4,count(glob($root.'/docs/decisions/*.json')?:[]),
    'existing four independent owner decision records remain');
$doc=(string)file_get_contents($root.'/docs/LI07B_M17_PERSISTENCE_CANDIDATE.md');
foreach(['U03','U04','U06','NOT_ADMITTED','NOT_DEPLOYED','TEST_ONLY'] as $marker)
    Assert::contains($marker,$doc,'gates and limitations documented: '.$marker);
