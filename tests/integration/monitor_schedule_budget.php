<?php
declare(strict_types=1);

/**
 * P07 — every bound that stops a schedule from becoming an unbounded crawler or a budget refill.
 *
 *   - catch-up after an outage is ONE occurrence (LATEST_ONLY), with the missed slots recorded;
 *   - the schedule's own occurrence cap;
 *   - the parent grant's capture budget, which a successor version does not refill;
 *   - a full human queue stops new occurrences;
 *   - expiry, and one admitted version per series.
 */

require_once __DIR__ . '/../support/ScheduleFixture.php';

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Tests\{Assert,ExecutionFixture as X,Harness,ScheduleFixture as S};

$db = Harness::freshDb();
if ($db === null) { Assert::skip(Harness::unavailableReason()); return; }
$DAY = 86_400_000;

$outcomes = static fn (string $versionId): array => $db->all(
    'SELECT o.slot_index, o.skipped_slots, x.outcome FROM monitor_schedule_occurrences o
     LEFT JOIN monitor_schedule_occurrence_outcomes x ON x.monitor_schedule_occurrence_id = o.monitor_schedule_occurrence_id
     WHERE o.monitor_schedule_version_id = ? ORDER BY o.slot_index',
    [$versionId]
);

// ============================================================ catch-up and the occurrence cap

$clock = Harness::clock('2026-04-01T06:00:00.000Z');
$sx = S::create($db, $clock, array_fill(0, 10, 'Unchanged page.'));
$schedule = S::admitted($sx, S::input($sx, $clock, ['max_occurrences' => 3]));
$version = $schedule['monitor_schedule_version_id'];

$sx['runner']->tick(5, 20000, 300);
Assert::same([['slot_index' => 0, 'skipped_slots' => 0, 'outcome' => 'BASELINE_RECORDED']], array_map(static fn (array $r): array => ['slot_index' => (int) $r['slot_index'], 'skipped_slots' => (int) $r['skipped_slots'], 'outcome' => $r['outcome']], $outcomes($version)), 'the first slot records the baseline');

// A ten-day outage: ONE occurrence for the latest slot, with nine missed slots recorded — no storm.
$clock->advanceMs(10 * $DAY);
$catchUp = $sx['runner']->tick(5, 20000, 300);
Assert::same(1, count($catchUp['dispatch']['reserved']), 'an outage of ten slots reserves exactly one occurrence');
Assert::same(9, $catchUp['dispatch']['reserved'][0]['skipped_slots'], 'and records the nine slots it skipped');
Assert::same(1, $catchUp['claimed'], 'one step runs');
$second = $sx['runner']->tick(5, 20000, 300);
Assert::same([], $second['dispatch']['reserved'], 'the skipped slots are never replayed');
Assert::same(2, $sx['source']->calls, 'two captures in total, not eleven');

$clock->advanceMs($DAY);
$sx['runner']->tick(5, 20000, 300);
Assert::same(3, count($outcomes($version)), 'the third occurrence runs');
$clock->advanceMs($DAY);
$capped = $sx['runner']->tick(5, 20000, 300);
Assert::same(['SCHEDULE_BUDGET_EXHAUSTED' => 1], $capped['dispatch']['skipped'], "the schedule's own cap stops the fourth");
Assert::same(3, $sx['source']->calls, 'and no fourth capture happens');
foreach ($db->all("SELECT attempt_count FROM jobs WHERE job_type = 'MONITOR_STEP' AND tenant_id = ?", [$sx['scope']['tenant_id']]) as $job) {
    Assert::same(1, (int) $job['attempt_count'], 'a successful occurrence spends one attempt, not a retry budget');
}

// ============================================================ the parent grant is not refilled by a successor

