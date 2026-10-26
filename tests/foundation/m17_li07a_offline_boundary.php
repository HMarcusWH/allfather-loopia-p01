<?php

declare(strict_types=1);

use Allfather\Loopia\Substrate\M17\{Lineage,RollupEngine,Replay};
use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
Assert::same('li07a.offline-rollup.v1',RollupEngine::VERSION,'deterministic rollup candidate pin');
Assert::same('li07a.offline-lineage.v1',Lineage::VERSION,'full metadata lineage candidate pin');
Assert::same('li07a.offline-replay.v1',Replay::VERSION,'read-only metadata replay candidate pin');
foreach (['RollupEngine.php','Lineage.php','Replay.php'] as $name) {
    $path=$root.'/app/Substrate/M17/'.$name;
    $source=(string)file_get_contents($path);
    Assert::true($source!=='','all LI07A candidate source files are present');
    $active=implode('',array_map(static function (string|array $token): string {
        if (is_array($token)&&in_array($token[0],[T_COMMENT,T_DOC_COMMENT],true))return '';
        return is_array($token)?$token[1]:$token;
    },token_get_all($source)));
    foreach (['new \\PDO(', 'mysqli_', 'curl_exec(', 'file_get_contents(', 'file_put_contents(',
              'fopen(', 'unlink(', 'shell_exec(', 'proc_open(', 'ModelExecutor',
              'SourceAdmission::evaluate(', 'new \\Allfather\\Loopia\\Db\\Database(',
              'implements ModelContextAuthority', 'new \\ReflectionClass('] as $forbidden) {
        Assert::false(str_contains($active,$forbidden),$name.' has no DB, provider, side effect or owner access: '.$forbidden);
    }
}
// LI07A pure functions remain unchanged; LI07B's standalone schema/store candidates may
// exist in a draft without granting any runtime authority or production migration.
foreach (['database/migrations/280_m17_temporal_memory.sql',
          'ops/temporal/run.php', 'ops/temporal/replay.php'] as $forbidden) {
    Assert::false(is_file($root.'/'.$forbidden),'LI07B runtime/persistence remains absent: '.$forbidden);
}
$doc=(string)file_get_contents($root.'/docs/LI07A_OFFLINE_ROLLUP_LINEAGE_REPLAY.md');
Assert::contains('U04',$doc,'successor uniqueness is still upstream-owned and pending');
Assert::contains('U03',$doc,'historical source-use authority remains pending');
Assert::contains('NOT_ADMITTED',$doc,'all outcomes have explicit offline authority ceiling');
Assert::contains('NOT_REPLAYABLE',$doc,'unavailable input reported rather than invented replay');
$dir=$root.'/docs/decisions';
$records=is_dir($dir)?glob($dir.'/*.json'):[];
Assert::same(4,count($records ?: []),'all four previously reviewed owner decision JSONs retained');
