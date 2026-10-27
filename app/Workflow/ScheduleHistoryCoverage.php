<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Digest,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P01\ObservationCoverage;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * LI-05E: exact, read-only source/schedule/occurrence/RunJournal coverage proof.
 *
 * This is NOT an alternative scheduler and confers NO M05/U03 historical-use
 * rights. An OWNER-ADMITTED *current* ScheduleService version is required. A
 * revoked/expired/ambiguous plan cannot authenticate a historical basis here.
 *
 * Completeness is narrower than "we have all captured snapshots":
 * every expected slot must be successfully observed, with a pinned P01
 * snapshot and M05 private-artifact binding, in one frozen schedule version.
 * Skipped, refused, pending or unreported slots leave the basis UNKNOWN.
 */
final class ScheduleHistoryCoverage
{
    public const VERSION = 'li05e.schedule-owner-coverage.v1';

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly ScheduleService $schedules,
    ) {}

    /**
     * @param array<string,mixed> $actor exact M02 principal scope
     * @param array<string,string> $period half-open UTC/ISO seconds
     */
    public function inspect(
        array $actor, string $competitorId, array $period,
        string $knowledgeCutoffUtc, string $scheduleVersionId,
    ): Outcome {
        if (!Vocabulary::exactKeys($actor,['tenant_id','workspace_id','actor_type','actor_id'])
            || !in_array($actor['actor_type']??null,['OPERATOR','SERVICE'],true)
            || !Vocabulary::id($actor['tenant_id']??null)
            || !Vocabulary::id($actor['workspace_id']??null)
            || !Vocabulary::id($actor['actor_id']??null)
            || !Vocabulary::id($competitorId) || !Vocabulary::id($scheduleVersionId)
            || !Vocabulary::exactKeys($period,['start_inclusive_utc','end_exclusive_utc'])
            || ($start=self::secondMs($period['start_inclusive_utc']??null))===null
            || ($end=self::secondMs($period['end_exclusive_utc']??null))===null
            || ($cutoff=self::secondMs($knowledgeCutoffUtc))===null
            || $start >= $end
            || $cutoff > self::canonicalMs($this->clock->now())) {
            return Outcome::refuse('LI05E_COVERAGE_SELECTOR_INVALID');
        }
        try {
            foreach(['competition_monitoring.read','evidence.read'] as $permission){
                $access=(new AccessGuard($this->db,$this->clock))->evaluate(
                    $actor['actor_type'],$actor['actor_id'],$actor['tenant_id'],
                    $actor['workspace_id'],$permission,'LI05E_SCHEDULE_COVERAGE',$competitorId,
                );
                if(($access['decision']??null)!=='ALLOW'){
                    return Outcome::refuse('LI05E_COVERAGE_SCOPE_DENIED');
                }
            }
            $current=$this->schedules->current($scheduleVersionId);
            if(!$current->isOk()){
                return Outcome::refuse('LI05E_SCHEDULE_OWNER_NOT_CURRENT');
            }
            $v=$current->value();
            if (($v['tenant_id']??null)!==$actor['tenant_id']
                || ($v['workspace_id']??null)!==$actor['workspace_id']
                || !is_string($v['schedule_digest']??null)
                || !hash_equals($v['schedule_digest'],
                    ScheduleService::digest($actor['tenant_id'],$actor['workspace_id'],$v['definition']))
                || ($v['catch_up_policy']??null)!==ScheduleService::CATCH_UP_POLICY
                || ($v['observation_mode']??null)!==ScheduleService::OBSERVATION_MODE
                || !is_int($cadence=(int)($v['cadence_seconds']??0))
                || $cadence<ScheduleService::MIN_CADENCE_SECONDS
                || $cadence>ScheduleService::MAX_CADENCE_SECONDS) {
                return Outcome::refuse('LI05E_SCHEDULE_IDENTITY_MISMATCH');
            }
            // No inferred cross-source completeness: one exact P01 source must
            // be the entire monitored-source population for this competitor.
            $sources=$this->db->all(
                'SELECT monitored_source_id,status FROM monitored_sources
                 WHERE tenant_id=? AND workspace_id=? AND competitor_id=?
                 ORDER BY monitored_source_id LIMIT 2',
                [$actor['tenant_id'],$actor['workspace_id'],$competitorId],
            );
            if(count($sources)!==1 || $sources[0]['status']!=='ACTIVE'
                || $sources[0]['monitored_source_id']!==$v['monitored_source_id']){
                return Outcome::refuse('LI05E_COMPETITOR_SOURCE_SCOPE_AMBIGUOUS');
            }

            $validFrom=self::canonicalMs($v['valid_from']??null);
            $validUntil=self::canonicalMs($v['valid_until']??null);
            if($validFrom===null || $validUntil===null || $start<$validFrom
                || $end>$validUntil || $validUntil<=$validFrom){
                return Outcome::refuse('LI05E_PERIOD_OUTSIDE_FROZEN_SCHEDULE');
            }
            $admission=$this->db->one(
                "SELECT recorded_at FROM monitor_schedule_events
                 WHERE monitor_schedule_version_id=? AND event_kind='ADMITTED'
                 ORDER BY sequence_no DESC LIMIT 1",[$scheduleVersionId],
            );
            if($admission===null
                || ($admittedAt=self::canonicalMs($admission['recorded_at']??null))===null
                || $admittedAt>=$cutoff){
                return Outcome::refuse('LI05E_SCHEDULE_NOT_ADMITTED_AT_CUTOFF');
            }

            $cadenceMs=$cadence*1000;
            $first=max(0,self::ceilDiv($start-$validFrom,$cadenceMs));
            // An outcome at EXACTLY cutoff is not yet known at that cutoff.
            $effectiveEnd=min($end,$cutoff);
            $lastExclusive=max($first,self::ceilDiv($effectiveEnd-$validFrom,$cadenceMs));
            $planned=max(0,$lastExclusive-$first);
            if ($planned>ObservationCoverage::MAX_PLANNED_SLOTS
                || $lastExclusive>(int)$v['max_occurrences']){
                return Outcome::refuse('LI05E_COVERAGE_PLAN_BUDGET_EXHAUSTED');
            }
            $occurrences=$this->db->all(
                'SELECT o.monitor_schedule_occurrence_id,o.slot_index,o.skipped_slots,
                        o.scheduled_for,o.reserved_at,o.tenant_id,o.workspace_id,
                        x.outcome,x.run_stage,x.recorded_at AS outcome_recorded_at,
                        x.recorded_by_service_identity_id,x.monitor_run_id
                 FROM monitor_schedule_occurrences o
                 LEFT JOIN monitor_schedule_occurrence_outcomes x
                   ON x.monitor_schedule_occurrence_id=o.monitor_schedule_occurrence_id
                 WHERE o.monitor_schedule_version_id=? AND o.tenant_id=?
                   AND o.workspace_id=? AND o.slot_index>=? AND o.slot_index<?
                 ORDER BY o.slot_index,o.monitor_schedule_occurrence_id',
                [$scheduleVersionId,$actor['tenant_id'],$actor['workspace_id'],$first,$lastExclusive],
            );
            $coverageRows=[];$snapshotIds=[];$seen=[];
            $journal=new RunJournal($this->db,$this->clock);
            foreach($occurrences as $o){
                $index=(int)$o['slot_index'];
                if(isset($seen[$index])){
                    return Outcome::refuse('LI05E_DUPLICATE_OCCURRENCE_SLOT');
                }
                $seen[$index]=true;
                $scheduledMs=self::canonicalMs($o['scheduled_for']??null);
                $reservedMs=self::canonicalMs($o['reserved_at']??null);
                if($scheduledMs===null || $reservedMs===null
                    || $scheduledMs!==$validFrom+$index*$cadenceMs
                    || (int)$o['skipped_slots']<0
                    || $o['tenant_id']!==$actor['tenant_id']
                    || $o['workspace_id']!==$actor['workspace_id']){
                    return Outcome::refuse('LI05E_OCCURRENCE_LEDGER_INTEGRITY_HOLD');
                }
                if($reservedMs >= $cutoff){
                    // This row did not yet exist at the requested knowledge cut.
                    // Its later skipped count cannot rewrite the past either.
                    continue;
                }
                $skipStart=max($first,$index-(int)$o['skipped_slots']);
                $skipped=max(0,$index-$skipStart);
                $kind='PENDING';
                $outcomeKnown=$o['outcome']!==null
                    && ($outcomeAt=self::canonicalMs($o['outcome_recorded_at']??null))!==null
                    && $outcomeAt<$cutoff;
                if($o['outcome']!==null && self::canonicalMs($o['outcome_recorded_at']??null)===null){
                    return Outcome::refuse('LI05E_OUTCOME_CLOCK_INVALID');
                }
                if($outcomeKnown){
                    if($o['recorded_by_service_identity_id']!==$v['service_identity_id']){
                        return Outcome::refuse('LI05E_OUTCOME_SERVICE_MISMATCH');
                    }
                    $kind=match($o['outcome']){
                        'BASELINE_RECORDED'=>'CAPTURED_BASELINE',
                        'NO_CHANGE'=>'CAPTURED_UNCHANGED',
                        'WAITING_FOR_OPERATOR'=>'CAPTURED_CHANGED',
                        'BLOCKED','REFUSED'=>'REFUSED',
                        default=>'PENDING',
                    };
                    if(str_starts_with($kind,'CAPTURED_')){
                        $runId=$o['monitor_run_id'];
                        if(!Vocabulary::id($runId)){
                            return Outcome::refuse('LI05E_OUTCOME_RUN_MISSING');
                        }
                        $replay=$journal->replay($actor,$runId);
                        if(!$replay->isOk() || ($replay->value()['integrity']??null)!=='PASS'){
                            return Outcome::refuse('LI05E_RUN_JOURNAL_NOT_REPLAYABLE');
                        }
                        $run=$replay->value()['run'];
                        if(($run['tenant_id']??null)!==$actor['tenant_id']
                            || ($run['workspace_id']??null)!==$actor['workspace_id']
                            || ($run['mission_id']??null)!==$v['mission_id']
                            || ($run['initiated_by_service_identity_id']??null)!==$v['service_identity_id']
                            || ($run['initiated_by_operator_id']??null)!==$v['delegating_operator_id']
                            || ($run['idempotency_key']??null)!==ScheduleService::runKey($o['monitor_schedule_occurrence_id'])){
                            return Outcome::refuse('LI05E_RUN_OWNER_SCOPE_MISMATCH');
                        }
                        $captured=[];
                        foreach($replay->value()['events'] as $event){
                            if(($event['event_kind']??null)!=='SOURCE_CAPTURED')continue;
                            if(($at=self::canonicalMs($event['recorded_at']??null))===null
                                || $at>=$cutoff){
                                continue;
                            }
                            $id=$event['payload']['cursor']['capture']['after_snapshot_id']??null;
                            if(!Vocabulary::id($id))return Outcome::refuse('LI05E_CAPTURE_EVENT_INVALID');
                            $captured[]=$id;
                        }
                        if(count($captured)!==1){
                            return Outcome::refuse('LI05E_CAPTURE_EVENT_AMBIGUOUS');
                        }
                        $snapshot=$this->db->one(
                            'SELECT s.source_snapshot_id,s.captured_at,
                                    p.created_at AS payload_bound_at
                             FROM source_snapshots s
                             JOIN monitor_capture_payloads p ON p.source_snapshot_id=s.source_snapshot_id
                             WHERE s.source_snapshot_id=? AND s.monitored_source_id=?
                               AND s.tenant_id=? AND s.workspace_id=?',
                            [$captured[0],$v['monitored_source_id'],$actor['tenant_id'],$actor['workspace_id']],
                        );
                        if($snapshot===null
                            || ($capturedAt=self::canonicalMs($snapshot['captured_at']??null))===null
                            || ($payloadAt=self::canonicalMs($snapshot['payload_bound_at']??null))===null
                            || max($capturedAt,$payloadAt)>=$cutoff
                            || isset($snapshotIds[$captured[0]])){
                            return Outcome::refuse('LI05E_CAPTURE_BINDING_INVALID');
                        }
                        $snapshotIds[$captured[0]]=true;
                    }
                }
                $coverageRows[]=['slot_index'=>$index-$first,
                    'outcome'=>$kind,'skipped_slots'=>$skipped];
            }
            $computed=ObservationCoverage::summarize($scheduleVersionId,$planned,$coverageRows);
            if(!$computed->isOk())return $computed;
            $summary=$computed->value();
            $complete=$planned>0 && $summary['not_successfully_observed_slots']===0
                && count($snapshotIds)===$planned;
            // KNOWN only means the exact scheduled-slot history can be proved
            // complete at this cutoff, never that sources may be reused (U03).
            $coverageForBasis=$complete?[
                'state'=>'KNOWN',
                'plan_version_id'=>$scheduleVersionId,
                'planned_slots'=>$planned,
                'captured'=>$summary['captured_slots'],
                'unchanged'=>$summary['structurally_unchanged_captures'],
                'failed'=>0,'skipped'=>0,'unreported'=>0,
            ]:['state'=>'UNKNOWN'];
            $receipt=[
                'version'=>self::VERSION,
                'scope'=>[
                    'tenant_id'=>$actor['tenant_id'],
                    'workspace_id'=>$actor['workspace_id'],
                    'competitor_id'=>$competitorId,
                ],
                'schedule_version_id'=>$scheduleVersionId,
                'schedule_digest'=>$v['schedule_digest'],
                'source_id'=>$v['monitored_source_id'],
                'period'=>$period,
                'knowledge_cutoff_utc'=>$knowledgeCutoffUtc,
                'planned_slots'=>$planned,
                'summary'=>$summary,
                'captured_snapshot_ids'=>array_keys($snapshotIds),
                'status'=>$complete?'COMPLETE_OWNER_SLOT_COVERAGE':'HOLD_GAPPED_OR_INCOMPLETE',
                'basis_coverage'=>$coverageForBasis,
                'owner_schedule_current_verified'=>true,
                'U03_historical_use_admitted'=>false,
                'model_call_authorized'=>false,
                'scheduler_action_authorized'=>false,
                'customer_output_authorized'=>false,
            ];
            return Outcome::ok([
                ...$receipt,
                'receipt_digest'=>Digest::canonicalDigest($receipt),
            ]);
        }catch(\Throwable){
            return Outcome::failure('LI05E_COVERAGE_DEPENDENCY_FAILED');
        }
    }

    /** @return ?int Exact UTC millisecond epoch from a 20-char ISO second. */
    private static function secondMs(mixed $second):?int
    {
        if(!HistoricalEvidenceSelector::utc($second))return null;
        return self::canonicalMs(substr($second,0,19).'.000Z');
    }

    /** @return ?int Exact UTC millisecond epoch from a canonical clock. */
    private static function canonicalMs(mixed $clock):?int
    {
        if(!is_string($clock) || Clock::parse($clock)===null)return null;
        $d=Clock::parse($clock);
        return (int)$d->format('U')*1000+(int)$d->format('v');
    }

    private static function ceilDiv(int $numerator,int $denominator):int
    {
        return $numerator<=0?0:intdiv($numerator+$denominator-1,$denominator);
    }
}
