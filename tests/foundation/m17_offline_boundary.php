<?php

declare(strict_types=1);

use Allfather\Loopia\Substrate\M17\Vocabulary;
use Allfather\Loopia\Substrate\M17\WindowEngine;
use Allfather\Loopia\Substrate\M17\ProfileResolver;
use Allfather\Loopia\Substrate\M17\DynamicMeasures;
use Allfather\Loopia\Substrate\M17\InputSet;
use Allfather\Loopia\Tests\Assert;

$root=dirname(__DIR__,2);
Assert::same(['SOURCE_DIFF','COMPANY_SIGNAL'],Vocabulary::SUPPORTED_CLASSES,
    'LI06A only supports the P01 metadata classes described in handover');
Assert::same(['DAY','WEEK','MONTH'],Vocabulary::WINDOW_KINDS,'no M13 fiscal or event windows activated');
Assert::same('li06a.offline-window-engine.v1',WindowEngine::VERSION,'temporal candidate version pinned');
Assert::same('li06a.offline-profile-resolver.v1',ProfileResolver::VERSION,'profile candidate version pinned');
Assert::same('li06a.offline-dynamic-measures.v1',DynamicMeasures::VERSION,'integer measures version pinned');
Assert::same('li06a.offline-input-set.v1',InputSet::VERSION,'input identity version pinned');
foreach (['Vocabulary','WindowEngine','ProfileResolver','DynamicMeasures','InputSet'] as $cls) {
    $contents=(string)file_get_contents($root.'/app/Substrate/M17/'.$cls.'.php');
    $active=implode('',array_map(static function (string|array $token):string {
        return is_array($token)&&in_array($token[0],[T_COMMENT,T_DOC_COMMENT],true)?'':(is_array($token)?$token[1]:$token);
    },token_get_all($contents)));
    foreach (['new \PDO(', 'Db\\Database', 'Provider\\', 'curl_exec(', 'file_put_contents(',
        'file_get_contents(', 'mail(', 'proc_open(', 'exec(', 'ModelExecutor',
        'ModelContextAuthority', 'SourceAdmission::evaluate('] as $forbidden) {
        Assert::false(str_contains($active,$forbidden),$cls.' is a pure, non-executing candidate: '.$forbidden);
    }
}
Assert::false(is_file($root.'/database/migrations/280_m17_temporal_compression.sql'),
    'LI06A does not implement M17 G21 tables');
Assert::false(is_file($root.'/ops/m17/run.php'),'LI06A cannot initiate compression jobs');
Assert::true(is_file($root.'/app/Substrate/M17/MemoryStore.php'),
    'LI07B review candidate exists but is not wired or installed');
Assert::false(is_file($root.'/database/migrations/280_m17_temporal_memory.sql'),
    'unratified U04 cannot install G21 as a runtime migration');
Assert::false(is_file($root.'/ops/temporal/run.php'),
    'review candidate does not introduce an executable scheduled memory command');
$doc=(string)file_get_contents($root.'/docs/M17_PROJECTION_CONTRACT.md');
Assert::contains('U04',$doc,'canonical U04 remains pending');
Assert::contains('NOT_ADMITTED',$doc,'code docs explicitly deny execution and source-use authority');
Assert::contains('0.system',$doc,'system-zone tzdb caveat is visible');
Assert::contains('No migration',$doc,'no unreviewed schema deployed');
