<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\{HistoryReviewContext,HistoryReviewBasisStore,
    HistoryM17CandidateBridge,HistoryReviewDueStore};
use Allfather\Loopia\Http\ReadAdapters\HistoryReviewReadAdapter;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Core\Clock;

$root=dirname(__DIR__,2);
foreach (['270_history_review_bases.sql','280_m17_temporal_memory.sql',
          '290_history_review_policy.sql'] as $filename) {
    Assert::false(is_file($root.'/database/migrations/'.$filename),
        'unapproved R2 schema cannot auto-deploy: '.$filename);
}
foreach([
  '270_history_review_bases_CANDIDATE_NOT_ADOPTED.sql',
  '280_m17_temporal_memory_CANDIDATE_NOT_ADOPTED.sql',
  '290_history_review_policy_CANDIDATE_NOT_ADOPTED.sql',
] as $file) {
    Assert::true(is_file($root.'/docs/longitudinal/proposals/'.$file),
        'review candidate schema is outside production migrator: '.$file);
}
Assert::same('li05c.disposable-basis-store.v1',HistoryReviewBasisStore::VERSION,
    'LI05C immutable source-basis store version pinned');
Assert::same('li07c.offline-history-m17-bridge.v1',HistoryM17CandidateBridge::VERSION,
    'M17 integration bridge is explicitly offline');
Assert::same('li08c.disposable-review-due-store.v1',HistoryReviewDueStore::VERSION,
    'U05 proposal store is not a scheduler');
Assert::same('li16.r2.inert-temporal-readback.v1',HistoryReviewReadAdapter::VERSION,
    'operator metadata pane is not an HTTP activation');
$fakeDb=(new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
$clock=new Clock();
Assert::same('LI05C_PERSISTENCE_NOT_ADOPTED',
    HistoryReviewBasisStore::forDisposableTest($fakeDb,$clock)->reasonCode(),
    'no runtime or web factory for historical source-basis writes');
Assert::same('LI08C_U05_REVIEW_POLICY_NOT_ADOPTED',
    HistoryReviewDueStore::forDisposableTest($fakeDb,$clock)->reasonCode(),
    'no runtime or web factory for due policy writes');
Assert::same('LI05C_U03_U06_HISTORICAL_MODEL_PURPOSE_NOT_ADOPTED',
    (new HistoryReviewContext())->revalidate([])->reasonCode(),
    'history context cannot authorize a provider call');
Assert::same('LI07C_U03_AND_RUNTIME_GRANTS_NOT_ADOPTED',
    HistoryM17CandidateBridge::activate([])->reasonCode(),
    'basis/M17 bridge cannot activate runtime');
foreach (['HistoryReviewBasisStore','HistoryReviewDueStore','HistoryM17CandidateBridge'] as $class) {
    $file = $root.'/app/Workflow/'.$class.'.php';
    $source=(string)file_get_contents($file);
    foreach (['curl_exec(', 'ModelExecutor', '->send(', 'SourceAdmission::evaluate(',
              'mail(', 'DELETE FROM'] as $forbidden) {
        Assert::false(str_contains($source,$forbidden),
            'LI08 candidates have no network/model/dispatch/deletion: '.$class.' '.$forbidden);
    }
}
$rootController=(string)file_get_contents($root.'/app/Http/Controllers/ConsoleReadController.php');
Assert::false(str_contains($rootController,'HistoryReviewReadAdapter'),
    'R2 readback has no unapproved operator-facing HTTP route');
Assert::false(is_file($root.'/ops/history/due.php'),
    'no cron/CLI task starts a review-due job');
