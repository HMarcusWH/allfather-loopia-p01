<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P04;

use Allfather\Loopia\Core\{Clock,Digest,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * AQ-02 disposable family340 append-only candidate ledger.
 * Not M05/M10 owner truth, not P04 distribution, not an external delivery log.
 */
final class InternalAttentionStore
{
    public const VERSION='aq02.disposable-internal-attention-store.v1';

    private function __construct(
        private readonly Database $db,private readonly Clock $clock,
    ) {}

    public static function forDisposableTest(Database $db,Clock $clock):Outcome
    {
        if(PHP_SAPI!=='cli'
            || !defined('ALLFATHER_AQ02_DISPOSABLE_SCHEMA_LOADED')
            || constant('ALLFATHER_AQ02_DISPOSABLE_SCHEMA_LOADED')!==true){
            return Outcome::refuse('AQ02_FAMILY340_RUNTIME_NOT_ADOPTED');
        }
        return Outcome::ok(new self($db,$clock));
    }

    public static function forRuntime(Database $db,Clock $clock,array $claimedOwner=[]):Outcome
    {
        return Outcome::refuse('AQ02_U07_U09_RUNTIME_OWNER_NOT_ADOPTED');
    }

    public function recordAttention(array $v):Outcome
    {
        if(!InternalAttention::verify($v)->isOk()){
            return Outcome::refuse('AQ02_ATTENTION_CANNOT_BE_RECORDED');
        }
        $scope=$v['scope'];$json=self::json($v);
        try{
            return $this->db->transaction(function(Database $tx)use($v,$scope,$json):Outcome{
                if(!$this->scopeExists($tx,$scope)){
                    return Outcome::refuse('AQ02_ATTENTION_SCOPE_NOT_REGISTERED');
                }
                $existing=$tx->one(
                    'SELECT history_internal_attention_candidate_id,attention_json
                     FROM history_internal_attention_candidates
                     WHERE tenant_id=? AND workspace_id=?
                       AND attention_digest=? FOR UPDATE',
                    [$scope['tenant_id'],$scope['workspace_id'],$v['attention_digest']],
                );
                if($existing!==null){
                    if(!hash_equals((string)$existing['attention_json'],$json)){
                        return Outcome::refuse('AQ02_ATTENTION_REPLAY_DIGEST_CONFLICT');
                    }
                    return Outcome::ok([
                        'action'=>'EXACT_ATTENTION_REPLAY_NO_WRITE',
                        'history_internal_attention_candidate_id'=>$existing['history_internal_attention_candidate_id'],
                        'real_notification_sent'=>false,
                    ]);
                }
                $previous=$tx->one(
                    'SELECT attention_digest FROM history_internal_attention_candidates
                     WHERE tenant_id=? AND workspace_id=? AND target_org_role=?
                       AND attention_dedup_key=? AND event_status=?
                     LIMIT 1 FOR UPDATE',
                    [$scope['tenant_id'],$scope['workspace_id'],
                     $v['target_org_role'],$v['attention_dedup_key'],
                     'ATTENTION_CANDIDATE_INTERNAL_ONLY'],
                );
                if($previous!==null
                    && $v['status']==='ATTENTION_CANDIDATE_INTERNAL_ONLY'){
                    return Outcome::refuse('AQ02_REPEAT_ATTENTION_REQUIRES_SUPPRESSION');
                }
                $id=Ids::new();$small=['tenant_id'=>$scope['tenant_id'],
                    'workspace_id'=>$scope['workspace_id']];
                $tx->insert('history_internal_attention_candidates',[
                    'history_internal_attention_candidate_id'=>$id,
                    ...$small,
                    'competitor_id'=>$scope['competitor_id'],
                    'question_id'=>$scope['question_id'],
                    'source_kind'=>$v['source_kind'],
                    'source_id'=>$v['source_id'],
                    'source_decision_record_id'=>$v['source_decision_record_id'],
                    'target_org_role'=>$v['target_org_role'],
                    'attention_dedup_key'=>$v['attention_dedup_key'],
                    'attention_digest'=>$v['attention_digest'],
                    'event_status'=>$v['status'],
                    'reason_code'=>$v['reason_code'],
                    'attention_json'=>$json,
                    'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                    'created_at'=>$this->clock->now(),
                ]);
                $suppressed=$v['suppression_reason']!==null;
                if($suppressed){
                    $digest=Digest::canonicalDigest([
                        'attention_digest'=>$v['attention_digest'],
                        'suppression_reason'=>$v['suppression_reason'],
                    ]);
                    $tx->insert('history_attention_suppression_events',[
                        'history_attention_suppression_event_id'=>Ids::new(),
                        'history_internal_attention_candidate_id'=>$id,
                        ...$small,'attention_digest'=>$v['attention_digest'],
                        'suppression_reason'=>$v['suppression_reason'],
                        'suppression_event_digest'=>$digest,
                        'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                        'created_at'=>$this->clock->now(),
                    ]);
                }
                return Outcome::ok([
                    'action'=>'DISPOSABLE_ATTENTION_RECORDED',
                    'history_internal_attention_candidate_id'=>$id,
                    'suppression_event_recorded'=>$suppressed,
                    'notification_or_distribution_sent'=>false,
                ]);
            });
        }catch(\PDOException $e){
            return Database::classify($e,'AQ02_ATTENTION_APPEND_RACE_HOLD');
        }catch(\Throwable){
            return Outcome::failure('AQ02_DISPOSABLE_ATTENTION_STORAGE_FAILED');
        }
    }

    /** Bind all digest members and suppressions to real candidate parent rows. */
    public function recordDigest(array $policy,array $events,array $digest):Outcome
    {
        if(!AttentionPolicy::verify($policy)->isOk()
            || !InternalDigest::verify($digest)->isOk()
            || !is_array($events) || !array_is_list($events)){
            return Outcome::refuse('AQ02_DIGEST_INPUT_NOT_REPLAYABLE');
        }
        $fresh=InternalDigest::freeze([
            'policy'=>$policy,'attention_candidates'=>$events,
            'period_start_utc'=>$digest['period_start_utc'],
            'period_end_utc'=>$digest['period_end_utc'],
            'generated_at_utc'=>$digest['generated_at_utc'],
        ]);
        if(!$fresh->isOk() || $fresh->value()!==$digest){
            return Outcome::refuse('AQ02_DIGEST_MEMBERSHIP_NOT_RECONSTRUCTIBLE');
        }
        $scope=$digest['scope'];$json=self::json($digest);
        try{
            return $this->db->transaction(function(Database $tx)use(
                $scope,$events,$digest,$json,
            ):Outcome{
                if(!$this->scopeExists($tx,$scope)){
                    return Outcome::refuse('AQ02_DIGEST_SCOPE_NOT_REGISTERED');
                }
                $existing=$tx->one(
                    'SELECT history_internal_digest_candidate_id,digest_json
                     FROM history_internal_digest_candidates
                     WHERE tenant_id=? AND workspace_id=? AND digest=?
                     FOR UPDATE',
                    [$scope['tenant_id'],$scope['workspace_id'],$digest['digest']],
                );
                if($existing!==null){
                    if(!hash_equals((string)$existing['digest_json'],$json)){
                        return Outcome::refuse('AQ02_DIGEST_REPLAY_CONFLICT');
                    }
                    return Outcome::ok([
                        'action'=>'EXACT_DIGEST_REPLAY_NO_WRITE',
                        'history_internal_digest_candidate_id'=>$existing['history_internal_digest_candidate_id'],
                        'external_delivery_authorized'=>false,
                    ]);
                }
                $eventIds=[];
                foreach($events as $event){
                    $row=$tx->one(
                        'SELECT history_internal_attention_candidate_id,attention_json
                         FROM history_internal_attention_candidates
                         WHERE tenant_id=? AND workspace_id=?
                           AND attention_digest=? AND candidate_state=?
                         FOR UPDATE',
                        [$scope['tenant_id'],$scope['workspace_id'],
                         $event['attention_digest'],'TEST_ONLY_NOT_ADMITTED'],
                    );
                    if($row===null || !hash_equals(
                        (string)$row['attention_json'],self::json($event))){
                        return Outcome::refuse('AQ02_DIGEST_PARENT_EVENT_NOT_SAME_SCOPE');
                    }
                    $eventIds[$event['attention_digest']]=$row['history_internal_attention_candidate_id'];
                }
                $id=Ids::new();$small=[
                    'tenant_id'=>$scope['tenant_id'],
                    'workspace_id'=>$scope['workspace_id'],
                ];
                $tx->insert('history_internal_digest_candidates',[
                    'history_internal_digest_candidate_id'=>$id,
                    ...$small,
                    'competitor_id'=>$scope['competitor_id'],
                    'question_id'=>$scope['question_id'],
                    'target_org_role'=>$digest['target_org_role'],
                    'digest'=>$digest['digest'],
                    'digest_status'=>$digest['status'],
                    'digest_json'=>$json,
                    'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                    'created_at'=>$this->clock->now(),
                ]);
                $memberSet=array_fill_keys(
                    array_column($digest['members'],'attention_digest'),true,
                );
                $suppressed=[];
                foreach($digest['suppression_entries'] as $entry){
                    $suppressed[$entry['attention_digest']]=$entry['reason'];
                }
                foreach($digest['input_attention_digests'] as $i=>$digestId){
                    $kind=isset($memberSet[$digestId])?'INCLUDED':'SUPPRESSED';
                    $reason=$kind==='SUPPRESSED'
                        ?($suppressed[$digestId]??null):null;
                    if($kind==='SUPPRESSED' && $reason===null){
                        return Outcome::refuse('AQ02_DIGEST_SUPPRESSION_REASON_MISSING');
                    }
                    $tx->insert('history_internal_digest_members',[
                        'history_internal_digest_member_id'=>Ids::new(),
                        'history_internal_digest_candidate_id'=>$id,
                        'history_internal_attention_candidate_id'=>$eventIds[$digestId],
                        ...$small,
                        'attention_digest'=>$digestId,
                        'member_kind'=>$kind,
                        'suppression_reason'=>$reason,
                        'member_ordinal'=>$i,
                        'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                        'created_at'=>$this->clock->now(),
                    ]);
                }
                return Outcome::ok([
                    'action'=>'DISPOSABLE_INTERNAL_DIGEST_RECORDED',
                    'history_internal_digest_candidate_id'=>$id,
                    'members_saved'=>$digest['member_count'],
                    'suppressed_saved'=>$digest['suppressed_count'],
                    'p04_or_m16_released'=>false,
                ]);
            });
        }catch(\PDOException $e){
            return Database::classify($e,'AQ02_DIGEST_APPEND_RACE_HOLD');
        }catch(\Throwable){
            return Outcome::failure('AQ02_DIGEST_STORAGE_FAILED');
        }
    }

    public function replayDigest(array $scope,string $digestId):Outcome
    {
        if(!Vocabulary::scope($scope) || !Ids::isValid($digestId)){
            return Outcome::refuse('AQ02_DIGEST_REPLAY_SELECTOR_INVALID');
        }
        try{
            $row=$this->db->one(
                'SELECT digest_json FROM history_internal_digest_candidates
                 WHERE history_internal_digest_candidate_id=?
                   AND tenant_id=? AND workspace_id=?
                   AND competitor_id=? AND question_id=? AND candidate_state=?',
                [$digestId,$scope['tenant_id'],$scope['workspace_id'],
                 $scope['competitor_id'],$scope['question_id'],
                 'TEST_ONLY_NOT_ADMITTED'],
            );
            if($row===null)return Outcome::refuse('AQ02_DIGEST_NOT_IN_SCOPE');
            $digest=json_decode((string)$row['digest_json'],true,64,JSON_THROW_ON_ERROR);
            if(!is_array($digest)
                || !InternalDigest::verify($digest)->isOk()
                || $digest['scope']!==$scope){
                return Outcome::refuse('AQ02_DIGEST_STORED_BYTES_INVALID');
            }
            $rows=$this->db->all(
                'SELECT attention_digest,member_kind,suppression_reason,
                        member_ordinal FROM history_internal_digest_members
                 WHERE history_internal_digest_candidate_id=?
                   AND tenant_id=? AND workspace_id=? ORDER BY member_ordinal',
                [$digestId,$scope['tenant_id'],$scope['workspace_id']],
            );
            if(count($rows)!==$digest['all_input_count']){
                return Outcome::refuse('AQ02_DIGEST_STORED_MEMBER_COUNT_INVALID');
            }
            $incl=array_fill_keys(
                array_column($digest['members'],'attention_digest'),true,
            );
            $sup=[];
            foreach($digest['suppression_entries'] as $entry){
                $sup[$entry['attention_digest']]=$entry['reason'];
            }
            foreach($rows as $i=>$r){
                $x=$digest['input_attention_digests'][$i];
                $kind=isset($incl[$x])?'INCLUDED':'SUPPRESSED';
                if((int)$r['member_ordinal']!==$i
                    || $r['attention_digest']!==$x
                    || $r['member_kind']!==$kind
                    || $r['suppression_reason']!==($sup[$x]??null)){
                    return Outcome::refuse('AQ02_DIGEST_STORED_MEMBER_INVALID');
                }
            }
            return Outcome::ok([
                'status'=>'EXACT_INTERNAL_DIGEST_REPLAY_NO_WRITE',
                'digest'=>$digest['digest'],
                'member_count'=>$digest['member_count'],
                'suppressed_count'=>$digest['suppressed_count'],
                'current_m10_reliance_rechecked'=>false,
                'customer_visibility_authorized'=>false,
                'external_delivery_authorized'=>false,
            ]);
        }catch(\Throwable){
            return Outcome::failure('AQ02_DIGEST_REPLAY_FAILED');
        }
    }

    private function scopeExists(Database $db,array $scope):bool
    {
        return $db->one(
            'SELECT 1 FROM workspaces WHERE workspace_id=? AND tenant_id=?',
            [$scope['workspace_id'],$scope['tenant_id']],
        )!==null
            && $db->one(
                'SELECT 1 FROM competitors WHERE competitor_id=?
                   AND tenant_id=? AND workspace_id=?
                   AND status IN (?,?)',
                [$scope['competitor_id'],$scope['tenant_id'],
                 $scope['workspace_id'],'ACTIVE','PAUSED'],
            )!==null;
    }

    private static function json(array $value):string
    {
        return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }
}
