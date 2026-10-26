<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M17;

use Allfather\Loopia\Core\{Clock,Digest,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Ingestion\SourcePolicy;

/**
 * LI-05B / U03 CANDIDATE: authentic, read-only native M05/P01 capture preflight.
 *
 * This is deliberately NOT a new SourceAdmission, M05 evidence writer,
 * historical-use grant, M17 InputSet factory, or model context admission.
 * It reads native owner records, tests current rights and verifies archived
 * bytes, but always reports historical_use_admitted=false until U03 is adopted.
 */
final class HistoricalCaptureProbe
{
    public const VERSION = 'li05b.native-history-preflight-candidate.v1';

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly EvidenceStore $storage,
    ) {}

    /** @param array<string,mixed> $scope */
    public function inspect(
        array $scope,
        string $competitorId,
        string $sourceSnapshotId,
        string $knowledgeCutoffUtc,
    ): Outcome {
        if (!is_array($scope)
            || !Vocabulary::exactKeys($scope, ['tenant_id','workspace_id','actor_type','actor_id'])
            || !in_array($scope['actor_type'], ['OPERATOR','SERVICE'], true)) {
            return Outcome::refuse('M17_HISTORICAL_ACTOR_SCOPE_INVALID');
        }
        foreach ($scope as $item) if (!Vocabulary::id($item)) {
            return Outcome::refuse('M17_HISTORICAL_ACTOR_SCOPE_INVALID');
        }
        if (!Vocabulary::id($competitorId) || !Vocabulary::id($sourceSnapshotId)
            || !self::utc24($knowledgeCutoffUtc)) {
            return Outcome::refuse('M17_HISTORICAL_QUERY_INVALID');
        }
        if ($knowledgeCutoffUtc > $this->clock->now()) {
            return Outcome::refuse('M17_HISTORICAL_FUTURE_CUTOFF');
        }
        try {
            $access = new AccessGuard($this->db, $this->clock);
            foreach (['competition_monitoring.read','evidence.read'] as $permission) {
                $decision=$access->evaluate(
                    $scope['actor_type'], $scope['actor_id'],
                    $scope['tenant_id'], $scope['workspace_id'],
                    $permission, 'M17_HISTORY_PREFLIGHT', $competitorId,
                );
                if (($decision['decision']??null)!=='ALLOW') {
                    return Outcome::refuse('M17_HISTORICAL_SCOPE_DENIED');
                }
            }
            $row=$this->db->one(
                'SELECT ss.source_snapshot_id,ss.tenant_id,ss.workspace_id,ss.evidence_id,
                        ss.monitored_source_id,ss.content_hash,ss.captured_at,ss.capture_method,
                        ms.competitor_id,ms.source_ref_id,ms.status AS monitored_status,
                        ms.source_license_card_id,c.status AS competitor_status,
                        ev.source_ref_id AS evidence_source_ref_id,ev.tenant_id AS evidence_tenant_id,
                        ev.workspace_id AS evidence_workspace_id,ev.evidence_status,ev.evidence_type,
                        ev.content_hash AS evidence_hash,ev.captured_at AS evidence_captured_at,
                        ev.tool_request_id,ev.tool_authorization_decision_id,ev.runtime_host_execution_ref,
                        mp.raw_artifact_id,mp.payload_digest,mp.created_at AS payload_recorded_at,
                        ra.source_ref_id AS artifact_source_ref_id,ra.content_hash AS artifact_hash,
                        ra.storage_reference_safe AS artifact_storage_ref
                 FROM source_snapshots ss
                 JOIN monitored_sources ms ON ms.monitored_source_id=ss.monitored_source_id
                     AND ms.tenant_id=ss.tenant_id AND ms.workspace_id=ss.workspace_id
                 JOIN competitors c ON c.competitor_id=ms.competitor_id
                     AND c.tenant_id=ms.tenant_id AND c.workspace_id=ms.workspace_id
                 JOIN evidence_objects ev ON ev.evidence_id=ss.evidence_id
                 JOIN monitor_capture_payloads mp ON mp.source_snapshot_id=ss.source_snapshot_id
                 JOIN raw_artifacts ra ON ra.raw_artifact_id=mp.raw_artifact_id
                 WHERE ss.source_snapshot_id=? AND ss.tenant_id=? AND ss.workspace_id=?
                   AND ms.competitor_id=?',
                [$sourceSnapshotId,$scope['tenant_id'],$scope['workspace_id'],$competitorId],
            );
            if ($row===null) return Outcome::refuse('M17_HISTORICAL_OWNER_REF_UNAVAILABLE');

            if ($row['monitored_status']!=='ACTIVE'
                || !in_array($row['competitor_status'],['ACTIVE','PAUSED'],true)
                || !in_array($row['evidence_status'],['CAPTURED','ASSESSED'],true)) {
                return Outcome::refuse('M17_HISTORICAL_REVOKED_OR_QUARANTINED');
            }
            if ($row['evidence_type']!=='HOST_RETRIEVAL_CAPTURE'
                || $row['capture_method']!=='LIVE_FETCH'
                || $row['evidence_source_ref_id']!==$row['source_ref_id']
                || $row['artifact_source_ref_id']!==$row['source_ref_id']
                || $row['evidence_tenant_id']!==$scope['tenant_id']
                || $row['evidence_workspace_id']!==$scope['workspace_id']
                || !$row['tool_request_id'] || !$row['tool_authorization_decision_id']
                || !$row['runtime_host_execution_ref']) {
                return Outcome::refuse('M17_HISTORICAL_M05_BINDING_INVALID');
            }
            if (!self::utc24($row['evidence_captured_at'])
                || !self::utc24($row['captured_at'])
                || !self::utc24($row['payload_recorded_at'])) {
                return Outcome::refuse('M17_HISTORICAL_KNOWN_AT_UNKNOWN');
            }
            $knownAt=max($row['captured_at'],$row['evidence_captured_at'],$row['payload_recorded_at']);
            if ($knownAt > $knowledgeCutoffUtc) {
                return Outcome::refuse('M17_HISTORICAL_NOT_KNOWN_BY_CUTOFF');
            }
            $source=$this->db->one(
                'SELECT * FROM source_references WHERE source_ref_id=? AND tenant_id=?',
                [$row['source_ref_id'],$scope['tenant_id']]);
            $card=$this->db->one(
                'SELECT * FROM source_license_cards WHERE source_license_card_id=?
                 AND tenant_id=? AND source_ref_id=?',
                [$row['source_license_card_id'],$scope['tenant_id'],$row['source_ref_id']],
            );
            if ($source===null || $card===null) {
                return Outcome::refuse('M17_HISTORICAL_SOURCE_OWNER_UNKNOWN');
            }
            if ($source['status']!=='ACTIVE_FOR_CAPTURE'
                || $source['content_sensitivity_class']!=='PUBLIC_OR_NON_SENSITIVE'
                || ($source['workspace_id']!==null && $source['workspace_id']!==$scope['workspace_id'])
                || $card['review_status']!=='approved'
                || $card['retention_class']!=='standard'
                || !in_array($card['caching_policy'],['standard_cache','explicit_contract'],true)
                || !in_array($card['allowed_use_class'],['derived_summary_allowed','customer_visible_allowed'],true)
                || (int)$card['derived_summary_allowed']!==1) {
                return Outcome::refuse('M17_HISTORICAL_CURRENT_RIGHTS_BLOCKED');
            }
            $review=$this->db->one(
                'SELECT * FROM source_terms_reviews WHERE source_license_card_id=?
                 ORDER BY reviewed_at DESC,source_terms_review_id DESC LIMIT 1',
                [$row['source_license_card_id']],
            );
            $millis=(int)round((float)(new \DateTimeImmutable($this->clock->now()))->format('U.u')*1000);
            $policy=SourcePolicy::compile($source,$card,$review,null,$millis);
            // There is no network action here; a deactivated connector is not a
            // license to skip source review, scope, retention or legal/robots gates.
            $unresolved=array_diff($policy['fail_closed_reason_codes'],[
                'CONNECTOR_NOT_ACTIVATED','CONNECTOR_CLASS_NOT_PROMOTED',
            ]);
            if ($unresolved!==[] || !is_string($policy['allowed_url_scope']??null)) {
                return Outcome::refuse('M17_HISTORICAL_CURRENT_POLICY_REFUSED');
            }
            $tool=$this->db->one(
                'SELECT eo.result_digest,eo.outcome_status,eo.evidence_capture_candidate,
                        r.tenant_id,r.workspace_id
                 FROM tool_execution_outcomes eo
                 JOIN tool_requests r ON r.tool_request_id=eo.tool_request_id
                 WHERE eo.tool_request_id=? AND eo.tool_authorization_decision_id=?
                   AND eo.runtime_host_execution_ref=?',
                [$row['tool_request_id'],$row['tool_authorization_decision_id'],$row['runtime_host_execution_ref']],
            );
            if ($tool===null || $tool['outcome_status']!=='SUCCEEDED'
                || (int)$tool['evidence_capture_candidate']!==1
                || $tool['tenant_id']!==$scope['tenant_id']
                || $tool['workspace_id']!==$scope['workspace_id']
                || $tool['result_digest']!==$row['content_hash']) {
                return Outcome::refuse('M17_HISTORICAL_M05_EXECUTION_UNAUTHENTICATED');
            }
            $digest=$row['content_hash'];
            if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D',$digest)!==1
                || $row['evidence_hash']!==$digest
                || $row['artifact_hash']!==$digest
                || $row['payload_digest']!==$digest
                || $row['artifact_storage_ref']!=='sha256:'.$digest) {
                return Outcome::refuse('M17_HISTORICAL_DIGEST_BINDING_INVALID');
            }
            $read=$this->storage->get($digest);
            if (!$read->isOk()) return Outcome::refuse('M17_HISTORICAL_BYTES_NOT_AVAILABLE');
            $body=json_decode((string)$read->value(),true,32,JSON_THROW_ON_ERROR);
            if (!is_array($body) || !Vocabulary::exactKeys($body,['url','body'])
                || !is_string($body['url']) || !is_string($body['body'])
                || !SourcePolicy::urlWithinScope($policy['allowed_url_scope'],$body['url'])
                || !hash_equals($digest,Digest::canonicalDigest($body))
                || !hash_equals((string)$read->value(),Digest::canonicalize($body))) {
                return Outcome::refuse('M17_HISTORICAL_BYTES_OR_SCOPE_MISMATCH');
            }
            // This is a bounded proof of EXISTING owner metadata and retained
            // bytes only. It cannot create the still-unadopted U03 purpose grant.
            return Outcome::ok([
                'version'=>self::VERSION,
                'status'=>'NATIVE_HISTORY_PREFLIGHT_VERIFIED_BUT_U03_HOLD',
                'source_snapshot_id'=>$sourceSnapshotId,
                'evidence_id'=>$row['evidence_id'],
                'source_ref_id'=>$row['source_ref_id'],
                'competitor_id'=>$competitorId,
                'observed_at_utc'=>$row['captured_at'],
                'known_at_upper_bound_utc'=>$knownAt,
                'knowledge_cutoff_utc'=>$knowledgeCutoffUtc,
                'capture_digest'=>$digest,
                'current_source_policy_digest'=>$policy['policy_digest'],
                'owner_native_metadata_verified'=>true,
                'retained_bytes_verified_privately'=>true,
                'source_bytes_returned'=>false,
                'U03_historical_use_admitted'=>false,
                'provider_disclosure_authorized'=>false,
                'M17_input_set_authorized'=>false,
                'runtime_persistence_authorized'=>false,
            ]);
        } catch (\Throwable) {
            // Do not print SQL, identities or private bytes in diagnostic failures.
            return Outcome::failure('M17_HISTORICAL_NATIVE_PREFLIGHT_DEPENDENCY_FAILED');
        }
    }

    private static function utc24(mixed $value): bool
    {
        if (!is_string($value)
            || preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/D',$value)!==1) return false;
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z',$value,new \DateTimeZone('UTC'));
        return $d!==false && $d->format('Y-m-d\TH:i:s.v\Z')===$value;
    }
}
