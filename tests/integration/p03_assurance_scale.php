<?php

declare(strict_types=1);

/**
 * P08 — the buyer-intent invariant at a scale TEXT could not hold.
 *
 * The scan is ONE audit over every queue item in the scope, and canonical records every violating
 * item id both on the run (audit_runs.target_object_refs) and on the incident request
 * (incident_evaluation_requests.related_object_refs). As TEXT (64 KiB) those columns refused the
 * write past roughly 1,680 violating ids, which rolled the whole audit back: the larger the breach,
 * the less of it was recorded. Migration 246 widens both to LONGTEXT; this test records 1,700.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P03\Assurance as P03Assurance;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M11\Assurance as M11Assurance;
use Allfather\Loopia\Tests\{Assert,Harness,Seed};

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());
    return;
}
$clock = Harness::clock('2026-07-02T00:00:00.000Z');
$p03 = new P03Assurance($db, $clock, new M11Assurance($db, $clock, new DecisionLedger($db, $clock)));
$scope = Seed::tenantWorkspace($db, $clock, 'p03scale');
$profiles = $p03->seedProfiles($scope['tenant_id'], $scope['workspace_id']);

// One non-trigger signal (market context, a hypothesis — not a buying-trigger FACT) cited by every
// queue item. The items are the canonical fixture shape: they bypassed the cp5 flow and the M08 gate,
// so they carry synthetic hypothesis/verdict ids and foreign-key checks are suspended for the bulk
// insert only.
$competitorId = Ids::new();
$db->insert('competitors', ['competitor_id' => $competitorId, 'schema_version' => '1', 'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
    'display_name' => 'Rival', 'competitor_class' => 'DIRECT', 'status' => 'ACTIVE', 'created_at' => '2026-07-01T00:00:00.000Z']);
$signalId = Ids::new();
$db->insert('company_signals', ['company_signal_id' => $signalId, 'schema_version' => '1', 'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
    'competitor_id' => $competitorId, 'signal_class' => 'MARKET_CONTEXT_SIGNAL', 'interpretation_kind' => 'HYPOTHESIS', 'confidence_band' => 'LOW',
    'summary_safe' => 'Market context', 'status' => 'RELEASED', 'created_at' => '2026-07-01T00:00:00.000Z']);
$VIOLATORS = 1_700;
$ids = [];
$values = [];
$params = [];
for ($i = 0; $i < $VIOLATORS; $i++) {
    $ids[] = $id = Ids::new();
    $values[] = "(?, '1', ?, ?, 'synthetic-fit-hypothesis', ?, 'synthetic-arbiter-verdict', 'OPEN', ?)";
    array_push($params, $id, $scope['tenant_id'], $scope['workspace_id'], $signalId, sprintf('2026-07-01T00:00:%02d.%03dZ', intdiv($i, 1000), $i % 1000));
}
$db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
try {
    foreach (array_chunk(range(0, $VIOLATORS - 1), 500) as $chunk) {
        $db->run(
            'INSERT INTO priority_outreach_queue_items (priority_outreach_queue_item_id, schema_version, tenant_id, workspace_id, fit_hypothesis_id,
                 triggering_company_signal_id, arbiter_verdict_id, queue_status, created_at) VALUES ' . implode(', ', array_map(fn ($i) => $values[$i], $chunk)),
            array_merge(...array_map(fn ($i) => array_slice($params, $i * 5, 5), $chunk))
        );
    }
} finally {
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
}
$encoded = strlen(json_encode($ids, JSON_THROW_ON_ERROR));
Assert::true($encoded > 65_535, "the violating-id list ({$encoded} bytes) is larger than a TEXT column holds");

$audit = $p03->runBuyerIntentInvariantAudit(['audit_profile_id' => $profiles['BUYER_INTENT_INVARIANT']]);
Assert::true($audit->isOk(), 'the audit records instead of rolling back: ' . ($audit->isOk() ? 'ok' : $audit->reasonCode()));
Assert::same([true, $VIOLATORS], [$audit->value()['failed'], $audit->value()['violation_count']], 'every violator is counted');
Assert::same($ids, $audit->value()['violating_queue_item_ids'], 'in canonical order');
$run = $db->one('SELECT target_object_refs, status FROM audit_runs WHERE audit_run_id = ?', [$audit->value()['audit_run_id']]);
Assert::same(['COMPLETED', $ids], [$run['status'], json_decode((string) $run['target_object_refs'], true, 512, JSON_THROW_ON_ERROR)], 'the run holds every violating id');
$request = $db->scalar('SELECT r.related_object_refs FROM incident_evaluation_requests r JOIN incident_cases c ON c.incident_evaluation_request_id = r.incident_evaluation_request_id WHERE c.incident_case_id = ?', [$audit->value()['incident_case_id']]);
Assert::same($ids, json_decode((string) $request, true, 512, JSON_THROW_ON_ERROR), 'and so does the incident it opened');

$swept = $p03->sweep(['tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'signal_pairing_freshness_window_ms' => 7 * 86_400_000]);
Assert::true($swept->isOk(), 'the sweep runs');
$scan = array_values(array_filter($swept->value()['audits'], fn ($a) => $a['audit_category'] === 'BUYER_INTENT_INVARIANT'))[0];
Assert::same(['COMPLETED', true], [$scan['outcome'], $scan['failed']], 'and within a sweep the scan completes and fails');
Assert::same(true, $swept->value()['complete'], 'so the sweep is complete');
