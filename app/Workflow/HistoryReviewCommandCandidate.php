<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Http\ReadAdapters\{HistoryReviewAdapter,HistoryReviewWorkbenchOwnerPort};
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * UI-02: reviewed, revision-locked command CANDIDATE.
 *
 * A real human decision would require M02 + native M07/M10/owner adoption.
 * Here only the exact fixed test owner may write a disposable in-memory
 * fixture receipt; production has no command handler.
 */
final class HistoryReviewCommandCandidate
{
    public const VERSION='ui02.reviewed-command-candidate.v1';
    public const ACTIONS=['REQUEST_HUMAN_REVIEW','DEFER_REVIEW','ACKNOWLEDGE_CANDIDATE'];
    public const REASONS=[
        'SOURCE_RIGHTS_PENDING','CONTRARY_EVIDENCE_UNRESOLVED',
        'NEW_OBSERVATION_REQUIRED','OPERATOR_REVIEW_REQUESTED',
    ];

    public function __construct(private readonly HistoryReviewAdapter $reader){}

    public function plan(?array $session,string $competitor,string $question,
                         string $revision,string $action,string $reason):Outcome
    {
        if(!HistoryReviewAdapter::sessionScope($session)
            || !Vocabulary::id($competitor)||!Vocabulary::id($question)
            || !is_string($revision)||preg_match('/^[a-f0-9]{64}$/D',$revision)!==1
            || !in_array($action,self::ACTIONS,true)
            || !in_array($reason,self::REASONS,true)){
            return Outcome::refuse('UI02_COMMAND_SELECTOR_OR_REASON_INVALID');
        }
        $read=$this->reader->list($session,$competitor,$question,1,10);
        if(($read['decision']??null)!=='VISIBLE'){
            return Outcome::refuse('UI02_COMMAND_OWNER_READ_NOT_AVAILABLE');
        }
        $rows=$read['data']['rows'];
        $matches=array_values(array_filter($rows,
            static fn(array $r):bool=>$r['revision']===$revision));
        if(count($matches)!==1){
            return Outcome::refuse('UI02_REVIEW_REVISION_STALE_OR_NOT_SCOPED');
        }
        $row=$matches[0];
        $scope=HistoryReviewAdapter::scope($session,$competitor,$question);
        $candidate=[
            'version'=>self::VERSION,
            'status'=>'REVIEWED_COMMAND_CANDIDATE_ONLY',
            'scope'=>$scope,
            'review_id'=>$row['review_id'],
            'basis_digest'=>$row['basis_digest'],
            'finding_digest'=>$row['finding_digest'],
            'expected_review_revision'=>$revision,
            'action'=>$action,
            'reason_code'=>$reason,
            'intent'=>'OPERATOR_REVIEW_WORK_PROPOSAL',
            'human_decision_authenticated'=>false,
            'actual_m07_review_record_written'=>false,
            'actual_m10_release_or_correction'=>false,
            'p04_recipient_notified'=>false,
            'customer_visibility_authorized'=>false,
        ];
        $candidate['plan_digest']=Digest::canonicalDigest($candidate);
        return Outcome::ok($candidate);
    }

    public function submit(?array $session,string $competitor,string $question,
                           string $revision,string $action,string $reason,
                           string $expectedPlanDigest):Outcome
    {
        if(!is_string($expectedPlanDigest)
            || preg_match('/^[a-f0-9]{64}$/D',$expectedPlanDigest)!==1){
            return Outcome::refuse('UI02_EXACT_PLAN_DIGEST_REQUIRED');
        }
        $plan=$this->plan($session,$competitor,$question,$revision,$action,$reason);
        if(!$plan->isOk())return $plan;
        if(!hash_equals($plan->value()['plan_digest'],$expectedPlanDigest)){
            return Outcome::refuse('UI02_REVIEW_PLAN_STALE_OR_FORGED');
        }
        $owner=$this->reader->fixtureOwner();
        if(!HistoryReviewAdapter::fixtureAllowed($owner)){
            return Outcome::refuse('UI02_NATIVE_COMMAND_OWNER_NOT_ADOPTED');
        }
        try{$res=$owner->proposeCommand($plan->value()['scope'],$plan->value());}
        catch(\Throwable){return Outcome::failure('UI02_FIXTURE_COMMAND_OWNER_FAILED');}
        if(!$res->isOk())return $res;
        $v=$res->value();
        if(!is_array($v)
            || !Vocabulary::exactKeys($v,[
                'status','plan_digest','scope','revision','action','record_count',
                'actual_business_mutation','human_decision_admitted',
                'customer_visibility_authorized',
            ])
            || $v['status']!=='FIXTURE_REVIEW_WORK_CANDIDATE_RECORDED'
            || $v['plan_digest']!==$expectedPlanDigest
            || $v['scope']!==$plan->value()['scope']
            || $v['revision']!==$revision
            || $v['action']!==$action
            || !is_int($v['record_count'])
            || $v['record_count']<1 || $v['record_count']>10
            || $v['actual_business_mutation']!==false
            || $v['human_decision_admitted']!==false
            || $v['customer_visibility_authorized']!==false){
            return Outcome::refuse('UI02_OWNER_COMMAND_RECEIPT_UNTRUSTED');
        }
        return $res;
    }

    public static function forRuntime():Outcome
    {
        return Outcome::refuse('UI02_U03_U06_U07_U09_NATIVE_COMMAND_NOT_ADOPTED');
    }
}
