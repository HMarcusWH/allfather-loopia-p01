<?php

declare(strict_types=1);

use Allfather\Loopia\Evaluation\HistoricalEvaluationFreeze;
use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$all = HistoricalEvaluationFreeze::loadAndVerify($root);
$m = $all['manifest'];
Assert::same('RULES_FROZEN_HELDOUT_NOT_FROZEN', $m['status'], 'VA00 rules freeze is not heldout material freeze');
Assert::same('NOT_ACCEPTED', $m['model_quality'], 'VA00 does not qualify model quality');
Assert::same(10, count($m['pinned_source_files']), 'all original source/decision bytes are pinned');
Assert::same(18, count($all['legacy']['cases']), 'original Monitor 18-case truth preserved');
Assert::same(15, count($all['development']['cases']), 'historical development 15-case truth preserved');
Assert::same(6, count($all['heldout']['slots']), 'only six reserved longitudinal heldout slots exist');
Assert::same([], $m['longitudinal']['custody']['payload_commitments'], 'holdout commitments not invented');
Assert::same('UNASSIGNED', $m['longitudinal']['custody']['custodian_status'], 'real independent custodian not invented');
Assert::same('UNASSIGNED', $m['longitudinal']['custody']['grounding_assessors'], 'human assessor unassigned');
Assert::same('UNASSIGNED', $m['longitudinal']['custody']['usefulness_assessors'], 'usefulness assessor unassigned');
Assert::same(0, $m['longitudinal']['current_observations']['independent_human_scores'], 'no fabricated scores');
Assert::same('INCOMPLETE_UNFROZEN_HELDOUT', $m['longitudinal']['current_observations']['candidate_acceptance'], 'heldout is not qualified');

foreach (['public_html/index.php', 'public_html/scheduler.php', 'worker/tick.php', 'app/Workflow/MonitorFactory.php'] as $entry) {
    $contents = (string) file_get_contents($root . '/' . $entry);
    Assert::false(str_contains($contents, 'HistoricalEvaluationFreeze')
        || str_contains($contents, 'EVALUATION_FREEZE_MANIFEST.json'),
        'no production bootstrap imports VA00 candidate: ' . $entry);
}
