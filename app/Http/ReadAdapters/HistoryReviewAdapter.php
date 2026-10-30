<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http\ReadAdapters;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Product\P04\InternalAttention;
use Allfather\Loopia\Workflow\{NativeReleaseProposal,HistoryReviewOutcomes};
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * UI-02: exact-scope metadata workbench with a sealed fixture-only owner.
 * Production HTTP cannot instantiate an admitted historical-use owner here.
 */
final class HistoryReviewAdapter
{
    public const VERSION='ui02.history-review-workbench.v1';
    public const MAX_PAGE=50;
    public const MAX_ROWS=10;
    public const REQUIRED_PERMISSION='evidence.read';

    public function __construct(private readonly HistoryReviewWorkbenchOwnerPort $owner){}

    public static function fixtureAllowed(HistoryReviewWorkbenchOwnerPort $owner):bool
    {
        if(PHP_SAPI!=='cli'
            || !defined('ALLFATHER_UI02_SYNTHETIC_TEST')
            || constant('ALLFATHER_UI02_SYNTHETIC_TEST')!==true
            || $owner::class!=='Allfather\\Loopia\\Tests\\Ui02FixtureReviewOwner'){
            return false;
        }
        $path=realpath(dirname(__DIR__,3).'/tests/support/Ui02FixtureReviewOwner.php');
        return $path!==false && (new \ReflectionClass($owner))->getFileName()===$path;
    }

