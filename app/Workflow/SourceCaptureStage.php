<?php
declare(strict_types=1);
namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Digest,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Ingestion\{FetchTransport,HostResolver};
use Allfather\Loopia\Product\P01\Capture;
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;

/** P01 capture -> policy-checked private M05 body -> replayable composition reference. */
final class SourceCaptureStage
{
    public function __construct(
        private readonly Database $db, private readonly Clock $clock, private readonly Capture $capture,
        private readonly EvidenceSpine $evidence, private readonly EvidenceStore $store,
        private readonly ContextEnvelope $context, private readonly FetchTransport $transport,
        private readonly HostResolver $resolver,
    ) {}

    /** @param null|callable():Outcome $beforeFetch @param null|callable():Outcome $beforeCapture */
    public function execute(array $scope, array $run, ?callable $beforeFetch = null, ?callable $beforeCapture = null): Outcome
    {
        $sourceId = $run['definition']['monitored_source_id'] ?? '';
        if (!is_string($sourceId) || $sourceId === '') {
            return Outcome::refuse('MONITOR_SOURCE_SCOPE_INVALID');
        }
        // Run locks protect one run. This source lock protects the before->after baseline shared by
        // different runs without holding a SQL transaction open through DNS/HTTP.
        $database = (string) $this->db->scalar('SELECT DATABASE()');
        $lock = 'monitor-source:' . substr(hash('sha256', $database . "\0" . $sourceId), 0, 48);
        if ((int) $this->db->scalar('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            return Outcome::refuse('MONITOR_SOURCE_BUSY');
        }
        try {
            return $this->executeLocked($scope, $run, $beforeFetch, $beforeCapture);
        } finally {
            $this->db->scalar('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** @param null|callable():Outcome $beforeFetch @param null|callable():Outcome $beforeCapture */
    private function executeLocked(array $scope, array $run, ?callable $beforeFetch = null, ?callable $beforeCapture = null): Outcome
    {
        $definition = $run['definition'];
        if ($beforeCapture !== null) {
            $admitted = $beforeCapture();
            if (!$admitted instanceof Outcome || !$admitted->isOk()) {
                return $admitted instanceof Outcome ? $admitted : Outcome::refuse('SOURCE_CAPTURE_ADMISSION_CHECK_INVALID');
            }
        }
        $before = $this->db->one('SELECT source_snapshot_id FROM source_snapshots WHERE monitored_source_id = ? AND tenant_id = ? AND workspace_id = ? ORDER BY snapshot_sequence DESC, source_snapshot_id DESC LIMIT 1', [$definition['monitored_source_id'], $scope['tenant_id'], $scope['workspace_id']]);
        $transport = new RecordingSourceTransport($this->transport);
        $captured = $this->capture->captureMonitoredSource([
            'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
            'monitored_source_id' => $definition['monitored_source_id'], 'capture_url' => $definition['capture_url'],
            'idempotency_key' => 'monitor.capture.' . $run['monitor_run_id'], 'correlation_id' => $run['monitor_run_id'],
            // The M06 tool request names who actually acts: the operator, or the scheduler's service.
            'governance' => array_merge($definition['capture_governance'], self::callingActor($scope)),
        ], $transport, $this->resolver, $beforeFetch);
        if (!$captured->isOk()) {
            return $transport->failureCode === null ? $captured : Outcome::refuse($transport->failureCode);
        }
        // An ambiguous/crashed attempt cannot reconstruct missing source bytes by fetching again.
        if ($transport->payload === null || !isset($captured->value()['source_snapshot_id'])) {
            return Outcome::refuse('MONITOR_CAPTURE_REQUIRES_RECONCILIATION');
        }
        $snapshotId = $captured->value()['source_snapshot_id'];
        $snapshot = $this->db->one('SELECT ss.evidence_id,ss.content_hash,ms.source_ref_id FROM source_snapshots ss JOIN monitored_sources ms ON ms.monitored_source_id = ss.monitored_source_id WHERE ss.source_snapshot_id = ? AND ss.tenant_id = ? AND ss.workspace_id = ?', [$snapshotId, $scope['tenant_id'], $scope['workspace_id']]);
        if ($snapshot === null || !hash_equals($snapshot['content_hash'], Digest::canonicalDigest($transport->payload))) {
            return Outcome::refuse('MONITOR_CAPTURE_CONTENT_MISMATCH');
        }
        $input = $definition['context_selection'];
        $input['observation_evidence_ids'] = [$snapshot['evidence_id']];
        $input['observation_payloads'] = [$snapshot['evidence_id'] => $transport->payload];
        $checked = $this->context->prepare(self::contextScope($scope), $input);
        if (!$checked->isOk()) { return $checked; }
        // Policy was resolved by ContextEnvelope before any retained model-input bytes are written.
        $stored = $this->store->put(Digest::canonicalize($transport->payload));
        if (!$stored->isOk()) { return $stored; }
        $bound = $this->db->transaction(function () use ($scope, $snapshot, $snapshotId, $stored): Outcome {
            $artifact = $this->evidence->recordRawArtifact([
                'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
                'source_ref_id' => $snapshot['source_ref_id'], 'artifact_type' => 'HOST_RESULT_CAPTURE',
                'content_hash' => $snapshot['content_hash'], 'capture_method' => 'GOVERNED_MONITOR_CAPTURE',
                'storage_reference_safe' => 'sha256:' . $stored->value()['digest'], 'access_classification' => 'INTERNAL_ONLY',
            ]);
            if (!$artifact->isOk()) { return $artifact; }
            $this->db->insert('monitor_capture_payloads', ['source_snapshot_id' => $snapshotId, 'raw_artifact_id' => $artifact->value()['raw_artifact_id'], 'payload_digest' => $stored->value()['digest'], 'created_at' => $this->clock->now()]);
            return Outcome::ok();
        });
        if (!$bound->isOk()) { return $bound; }
        return Outcome::ok(['before_snapshot_id' => $before['source_snapshot_id'] ?? null, 'after_snapshot_id' => $snapshotId, 'after_evidence_id' => $snapshot['evidence_id'], 'payload_digest' => $stored->value()['digest']]);
    }

    public function contextFor(array $scope, array $definition, array $snapshotIds): Outcome
    {
        $input = $definition['context_selection'];
        $input['observation_evidence_ids'] = []; $input['observation_payloads'] = [];
        foreach ($snapshotIds as $id) {
            $row = $this->db->one('SELECT ss.evidence_id,ss.content_hash,mp.payload_digest FROM source_snapshots ss JOIN monitor_capture_payloads mp ON mp.source_snapshot_id = ss.source_snapshot_id WHERE ss.source_snapshot_id = ? AND ss.tenant_id = ? AND ss.workspace_id = ? AND ss.monitored_source_id = ?', [$id, $scope['tenant_id'], $scope['workspace_id'], $definition['monitored_source_id']]);
            if ($row === null || $row['content_hash'] !== $row['payload_digest']) { return Outcome::refuse('MONITOR_CAPTURE_BYTES_UNAVAILABLE'); }
            $stored = $this->store->get($row['payload_digest']);
            if (!$stored->isOk()) { return $stored; }
            $payload = json_decode($stored->value(), true, 32, JSON_THROW_ON_ERROR);
            $input['observation_evidence_ids'][] = $row['evidence_id'];
            $input['observation_payloads'][$row['evidence_id']] = $payload;
        }
        return $this->context->prepare(self::contextScope($scope), $input);
    }

    public static function contextScope(array $scope): array
    {
        $actor = self::callingActor($scope);

        return ['tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'actor_type' => $actor['calling_actor_type'], 'actor_id' => $actor['calling_actor_id']];
    }

    /** @return array{calling_actor_type: string, calling_actor_id: string} */
    private static function callingActor(array $scope): array
    {
        $principal = MonitorPrincipal::fromScope($scope);
        // A malformed principal is evaluated as an unmapped identity and refused by M02, never
        // quietly treated as the operator it rode in with.
        if ($principal === null) {
            return ['calling_actor_type' => 'SERVICE', 'calling_actor_id' => 'invalid-principal'];
        }

        return ['calling_actor_type' => $principal->actorType(), 'calling_actor_id' => $principal->actorId];
    }
}
