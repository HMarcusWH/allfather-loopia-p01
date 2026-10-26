<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\HistoryReviewBasis;
use Allfather\Loopia\Workflow\HistoricalEvidenceSelector;
use Allfather\Loopia\Workflow\HistoricalEvidenceEligibility;

$root = dirname(__DIR__, 2);
Assert::same('li05.offline-basis-candidate.v1', HistoryReviewBasis::SCHEMA_VERSION, 'LI05A source candidate schema pinned');
Assert::same('li05.offline-selector.v1', HistoricalEvidenceSelector::VERSION, 'deterministic temporal selection version pinned');
Assert::same('li05.offline-disposition.v1', HistoricalEvidenceEligibility::VERSION, 'descriptive non-rights version pinned');
Assert::same(256, HistoricalEvidenceSelector::MAX_INPUT_ROWS, 'source candidate input bounded');
Assert::same(32, HistoricalEvidenceSelector::MAX_REFERENCES, 'frozen ref list bounded');
$files = ['HistoryReviewBasis.php','HistoricalEvidenceSelector.php','HistoricalEvidenceEligibility.php'];
foreach ($files as $file) {
    $contents = (string) file_get_contents($root . '/app/Workflow/' . $file);
    Assert::false(str_contains($contents, 'implements ModelContextAuthority'), "{$file} never registers as model context");
    // Ignore only comments so restrictions apply to executable source.
    $active = implode('', array_map(static function (string|array $token): string {
        return is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ''
            : (is_array($token) ? $token[1] : $token);
    }, token_get_all($contents)));
    foreach (['PDO(', 'curl_exec(', 'file_put_contents(', 'file_get_contents(', 'mail(',
              'SourceAdmission::evaluate(', 'ModelExecutor', 'RunJournal', 'ExecutionAdmission',
              'new \PDO(', 'Db\\Database', 'Provider\\'] as $forbidden) {
        Assert::false(str_contains($active, $forbidden), "{$file} has no execution/network/DB permission surface: {$forbidden}");
    }
}
Assert::false(is_file($root . '/database/migrations/270_history_review_bases.sql'), 'R2 persistence migration remains unapproved');
Assert::false(is_file($root . '/ops/history/preview.php'), 'no operator historical model preview action');
// LI-05C's new context is inert: its dispatch/routing gates always refuse.
Assert::true(is_file($root . '/app/Workflow/HistoryReviewContext.php'),
    'history context exists as a non-dispatchable preview');
$inertHistoryContext=new \Allfather\Loopia\Workflow\HistoryReviewContext();
Assert::same('LI05C_U03_U06_HISTORICAL_MODEL_PURPOSE_NOT_ADOPTED',
    $inertHistoryContext->revalidate([])->reasonCode(),
    'history context cannot admit dispatch-time model use');
Assert::same('LI05C_HISTORICAL_ROUTING_NOT_ADOPTED',
    $inertHistoryContext->validateRoutingPackage([],[],[])->reasonCode(),
    'history context cannot admit routing or output');
$doc = (string) file_get_contents($root . '/docs/LI05_OFFLINE_HISTORY_BASIS_CONTRACT.md');
Assert::contains('U03', $doc, 'owner gate U03 kept explicit');
Assert::contains('U06', $doc, 'owner gate U06 kept explicit');
Assert::contains('NOT_ADMITTED', $doc, 'offline candidate cannot grant authority');
$decisionDir = $root . '/docs/decisions';
$decisions = is_dir($decisionDir) ? glob($decisionDir . '/*.json') : [];
Assert::same(4, count($decisions ?: []), 'all four independently reviewed owner decision files remain');