    public function list(?array $session,string $competitor,string $question,int $page=1,int $limit=10):array
    {
        if(!self::sessionScope($session)){
            return self::state('NO_ACTIVE_WORKSPACE','NO_ACTIVE_WORKSPACE');
        }
        if(!Vocabulary::id($competitor)||!Vocabulary::id($question)
            || $page<1 || $page>self::MAX_PAGE || $limit<1 || $limit>self::MAX_ROWS){
            return self::state('NOT_FOUND','UI02_SELECTOR_INVALID');
        }
        if(!self::fixtureAllowed($this->owner)){
            return self::state('UNAVAILABLE','UI02_NATIVE_OWNER_NOT_ADOPTED');
        }
        $scope=self::scope($session,$competitor,$question);
        try{$r=$this->owner->read($scope,$page,$limit);}
        catch(\Throwable){return self::state('UNAVAILABLE','UI02_OWNER_READ_FAILED');}
        if(!$r->isOk())return self::state('DENIED','UI02_OWNER_REFUSED');
        $data=$r->value();
        if(!is_array($data)
            || !Vocabulary::exactKeys($data,['scope','rows','total','page','permission','owner_kind'])
            || ($data['scope']??null)!==$scope
            || ($data['permission']??null)!==self::REQUIRED_PERMISSION
            || ($data['owner_kind']??null)!=='SYNTHETIC_FIXED_OWNER_NOT_AUTHORIZED'
            || !is_int($data['total'])
            || $data['total']<0||$data['total']>100
            || $data['page']!==$page
            || !is_array($data['rows'])
            || !array_is_list($data['rows'])
            || count($data['rows'])>$limit){
            return self::state('UNAVAILABLE','UI02_OWNER_SHAPE_INVALID');
        }
        $rows=[];
        foreach($data['rows'] as $row){
            if(!is_array($row)
                || !Vocabulary::exactKeys($row,['proposal','outcome','attention'])
                || !is_array($row['proposal'])
                || !NativeReleaseProposal::verify($row['proposal'])->isOk()
                || !is_array($row['outcome'])
                || !HistoryReviewOutcomes::verify($row['outcome'])->isOk()
                || !is_array($row['attention'])
                || !InternalAttention::verify($row['attention'])->isOk()){
                return self::state('UNAVAILABLE','UI02_OWNER_CANDIDATE_UNVERIFIED');
            }
            $proposal=$row['proposal'];$outcome=$row['outcome'];$attention=$row['attention'];
            if($proposal['scope']!==self::domainScope($scope)
                || $outcome['scope']!==$proposal['scope']
                || $attention['scope']!==$proposal['scope']
                || $outcome['finding_digest']!==$proposal['finding_digest']
                || $attention['historical_finding_digest']!==$proposal['finding_digest']
                || $attention['outcome_annotation_digest']!==$outcome['annotation_digest']){
                return self::state('DENIED','UI02_CROSS_SCOPE_OR_LINEAGE');
            }
            $sources=[];
            foreach($proposal['primary_references'] as $ref){
                $sources[]=[
                    'ref_id'=>$ref['ref_id'],
                    'source_ref_id'=>$ref['source_ref_id'],
                    'evidence_id'=>$ref['evidence_id'],
                    'snapshot_id'=>$ref['snapshot_id'],
                    'polarity'=>$ref['polarity'],
                    'known_at_utc'=>$ref['known_at_utc'],
                    'cited_as_support'=>$ref['cited_as_support'],
                ];
            }
            $revision=Digest::canonicalDigest([
                'finding'=>$proposal['finding_digest'],
                'outcome'=>$outcome['annotation_digest'],
                'attention'=>$attention['attention_digest'],
            ]);
            $rows[]=[
                'revision'=>$revision,
                'review_id'=>$proposal['historical_finding']['review_id'],
                'finding_digest'=>$proposal['finding_digest'],
                'basis_digest'=>$proposal['basis_digest'],
                'knowledge_cutoff_utc'=>$proposal['known_at_cutoff_utc'],
                'status'=>'HUMAN_REVIEW_REQUIRED',
                'critic_digest'=>$proposal['critic_candidate_digest'],
                'analyst_digest'=>$proposal['analyst_candidate_digest'],
                'primary_references'=>$sources,
                'selected_count'=>count($sources),
                'contrary_count'=>count($proposal['claim']['contrary_ref_ids']),
                'outcome_status'=>$outcome['reported_disposition'],
                'outcome_observer_kind'=>$outcome['observer']['actor_type'],
                'outcome_adjudication'=>'NOT_ADJUDICATED',
                'attention_status'=>$attention['status'],
                'review_due_status'=>'UNKNOWN_UNTIL_OWNER_READ',
                'historical_source_rights'=>'U03_NOT_ADOPTED',
                'human_factual_release_approved'=>false,
                'human_delivery_approved'=>false,
                'customer_visibility_authorized'=>false,
            ];
        }
        return [
            'decision'=>'VISIBLE','required_permission'=>self::REQUIRED_PERMISSION,
            'reason_codes'=>['UI02_SYNTHETIC_METADATA_ONLY'],
            'data'=>[
                'version'=>self::VERSION,
                'scope'=>$scope,
                'rows'=>$rows,
                'total'=>$data['total'],
                'page'=>$page,
                'page_size'=>$limit,
                'next_page'=>($page*$limit<$data['total'])?$page+1:null,
                'read_only'=>true,
                'fixture_only'=>true,
                'runtime_commands_enabled'=>false,
                'actual_source_owner_authenticated'=>false,
            ],
        ];
    }

    public function fixtureOwner():HistoryReviewWorkbenchOwnerPort{return $this->owner;}

    public static function sessionScope(?array $session):bool
    {
        return is_array($session)
            && Vocabulary::id($session['tenant_id']??null)
            && Vocabulary::id($session['active_workspace_id']??null)
            && Vocabulary::id($session['operator_id']??null);
    }

    public static function scope(array $session,string $competitor,string $question):array
    {
        return [
            'tenant_id'=>$session['tenant_id'],
            'workspace_id'=>$session['active_workspace_id'],
            'competitor_id'=>$competitor,
            'question_id'=>$question,
            'actor_type'=>'OPERATOR',
            'actor_id'=>$session['operator_id'],
        ];
    }

    public static function domainScope(array $scope):array
    {
        return [
            'tenant_id'=>$scope['tenant_id'],
            'workspace_id'=>$scope['workspace_id'],
            'competitor_id'=>$scope['competitor_id'],
            'question_id'=>$scope['question_id'],
        ];
    }

    private static function state(string $decision,string $reason):array
    {
        return ['decision'=>$decision,'required_permission'=>self::REQUIRED_PERMISSION,
            'reason_codes'=>[$reason],'data'=>null];
    }
}
