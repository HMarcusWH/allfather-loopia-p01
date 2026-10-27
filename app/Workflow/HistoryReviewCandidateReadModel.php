<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Ids};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M17\MemoryStore;

/**
 * CLI/disposable-fixture-only owner reader for the 270→280→290 candidate graph.
 * Its M02 check is real, but U03 and production review policy remain unadopted.
 * It returns no private captured bytes, full model candidates or source URLs.
 *
 * No runtime or HTTP factory. Tests inject read() as the explicitly checked
 * owner callback into HistoryReviewReadAdapter.
 */
final class HistoryReviewCandidateReadModel
{
    public const VERSION='li16.r2.disposable-owner-read-model.v1';

    private function __construct(
        private readonly Database $db, private readonly Clock $clock,
    ) {}

    public static function forDisposableTest(Database $db,Clock $clock): \Allfather\Loopia\Core\Outcome
    {
        foreach (['ALLFATHER_LI05C_DISPOSABLE_SCHEMA_LOADED',
                  'ALLFATHER_LI07B_DISPOSABLE_SCHEMA_LOADED',
                  'ALLFATHER_LI08C_DISPOSABLE_SCHEMA_LOADED'] as $name) {
            if (!defined($name) || constant($name)!==true) {
                return \Allfather\Loopia\Core\Outcome::refuse('LI16_R2_OWNER_READ_NOT_ADOPTED');
            }
        }
        if (PHP_SAPI!=='cli') {
            return \Allfather\Loopia\Core\Outcome::refuse('LI16_R2_OWNER_READ_NOT_ADOPTED');
        }
        return \Allfather\Loopia\Core\Outcome::ok(new self($db,$clock));
    }

    /**
     * @param array<string,string> $scope trusted session-derived actor
     * @return array<string,mixed>
     */
    public function read(array $scope,int $limit=20):array
    {
        foreach(['tenant_id','workspace_id','actor_type','actor_id',
                 'competitor_id','question_id'] as $field){
            if(!isset($scope[$field]) || !is_string($scope[$field]) || $scope[$field]==='') {
                return ['decision'=>'DENIED','reason_codes'=>['HISTORY_READ_INVALID_SCOPE']];
            }
        }
        if ($scope['actor_type']!=='OPERATOR' || !Ids::isValid($scope['actor_id'])
            || !Ids::isValid($scope['tenant_id'])
            || !Ids::isValid($scope['workspace_id'])
            || !Ids::isValid($scope['competitor_id'])
            || !Ids::isValid($scope['question_id'])
            || $limit<1 || $limit>50) {
            return ['decision'=>'DENIED','reason_codes'=>['HISTORY_READ_INVALID_SCOPE']];
        }
        try {
            foreach(['competition_monitoring.read','evidence.read'] as $permission){
                $access=(new AccessGuard($this->db,$this->clock))->evaluate(
                    $scope['actor_type'],$scope['actor_id'],$scope['tenant_id'],
                    $scope['workspace_id'],$permission,'M17_CANDIDATE_READBACK',
                    $scope['competitor_id'],
                );
                if (($access['decision']??null)!=='ALLOW'){
                    return ['decision'=>'DENIED','reason_codes'=>['HISTORY_READ_PERMISSION_NOT_CURRENT']];
                }
            }
            $competitor=$this->db->one(
                'SELECT competitor_id FROM competitors
                 WHERE competitor_id=? AND tenant_id=? AND workspace_id=?
                   AND status IN (?,?)',
                [$scope['competitor_id'],$scope['tenant_id'],$scope['workspace_id'],
                 'ACTIVE','PAUSED'],
            );
            if($competitor===null) {
                return ['decision'=>'NOT_FOUND','reason_codes'=>['HISTORY_READ_NOT_FOUND']];
            }
            $sqlScope=[
                'tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],
                'competitor_id'=>$scope['competitor_id'],'question_id'=>$scope['question_id'],
            ];
            $basisStore=HistoryReviewBasisStore::forDisposableTest($this->db,$this->clock);
            $memoryStore=MemoryStore::forDisposableTest($this->db,$this->clock);
            $dueStore=HistoryReviewDueStore::forDisposableTest($this->db,$this->clock);
            if (!$basisStore->isOk()||!$memoryStore->isOk()||!$dueStore->isOk()) {
                return self::unavailable();
            }
            $basisRows=$this->db->all(
                'SELECT history_review_basis_id,basis_digest FROM history_review_bases
                 WHERE tenant_id=? AND workspace_id=? AND competitor_id=? AND question_id=?
                   AND candidate_state=? ORDER BY knowledge_cutoff_utc DESC,
                   history_review_basis_id DESC LIMIT '.$limit,
                [$scope['tenant_id'],$scope['workspace_id'],
                 $scope['competitor_id'],$scope['question_id'],'TEST_ONLY_NOT_ADMITTED'],
            );
            $due=$dueStore->value()->listDueCandidates($sqlScope,50);
            if (!$due->isOk())return self::unavailable();
            $dueByBasis=[];
            foreach($due->value()['rows'] as $event){
                $digest=$event['basis_digest'];
                if(!array_key_exists($digest,$dueByBasis))$dueByBasis[$digest]=$event['disposition'];
            }
            $rows=[];
            foreach($basisRows as $record){
                $basis=$basisStore->value()->replayCandidate(
                    $sqlScope,$record['history_review_basis_id']);
                if (!$basis->isOk()) return self::unavailable();
                $manifest=$basis->value()['manifest'];
                if ($manifest['manifest_digest']!==$record['basis_digest']) return self::unavailable();
                $heads=$memoryStore->value()->headCandidates(
                    $sqlScope,'SOURCE_DIFF','MONTH',
                    $manifest['period']['start_inclusive_utc'],
                    $manifest['period']['end_exclusive_utc'],
                );
                if (!$heads->isOk())return self::unavailable();
                $status=$heads->value()['status'];
                if ($status==='NO_CANDIDATE')$status='NONE_NOT_ADMITTED';
                $rows[]=[
                    'history_review_basis_id'=>$record['history_review_basis_id'],
                    'basis_digest'=>$record['basis_digest'],
                    'selected_count'=>$manifest['selected_count'],
                    'excluded_count'=>array_sum($manifest['excluded_counts_by_reason']),
                    'head_status'=>$status,
                    'due_status'=>$dueByBasis[$record['basis_digest']]??'NOT_EVALUATED',
                ];
            }
            return ['decision'=>'VISIBLE','required_permission'=>'evidence.read','data'=>[
                'read_only'=>true,'candidate_only'=>true,
                'source_rights'=>'U03_NOT_ADOPTED',
                'rows'=>$rows,
            ]];
        } catch(\Throwable) {return self::unavailable();}
    }

    private static function unavailable():array
    {
        return ['decision'=>'UNAVAILABLE','reason_codes'=>['HISTORY_R2_OWNER_READ_FAILED']];
    }
}
