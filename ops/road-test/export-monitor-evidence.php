<?php

declare(strict_types=1);

/**
 * Export a sanitized ROAD_TEST_001 evidence summary for one exact Monitor run.
 *
 * This command is READ-ONLY with respect to governed/application data on a successful authorized
 * path. It acquires the normal maintenance shared lease through BetaCli, but does not mutate
 * database product state. It does not fetch a source, call a model, release anything, file
 * deployment evidence, decide PASS/FAIL, or grant authority.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../../app/autoload.php';

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Ops\{BetaCli,SourceFingerprint};
use Allfather\Loopia\PromptOps\PromptApprovalStore;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Workflow\{ExecutionGrantStore,OwnerDecisionRegistry,RunJournal};

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'road-test-evidence: ' . $message . PHP_EOL);
    exit($code);
};

$args = BetaCli::parseArgs(
    $argv,
    ['env', 'tenant-id', 'workspace-id', 'operator-id', 'run-id', 'expect-release'],
    $fail,
);
$need = static function (string $name) use ($args, $fail): string {
    $value = $args[$name] ?? '';
    if ($value === '' || $value === '1') {
        $fail("--{$name} is required");
    }
    return $value;
};

$expectedRelease = $need('expect-release');
if (preg_match('/^[0-9a-f]{40}$/D', $expectedRelease) !== 1) {
    $fail('--expect-release must be a full lowercase 40-character git SHA');
}

$root = dirname(__DIR__, 2);
$config = BetaCli::configuration($root, $args, $fail);
$env = $config['env'];
$db = BetaCli::connect($env, $fail);
$clock = new Clock();

$tenantId = $need('tenant-id');
$workspaceId = $need('workspace-id');
$operatorId = $need('operator-id');
$runId = $need('run-id');

$runningRelease = (string) ($env->get('APP_RELEASE_REF') ?? '');
if (!hash_equals($expectedRelease, $runningRelease)) {
    $fail('APP_RELEASE_REF does not match --expect-release', 4);
}

// Use the existing owner for authorization. ALLOW is not retained, so the successful export does
// not manufacture an audit row. Denials retain their normal M02 evidence by design.
$access = (new AccessGuard($db, $clock))->evaluate(
    'OPERATOR',
    $operatorId,
    $tenantId,
    $workspaceId,
    'tenant.administer',
    'ROAD_TEST_EVIDENCE_EXPORT',
    $runId,
);
if (($access['decision'] ?? '') !== 'ALLOW') {
    $fail('operator is not authorized to export road-test evidence', 4);
}

$scope = ['tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'operator_id' => $operatorId];
$journal = new RunJournal($db, $clock);
$run = $journal->find($scope, $runId);
if ($run === null) {
    $fail('Monitor run not found in the requested scope', 4);
}
$replayed = $journal->replay($scope, $runId);
if (!$replayed->isOk()) {
    $fail('Monitor journal did not verify: ' . $replayed->reasonCode(), 4);
}

$eventRows = $db->all(
    'SELECT sequence_no,event_kind,event_digest,previous_digest,recorded_at
     FROM monitor_run_events WHERE monitor_run_id=? ORDER BY sequence_no',
    [$runId],
);
$events = array_map(static fn (array $row): array => [
    'sequence_no' => (int) $row['sequence_no'],
    'event_kind' => (string) $row['event_kind'],
    'event_digest' => (string) $row['event_digest'],
    'previous_digest' => $row['previous_digest'] === null ? null : (string) $row['previous_digest'],
    'recorded_at' => (string) $row['recorded_at'],
], $eventRows);

$bindings = $db->all(
    "SELECT b.run_revision,b.model_stage,b.model_attempt_id,b.request_artifact_digest,b.request_digest,
            a.tool_request_id,a.context_digest,a.prompt_version_id,a.binding_id,a.prompt_sha256,
            a.schema_sha256,a.execution_mode,a.reserved_at,
            pp.execution_purpose,pp.execution_grant_id,pp.material_provenance,pp.monitor_run_id AS purpose_monitor_run_id,
            r.model_result_id,r.provider_call_id,r.outcome,r.candidate_digest,r.failure_code,r.recorded_at,
            ca.artifact_digest,ca.artifact_size
     FROM monitor_model_attempt_bindings b
     JOIN provider_model_attempts a ON a.model_attempt_id=b.model_attempt_id
     JOIN provider_attempt_purposes pp ON pp.model_attempt_id=a.model_attempt_id
     LEFT JOIN provider_model_results r ON r.model_attempt_id=a.model_attempt_id
     LEFT JOIN provider_model_candidate_artifacts ca ON ca.model_attempt_id=a.model_attempt_id
     WHERE b.monitor_run_id=? AND pp.execution_purpose='MANUAL_MONITOR' AND pp.monitor_run_id=?
     ORDER BY b.run_revision,b.model_stage",
    [$runId, $runId],
);
$totalModelBindings = (int) $db->scalar(
    'SELECT COUNT(*) FROM monitor_model_attempt_bindings WHERE monitor_run_id=?',
    [$runId],
);

$modelAttempts = array_map(static fn (array $row): array => [
    'run_revision' => (int) $row['run_revision'],
    'model_stage' => (string) $row['model_stage'],
    'model_attempt_id' => (string) $row['model_attempt_id'],
    'tool_request_id' => (string) $row['tool_request_id'],
    'request_artifact_digest' => (string) $row['request_artifact_digest'],
    'request_digest' => (string) $row['request_digest'],
    'context_digest' => (string) $row['context_digest'],
    'prompt_version_id' => (string) $row['prompt_version_id'],
    'binding_id' => (string) $row['binding_id'],
    'prompt_sha256' => (string) $row['prompt_sha256'],
    'schema_sha256' => (string) $row['schema_sha256'],
    'execution_mode' => (string) $row['execution_mode'],
    'execution_purpose' => (string) $row['execution_purpose'],
    'execution_grant_id' => (string) $row['execution_grant_id'],
    'material_provenance' => (string) $row['material_provenance'],
    'purpose_monitor_run_id' => (string) $row['purpose_monitor_run_id'],
    'model_result_id' => $row['model_result_id'] === null ? null : (string) $row['model_result_id'],
    'provider_call_id' => $row['provider_call_id'] === null ? null : (string) $row['provider_call_id'],
    'outcome' => $row['outcome'] === null ? null : (string) $row['outcome'],
    'candidate_digest' => $row['candidate_digest'] === null ? null : (string) $row['candidate_digest'],
    'failure_code' => $row['failure_code'] === null ? null : (string) $row['failure_code'],
    'candidate_artifact_digest' => $row['artifact_digest'] === null ? null : (string) $row['artifact_digest'],
    'candidate_artifact_size' => $row['artifact_size'] === null ? null : (int) $row['artifact_size'],
    'reserved_at' => (string) $row['reserved_at'],
    'recorded_at' => $row['recorded_at'] === null ? null : (string) $row['recorded_at'],
], $bindings);

$reconciliations = array_map(
    static fn (array $row): array => [
        'reconciliation_id' => (string) $row['reconciliation_id'],
        'run_revision' => (int) $row['run_revision'],
        'model_stage' => (string) $row['model_stage'],
        'disposition' => (string) $row['disposition'],
        'model_attempt_id' => $row['model_attempt_id'] === null ? null : (string) $row['model_attempt_id'],
        'model_result_id' => $row['model_result_id'] === null ? null : (string) $row['model_result_id'],
        'request_digest' => $row['request_digest'] === null ? null : (string) $row['request_digest'],
        'candidate_digest' => $row['candidate_digest'] === null ? null : (string) $row['candidate_digest'],
        'artifact_digest' => $row['artifact_digest'] === null ? null : (string) $row['artifact_digest'],
        'observed_reason_code' => (string) $row['observed_reason_code'],
        'operator_reason_code' => $row['operator_reason_code'] === null ? null : (string) $row['operator_reason_code'],
        'recorded_at' => (string) $row['recorded_at'],
    ],
    $db->all(
        'SELECT reconciliation_id,run_revision,model_stage,disposition,model_attempt_id,model_result_id,
                request_digest,candidate_digest,artifact_digest,observed_reason_code,operator_reason_code,recorded_at
         FROM monitor_run_reconciliations
         WHERE tenant_id=? AND workspace_id=? AND monitor_run_id=?
         ORDER BY recorded_at,reconciliation_id',
        [$tenantId, $workspaceId, $runId],
    ),
);

$consumptions = $db->all(
    'SELECT c.execution_grant_id,c.consumption_kind,c.reference_id,c.recorded_at
     FROM monitor_grant_consumptions c
     WHERE c.monitor_run_id=?
     ORDER BY c.recorded_at,c.grant_consumption_id',
    [$runId],
);
$grantIds = [];
foreach ($consumptions as $row) {
    $grantIds[(string) $row['execution_grant_id']] = true;
}
foreach ($db->all(
    'SELECT DISTINCT execution_grant_id FROM monitor_admission_decisions
     WHERE tenant_id=? AND workspace_id=? AND monitor_run_id=? AND execution_grant_id IS NOT NULL',
    [$tenantId, $workspaceId, $runId],
) as $row) {
    $grantIds[(string) $row['execution_grant_id']] = true;
}

$decisions = OwnerDecisionRegistry::forRoot($root);
$approvals = new PromptApprovalStore($db, $clock, $decisions);
$grants = new ExecutionGrantStore($db, $clock, $approvals, $decisions);
$grantSummaries = [];
foreach (array_keys($grantIds) as $grantId) {
    $grant = $grants->find($tenantId, $workspaceId, $grantId);
    if ($grant === null) {
        continue;
    }
    $approvalRows = [];
    foreach ((array) ($grant['definition']['prompt_approval_ids'] ?? []) as $approvalId) {
        $row = $db->one(
            'SELECT prompt_approval_id,purpose,role,pack_version,prompt_version_id,binding_id,
                    prompt_sha256,schema_sha256,model_policy_digest,evaluation_reference
             FROM monitor_prompt_approvals
             WHERE prompt_approval_id=? AND tenant_id=? AND workspace_id=?',
            [(string) $approvalId, $tenantId, $workspaceId],
        );
        if ($row !== null) {
            $approvalRows[] = $row;
        }
    }
    $sourceCaptureRunIds = array_values(array_unique(array_map(
        'strval',
        array_column(
            $db->all(
                "SELECT monitor_run_id FROM monitor_grant_consumptions
                 WHERE execution_grant_id=? AND consumption_kind='SOURCE_CAPTURE' AND monitor_run_id IS NOT NULL
                 ORDER BY recorded_at,grant_consumption_id",
                [$grantId],
            ),
            'monitor_run_id',
        ),
    )));
    $grantSummaries[] = [
        'execution_grant_id' => $grantId,
        'purpose' => (string) $grant['purpose'],
        'grant_digest' => (string) $grant['grant_digest'],
        'status_now' => $grants->status($grantId),
        'valid_from' => (string) $grant['valid_from'],
        'valid_until' => (string) $grant['valid_until'],
        'consumption_counts' => [
            'SOURCE_CAPTURE' => $grants->consumed($grantId, 'SOURCE_CAPTURE'),
            'MODEL_ATTEMPT' => $grants->consumed($grantId, 'MODEL_ATTEMPT'),
        ],
        'source_capture_run_ids' => $sourceCaptureRunIds,
        'prompt_approvals' => $approvalRows,
    ];
}

$consumptionSummary = ['SOURCE_CAPTURE' => 0, 'MODEL_ATTEMPT' => 0];
foreach ($consumptions as $row) {
    $kind = (string) $row['consumption_kind'];
    if (array_key_exists($kind, $consumptionSummary)) {
        $consumptionSummary[$kind]++;
    }
}

$cursor = is_array($run['cursor'] ?? null) ? $run['cursor'] : [];
$pick = static function (mixed $value, array $allowed): array {
    if (!is_array($value)) {
        return [];
    }
    return array_intersect_key($value, array_flip($allowed));
};
$releaseRefs = [
    'capture' => $pick($cursor['capture'] ?? null, ['before_snapshot_id', 'after_snapshot_id', 'raw_artifact_id', 'payload_digest']),
    'diff' => $pick($cursor['diff'] ?? null, ['source_diff_id', 'change_significance']),
    'p01_release' => $pick($cursor['p01_release'] ?? null, [
        'released_briefing_id', 'release_artifact_id', 'customer_output_projection_id',
        'decision_record_id', 'company_signal_id', 'review_session_id', 'proposal_id',
    ]),
    'routing' => $pick($cursor['routing'] ?? null, [
        'role_distribution_briefing_id', 'proposal_id', 'review_session_id',
        'released_briefing_id', 'source_decision_record_id',
    ]),
    'routing_release' => $pick($cursor['routing_release'] ?? null, [
        'released_distribution_output_id', 'distribution_routing_record_id',
        'decision_record_id', 'role_distribution_briefing_id',
    ]),
];
$artifactDigests = [];
foreach ([
    'classifier_request_artifact','classifier_artifact','draft_request_artifact','draft_artifact',
    'critic_request_artifact','critic_artifact','routing_critic_request_artifact','routing_critic_artifact',
    'human_review_artifact','routing_review_artifact',
] as $key) {
    if (isset($cursor[$key]) && is_string($cursor[$key]) && preg_match('/^[a-f0-9]{64}$/D', $cursor[$key]) === 1) {
        $artifactDigests[$key] = $cursor[$key];
    }
}

$ledger = new DecisionLedger($db, $clock);
$currentReliance = [];
foreach (['p01_release', 'routing_release'] as $key) {
    $decisionId = $releaseRefs[$key]['decision_record_id'] ?? null;
    if (is_string($decisionId) && $decisionId !== '') {
        $currentReliance[$key] = [
            'decision_record_id' => $decisionId,
            'current_reliance' => $ledger->currentReliance($decisionId),
        ];
    }
}

$humanReleaseEvents = count(array_filter(
    $events,
    static fn (array $event): bool => $event['event_kind'] === 'HUMAN_APPROVED_INTERNAL_RELEASE',
));

BetaCli::emit([
    'schema_version' => '1.0',
    'kind' => 'ROAD_TEST_001_MONITOR_EVIDENCE_SUMMARY',
    'release' => [
        'expected_release_ref' => $expectedRelease,
        'running_release_ref' => $runningRelease,
        'source_fingerprint' => SourceFingerprint::compute($root),
    ],
    'scope' => [
        'tenant_id' => $tenantId,
        'workspace_id' => $workspaceId,
        'monitor_run_id' => $runId,
    ],
    'run' => [
        'mission_id' => (string) $run['mission_id'],
        'execution_mode' => (string) $run['execution_mode'],
        'stage' => (string) $run['stage'],
        'revision' => (int) $run['revision'],
        'definition_digest' => (string) $run['definition_digest'],
        'event_digest' => $run['event_digest'],
        'created_at' => (string) $run['created_at'],
        'updated_at' => (string) $run['updated_at'],
        'journal_integrity' => 'PASS',
        'event_count' => count($events),
        'events' => $events,
    ],
    'execution' => [
        'grants' => $grantSummaries,
        'consumption_counts' => $consumptionSummary,
        'model_attempts' => $modelAttempts,
        'reconciliations' => $reconciliations,
    ],
    'release_refs' => $releaseRefs,
    'artifact_digests' => $artifactDigests,
    'current_reliance' => $currentReliance,
    'observations' => [
        'native_run' => $run['execution_mode'] === 'NATIVE',
        'complete_stage' => $run['stage'] === 'COMPLETE',
        'human_internal_release_events' => $humanReleaseEvents,
        'source_capture_consumptions' => $consumptionSummary['SOURCE_CAPTURE'],
        'model_attempt_consumptions' => $consumptionSummary['MODEL_ATTEMPT'],
        'model_attempt_bindings_total' => $totalModelBindings,
        'model_attempt_receipts_with_purpose' => count($modelAttempts),
        'all_model_bindings_have_purpose_receipts' => count($modelAttempts) === $totalModelBindings,
        'manual_monitor_attempts_with_product_mission_provenance' => count(array_filter(
            $modelAttempts,
            static fn (array $attempt): bool => $attempt['execution_purpose'] === 'MANUAL_MONITOR'
                && $attempt['material_provenance'] === 'PRODUCT_MISSION'
                && $attempt['purpose_monitor_run_id'] === $runId,
        )),
        'p01_release_reference_present' => $releaseRefs['p01_release'] !== [],
        'p04_release_reference_present' => $releaseRefs['routing_release'] !== [],
    ],
    'records_evidence' => false,
    'decides_road_test_result' => false,
    'grants_runtime_authority' => false,
    'activates_live_read' => false,
    'governed_data_writes_performed_on_success' => 0,
]);
