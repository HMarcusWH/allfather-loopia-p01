<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Digest,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M17\{HistoricalCaptureProbe,Vocabulary};

/**
 * LI-05D: owner-native, read-only P01/M05 capture-to-history preflight.
 *
 * An authenticated historical capture is NOT an authorized historical use.
 * Even the verified result is INSUFFICIENT_BASIS because P01 snapshots alone
 * cannot prove an exhaustive scheduled-capture or source-coverage ledger.
 *
 * No raw bytes, model inputs, provider disclosure, schedule, or M17 run is
 * returned or authorized. No HTTP/CLI route or runtime factory is registered.
 */
final class NativeCaptureHistoryCandidate
{
    public const VERSION = 'li05d.owner-native-history-preflight.v1';

    private readonly HistoricalCaptureProbe $probe;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        EvidenceStore $privateStorage,
        private readonly ?ScheduleHistoryCoverage $ownerCoverage = null,
    ) {
        $this->probe = new HistoricalCaptureProbe($db, $clock, $privateStorage);
    }

    /**
     * @param array<string,mixed> $actor Existing M02 operator/service scope
     * @param array<string,string> $period Closed UTC seconds, half-open [start,end)
     */
    public function preview(
        array $actor,
        string $competitorId,
        string $questionId,
        array $period,
        string $knowledgeCutoffUtc,
        int $maxReferences = HistoricalEvidenceSelector::MAX_REFERENCES,
        ?string $scheduleVersionId = null,
    ): Outcome {
        if (!Vocabulary::exactKeys($actor, ['tenant_id','workspace_id','actor_type','actor_id'])
            || !in_array($actor['actor_type'] ?? null, ['OPERATOR','SERVICE'], true)
            || !Vocabulary::id($actor['tenant_id'] ?? null)
            || !Vocabulary::id($actor['workspace_id'] ?? null)
            || !Vocabulary::id($actor['actor_id'] ?? null)
            || !Vocabulary::id($competitorId)
            || !Vocabulary::id($questionId)
            || !Vocabulary::exactKeys($period, ['start_inclusive_utc','end_exclusive_utc'])
            || !HistoricalEvidenceSelector::utc($period['start_inclusive_utc'] ?? null)
            || !HistoricalEvidenceSelector::utc($period['end_exclusive_utc'] ?? null)
            || $period['start_inclusive_utc'] >= $period['end_exclusive_utc']
            || !HistoricalEvidenceSelector::utc($knowledgeCutoffUtc)
            || ($scheduleVersionId !== null && !Vocabulary::id($scheduleVersionId))
            || $maxReferences < 1
            || $maxReferences > HistoricalEvidenceSelector::MAX_REFERENCES) {
            return Outcome::refuse('LI05D_NATIVE_BASIS_SELECTOR_INVALID');
        }
        $now = $this->clock->now();
        // PHP DateTime normalizes UTC millisecond and second serializations.
        // Comparing 20-char vs 24-char timestamps lexically is NOT equivalent.
        if (strtotime($knowledgeCutoffUtc) > strtotime($now)) {
            return Outcome::refuse('LI05D_NATIVE_BASIS_FUTURE_CUTOFF');
        }

        try {
            $access = new AccessGuard($this->db, $this->clock);
            foreach (['competition_monitoring.read','evidence.read'] as $permission) {
                $grant = $access->evaluate(
                    $actor['actor_type'], $actor['actor_id'],
                    $actor['tenant_id'], $actor['workspace_id'],
                    $permission, 'M17_NATIVE_HISTORY_PREFLIGHT', $competitorId,
                );
                if (($grant['decision'] ?? null) !== 'ALLOW') {
                    return Outcome::refuse('LI05D_NATIVE_BASIS_NOT_AUTHORIZED');
                }
            }
            $exists = $this->db->one(
                'SELECT competitor_id FROM competitors
                 WHERE competitor_id=? AND tenant_id=? AND workspace_id=?
                   AND status IN (?,?)',
                [$competitorId,$actor['tenant_id'],$actor['workspace_id'],'ACTIVE','PAUSED'],
            );
            if ($exists === null) {
                return Outcome::refuse('LI05D_NATIVE_BASIS_NOT_FOUND');
            }

            // Read original P01 owner metadata, not unrelated M05 objects.
            // Don't filter on current source/card status: a revoked/quarantined
            // capture must produce HOLD, not disappear from the counted basis.
            $captures = $this->db->all(
                'SELECT s.source_snapshot_id
                 FROM source_snapshots s
                 JOIN monitored_sources m ON m.monitored_source_id=s.monitored_source_id
                   AND m.tenant_id=s.tenant_id AND m.workspace_id=s.workspace_id
                 WHERE s.tenant_id=? AND s.workspace_id=? AND m.competitor_id=?
                   AND s.captured_at >= ? AND s.captured_at < ?
                 ORDER BY s.captured_at,s.source_snapshot_id LIMIT 257',
                [
                    $actor['tenant_id'],$actor['workspace_id'],$competitorId,
                    self::millisecondBoundary($period['start_inclusive_utc']),
                    self::millisecondBoundary($period['end_exclusive_utc']),
                ],
            );
            if (count($captures) > HistoricalEvidenceSelector::MAX_INPUT_ROWS) {
                return Outcome::refuse('LI05D_NATIVE_CAPTURE_INPUT_CAP_HOLD');
            }

            $scope = [
                'tenant_id'=>$actor['tenant_id'],'workspace_id'=>$actor['workspace_id'],
                'competitor_id'=>$competitorId,'question_id'=>$questionId,
            ];
            $rows = [];
            $proofs = [];
            foreach ($captures as $capture) {
                $snapshotId = (string)($capture['source_snapshot_id'] ?? '');
                if (!Vocabulary::id($snapshotId)) {
                    return Outcome::refuse('LI05D_NATIVE_CAPTURE_IDENTITY_HOLD');
                }
                // Current M02/M05/P01/source-card checks and actual private byte
                // verification are all performed again on EVERY inspected ref.
                // We inspect at current wall time, *not* the historical cutoff;
                // the selector applies the historical known-at law afterward.
                $verified = $this->probe->inspect($actor,$competitorId,$snapshotId,$now);
                if (!$verified->isOk()) {
                    return $verified->isFailure()
                        ? Outcome::failure('LI05D_NATIVE_CAPTURE_DEPENDENCY_FAILED')
                        : Outcome::refuse('LI05D_NATIVE_CAPTURE_HOLD', [
                            'reason_code'=>$verified->reasonCode(),
                        ]);
                }
                $item = $verified->value();
                if (($item['status'] ?? null) !== 'NATIVE_HISTORY_PREFLIGHT_VERIFIED_BUT_U03_HOLD'
                    || ($item['retained_bytes_verified_privately'] ?? null) !== true
                    || ($item['owner_native_metadata_verified'] ?? null) !== true
                    || ($item['U03_historical_use_admitted'] ?? null) !== false
                    || ($item['provider_disclosure_authorized'] ?? null) !== false
                    || ($item['source_snapshot_id'] ?? null) !== $snapshotId
                    || !Vocabulary::id($item['evidence_id'] ?? null)
                    || !Vocabulary::id($item['source_ref_id'] ?? null)
                    || !is_string($item['capture_digest'] ?? null)
                    || preg_match('/^[a-f0-9]{64}$/D',$item['capture_digest'])!==1) {
                    return Outcome::refuse('LI05D_NATIVE_CAPTURE_PROOF_INVALID');
                }
                $observed = self::floorUtcSecond($item['observed_at_utc']);
                $recorded = self::ceilUtcSecond($item['observed_at_utc']);
                $known = self::ceilUtcSecond($item['known_at_upper_bound_utc']);
                if ($observed === null || $recorded === null || $known === null
                    || $observed > $recorded || $recorded > $known) {
                    return Outcome::refuse('LI05D_NATIVE_CAPTURE_TIME_HOLD');
                }
                $rows[] = [
                    'tenant_id'=>$actor['tenant_id'],
                    'workspace_id'=>$actor['workspace_id'],
                    'competitor_id'=>$competitorId,
                    'question_id'=>$questionId,
                    'ref_id'=>$snapshotId,
                    'source_ref_id'=>$item['source_ref_id'],
                    'evidence_id'=>$item['evidence_id'],
                    'snapshot_id'=>$snapshotId,
                    'assessment_id'=>null,
                    'payload_sha256'=>$item['capture_digest'],
                    'event_at'=>null,            // no inferred business-event date
                    'observed_at'=>$observed,   // physical capture clock
                    'recorded_at'=>$recorded,   // conservative serialized bound
                    'known_at'=>$known,         // actual M05/file binding upper bound
                    'analyzed_at'=>null,
                    'semantic_polarity'=>'UNKNOWN',
                    'origin_group'=>'UNKNOWN', // shared source != independent event
                    'reported_disposition'=>'UNASSESSED',
                    'provenance'=>'M05_CAPTURE_METADATA',
                ];
                $proofs[] = [
                    'source_snapshot_id'=>$snapshotId,
                    'capture_digest'=>$item['capture_digest'],
                    'source_policy_digest'=>$item['current_source_policy_digest'],
                    'known_at_upper_bound_utc'=>$item['known_at_upper_bound_utc'],
                    'source_bytes_returned'=>false,
                ];
            }

            // Default behaviour retains LI-05D's UNKNOWN coverage. An optional
            // LI-05E composition can upgrade *slot accounting only* when the
            // current owner-admitted schedule, immutable ledger and exact
            // M05/P01 capture identities all agree for this knowledge cutoff.
            $basisCoverage=['state'=>'UNKNOWN'];
            $scheduleReport=null;
            $complete=false;
            if($scheduleVersionId!==null){
                if($this->ownerCoverage===null){
                    return Outcome::refuse('LI05E_SCHEDULE_OWNER_READER_REQUIRED');
                }
                $check=$this->ownerCoverage->inspect(
                    $actor,$competitorId,$period,$knowledgeCutoffUtc,$scheduleVersionId,
                );
                if(!$check->isOk())return $check;
                $receipt=$check->value();
                if(($receipt['U03_historical_use_admitted']??null)!==false
                    || ($receipt['model_call_authorized']??null)!==false
                    || ($receipt['scheduler_action_authorized']??null)!==false){
                    return Outcome::refuse('LI05E_COVERAGE_RECEIPT_AUTHORITY_INVALID');
                }
                // Keep the owner's full gap counts available without placing
                // its internal per-capture identities into a UI response.
                $scheduleReport=[
                    'version'=>$receipt['version'],
                    'schedule_version_id'=>$receipt['schedule_version_id'],
                    'schedule_digest'=>$receipt['schedule_digest'],
                    'planned_slots'=>$receipt['planned_slots'],
                    'status'=>$receipt['status'],
                    'summary'=>$receipt['summary'],
                    'receipt_digest'=>$receipt['receipt_digest'],
                    'source_coverage_complete'=>false,
                ];
                if($receipt['status']==='COMPLETE_OWNER_SLOT_COVERAGE'){
                    $scheduled=$receipt['captured_snapshot_ids'];
                    $historicallyKnown=[];
                    foreach($rows as $row){
                        if($row['observed_at']>=$period['start_inclusive_utc']
                            && $row['observed_at']<$period['end_exclusive_utc']
                            && $row['known_at']<=$knowledgeCutoffUtc){
                            $historicallyKnown[]=$row['snapshot_id'];
                        }
                    }
                    sort($historicallyKnown,SORT_STRING);
                    sort($scheduled,SORT_STRING);
                    if($historicallyKnown!==$scheduled
                        || count($historicallyKnown)>$maxReferences){
                        return Outcome::refuse('LI05E_CAPTURE_SET_DOES_NOT_MATCH_OWNER_SLOTS');
                    }
                    $basisCoverage=$receipt['basis_coverage'];
                    $complete=true;
                    $scheduleReport['source_coverage_complete']=true;
                }
            }
            $frozen = HistoryReviewBasis::freeze([
                'scope'=>$scope,
                'period'=>$period,
                'baseline_period'=>null,
                'baseline_null_reason'=>'NOT_REQUESTED',
                'knowledge_cutoff_utc'=>$knowledgeCutoffUtc,
                'rows'=>$rows,
                'max_references'=>$maxReferences,
                'coverage'=>$basisCoverage,
            ]);
            if (!$frozen->isOk()) return $frozen;
            $manifest = $frozen->value();
            if (($manifest['historical_reuse_admitted'] ?? null) !== false
                || ($manifest['rights_disposition'] ?? null) !== 'NOT_ASSESSED'
                || ($complete && $manifest['selection_integrity']!=='COMPLETE_WITHIN_INPUT')
                || (!$complete && $manifest['selection_integrity']!=='INSUFFICIENT_BASIS')){
                return Outcome::refuse('LI05E_BASIS_INTEGRITY_OR_AUTHORITY_INVALID');
            }
            return Outcome::ok([
                'version'=>self::VERSION,
                'status'=>'OWNER_NATIVE_CAPTURE_METADATA_VERIFIED_U03_HOLD',
                'history_basis'=>$manifest,
                'proof_manifest_digest'=>Digest::canonicalDigest($proofs),
                'verified_capture_count'=>count($proofs),
                'owner_provenance_verified'=>true,
                'source_coverage_proven'=>$complete,
                'schedule_coverage_report'=>$scheduleReport,
                'source_bytes_returned'=>false,
                'U03_historical_use_admitted'=>false,
                'U06_model_context_authorized'=>false,
                'M17_input_set_authorized'=>false,
                'scheduler_action_authorized'=>false,
                'customer_output_authorized'=>false,
            ]);
        } catch (\Throwable) {
            // No SQL/exception message, source bytes or URL in external errors.
            return Outcome::failure('LI05D_OWNER_NATIVE_PREVIEW_DEPENDENCY_FAILED');
        }
    }

    private static function millisecondBoundary(string $second): string
    {
        return substr($second,0,19).'.000Z';
    }

    private static function floorUtcSecond(mixed $instant): ?string
    {
        if (!self::utcMillis($instant)) return null;
        return substr($instant,0,19).'Z';
    }

    private static function ceilUtcSecond(mixed $instant): ?string
    {
        if (!self::utcMillis($instant)) return null;
        try {
            $date = \DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i:s.v\Z', $instant,new \DateTimeZone('UTC'),
            );
            if ($date === false || $date->format('Y-m-d\TH:i:s.v\Z')!==$instant) return null;
            if ((int)$date->format('v')!==0) $date=$date->modify('+1 second');
            return $date->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function utcMillis(mixed $instant): bool
    {
        return is_string($instant)
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D',$instant)===1;
    }
}
