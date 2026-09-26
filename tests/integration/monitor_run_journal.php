<?php
declare(strict_types=1);

use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;
use Allfather\Loopia\Workflow\RunJournal;

$db = Harness::freshDb();
if ($db === null) { Assert::skip(Harness::unavailableReason()); return; }
$clock = Harness::clock();
$scope = Seed::tenantWorkspace($db, $clock, 'monitor-journal');
$foreign = Seed::tenantWorkspace($db, $clock, 'monitor-foreign');
$mission = (new Proposals($db, $clock))->createMission($scope['tenant_id'], $scope['workspace_id'], 'COMPETITION_MONITORING', 'Watch approved source', 'Internal evidence review', ['OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE'], 'OPERATOR', $scope['operator_id']);
$journal = new RunJournal($db, $clock);
$definition = ['mission_id' => $mission, 'purpose' => 'Internal review'];
Assert::same('MONITOR_MISSION_SCOPE_INVALID', $journal->create($foreign, $definition, 'foreign', 'FIXTURE')->reasonCode(), 'foreign mission is refused before insertion');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM monitor_runs'), 'foreign refusal wrote nothing');
$created = $journal->create($scope, $definition, 'run-1', 'FIXTURE');
Assert::true($created->isOk(), 'scoped fixture run starts');
$run = $created->value();
Assert::same('NEW', $run['stage'], 'initial stage is honest');
Assert::same($run['monitor_run_id'], $journal->create($scope, $definition, 'run-1', 'FIXTURE')->value()['monitor_run_id'], 'identical request converges');
Assert::same('MONITOR_IDEMPOTENCY_CONFLICT', $journal->create($scope, ['mission_id' => $mission, 'purpose' => 'different'], 'run-1', 'FIXTURE')->reasonCode(), 'changed input cannot reuse key');
Assert::same(null, $journal->find($foreign, $run['monitor_run_id']), 'foreign read is absent');
Assert::same('MONITOR_RUN_NOT_FOUND', $journal->replay($foreign, $run['monitor_run_id'])->reasonCode(), 'foreign replay refuses');
Assert::same('MONITOR_TRANSITION_INVALID', $journal->advance($scope, $run, 'COMPLETE', [], 'invalid')->reasonCode(), 'cannot jump from created to released');
$captured = $journal->exclusive($scope, $run['monitor_run_id'], fn ($row) => $journal->advance($scope, $row, 'CAPTURED', ['source_snapshot_id' => 'fixture-ref'], 'CAPTURE_SUCCEEDED'));
Assert::true($captured->isOk(), 'capture transition commits with event');
Assert::same(1, (int) $captured->value()['revision'], 'revision advances once');
Assert::same('MONITOR_RUN_REVISION_CONFLICT', $journal->advance($scope, $run, 'CAPTURED', [], 'STALE')->reasonCode(), 'stale stage worker cannot overwrite committed cursor');
Assert::same(1, (int) $db->scalar('SELECT COUNT(*) FROM monitor_run_events'), 'stale worker added no event');
$replay = $journal->replay($scope, $run['monitor_run_id']);
Assert::true($replay->isOk(), 'chain verifies');
Assert::same(false, $replay->value()['reexecutes_model'], 'replay never invokes model');
Assert::same(false, $replay->value()['grants_release'], 'composition replay grants no release');

// An event insert failure must undo the cursor update in the same transaction.
$db->run("CREATE TRIGGER test_monitor_event_failure BEFORE INSERT ON monitor_run_events FOR EACH ROW BEGIN IF NEW.event_kind = 'FAULT' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected'; END IF; END");
try {
    $journal->advance($scope, $captured->value(), 'DIFFED', ['bad' => 'must rollback'], 'FAULT');
    Assert::fail('injected event failure must throw');
} catch (\PDOException) { Assert::pass(); }
$db->run('DROP TRIGGER test_monitor_event_failure');
Assert::same('CAPTURED', $journal->find($scope, $run['monitor_run_id'])['stage'], 'failed event leaves old stage');
Assert::true($journal->replay($scope, $run['monitor_run_id'])->isOk(), 'failed event leaves valid chain');
foreach (['UPDATE monitor_run_events SET event_kind = \'rewritten\'', 'DELETE FROM monitor_run_events', 'DELETE FROM monitor_runs', "UPDATE monitor_runs SET definition_json = '{}' "] as $sql) {
    try { $db->run($sql); Assert::fail('frozen history must reject mutation'); } catch (\PDOException) { Assert::pass(); }
}
$stopped = $journal->advance($scope, $captured->value(), 'STOPPED', $captured->value()['cursor'], 'OPERATOR_STOP');
Assert::true($stopped->isOk(), 'operator stop preserves cursor and history');
Assert::same('MONITOR_TRANSITION_INVALID', $journal->advance($scope, $stopped->value(), 'DIFFED', [], 'RESTART')->reasonCode(), 'stopped run cannot resume external work');
Assert::true($journal->replay($scope, $run['monitor_run_id'])->isOk(), 'stopped history remains replayable');