$clock2 = Harness::clock('2026-05-01T06:00:00.000Z');
$gx = S::create($db, $clock2, array_fill(0, 10, 'Unchanged page.'), ['limits' => ['max_model_attempts' => 0, 'max_source_captures' => 2]]);
$v1 = S::admitted($gx, S::input($gx, $clock2, ['max_occurrences' => 2]));
$gx['runner']->tick(5, 20000, 300);
$clock2->advanceMs($DAY);
$gx['runner']->tick(5, 20000, 300);
Assert::same(2, $gx['grants']->consumed($gx['scheduled_grant']['execution_grant_id'], 'SOURCE_CAPTURE'), 'two captures spent the whole grant');

// A successor cannot be admitted beside an admitted predecessor.
$successor = S::value($gx['schedules']->propose($gx['scope'], S::input($gx, $clock2, ['monitor_schedule_id' => $v1['monitor_schedule_id'], 'max_occurrences' => 2])));
Assert::same(2, $successor['schedule_version'], 'a successor is version 2 of the same series');
$successorDecision = X::decision($gx, ['SCHEDULED_OBSERVATION'], [], [], '', [$successor['schedule_digest']]);
Assert::same('SCHEDULE_OTHER_VERSION_ADMITTED', $gx['schedules']->admit($gx['scope'], $successor['monitor_schedule_version_id'], $successorDecision['ref'], $successorDecision['sha256'])->reasonCode(), 'one admitted version per series');
S::value($gx['schedules']->revoke($gx['scope'], $v1['monitor_schedule_version_id'], 'SUPERSEDED_BY_V2'));
S::value($gx['schedules']->admit($gx['scope'], $successor['monitor_schedule_version_id'], $successorDecision['ref'], $successorDecision['sha256']));
$clock2->advanceMs($DAY);
$refill = $gx['runner']->tick(5, 20000, 300);
Assert::same(['GRANT_BUDGET_EXHAUSTED' => 1], $refill['dispatch']['skipped'], 'a fresh version is not a refill: the parent grant budget is spent');
Assert::same(2, $gx['source']->calls, 'and no capture happens');

// ============================================================ a queue nobody reads stops new occurrences

$clock3 = Harness::clock('2026-06-01T06:00:00.000Z');
$qx = S::create($db, $clock3, ['A page.', 'B page.', 'A page.', 'B page.', 'A page.', 'B page.']);
$queue = S::admitted($qx, S::input($qx, $clock3));
for ($i = 0; $i < 4; $i++) {
    $qx['runner']->tick(5, 20000, 300);
    $clock3->advanceMs($DAY);
}
Assert::same(
    ['BASELINE_RECORDED', 'WAITING_FOR_OPERATOR', 'WAITING_FOR_OPERATOR', 'WAITING_FOR_OPERATOR'],
    array_column($outcomes($queue['monitor_schedule_version_id']), 'outcome'),
    'three changed occurrences wait for a human',
);
$full = $qx['runner']->tick(5, 20000, 300);
Assert::same(['OPERATOR_QUEUE_FULL' => 1], $full['dispatch']['skipped'], 'a fourth waiting run is not created while three wait unread');
Assert::same(4, $qx['source']->calls, 'and nothing is captured for it');
Assert::same(0, $qx['model']->calls, 'none of this called a model');

// ============================================================ expiry

$clock4 = Harness::clock('2026-07-01T06:00:00.000Z');
$ex = S::create($db, $clock4, array_fill(0, 4, 'Unchanged page.'));
$now = Clock::parse($clock4->now());
$short = S::admitted($ex, S::input($ex, $clock4, ['valid_until' => Clock::format($now->modify('+2 days'))]));
$ex['runner']->tick(5, 20000, 300);
$clock4->advanceMs(3 * $DAY);
Assert::same('EXPIRED', $ex['schedules']->status($short['monitor_schedule_version_id']), 'a schedule past its window is EXPIRED, computed, never stored');
$expired = $ex['runner']->tick(5, 20000, 300);
Assert::same(0, $expired['dispatch']['considered'], 'an expired schedule is not even a candidate');
Assert::same(1, $ex['source']->calls, 'and captures nothing more');
