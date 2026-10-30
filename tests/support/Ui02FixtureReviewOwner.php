<?php

declare(strict_types=1);

namespace Allfather\Loopia\Tests;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Http\ReadAdapters\{HistoryReviewWorkbenchOwnerPort,HistoryReviewAdapter};
use Allfather\Loopia\Product\P04\InternalAttention;
use Allfather\Loopia\Substrate\M17\Vocabulary;

/** CLI-only fixture implementation, not a native reviewer or release owner. */
final class Ui02FixtureReviewOwner implements HistoryReviewWorkbenchOwnerPort
{
    private array $records=[];

    public function __construct(private readonly array $expectedScope){}

    public function receiptCount():int{return count($this->records);}

    public function read(array $scope,int $page,int $limit):Outcome
    {
        if($scope!==$this->expectedScope || !Vocabulary::id($scope['actor_id']??null)){
            return Outcome::refuse('UI02_FIXTURE_OWNER_FOREIGN_SCOPE');
        }
        if(!defined('ALLFATHER_AQ02_SYNTHETIC_TEST')){
            define('ALLFATHER_AQ02_SYNTHETIC_TEST',true);
        }
        $input=Aq02MockAttention::input(HistoryReviewAdapter::domainScope($scope));
        if(!$input->isOk())return Outcome::refuse('UI02_FIXTURE_HISTORY_MISSING');
        $a=InternalAttention::composeFixture(
            $input->value(),new Aq02FixtureAttentionOwner(),
        );
        if(!$a->isOk())return Outcome::refuse('UI02_FIXTURE_ATTENTION_MISSING');
        $rows=[[
            'proposal'=>$input->value()['native_release_proposal'],
            'outcome'=>$input->value()['outcome_annotation'],
            'attention'=>$a->value(),
        ]];
        return Outcome::ok([
            'scope'=>$scope,'rows'=>$page===1?$rows:[],
            'total'=>1,'page'=>$page,'permission'=>'evidence.read',
            'owner_kind'=>'SYNTHETIC_FIXED_OWNER_NOT_AUTHORIZED',
        ]);
    }

    public function proposeCommand(array $scope,array $candidate):Outcome
    {
        if($scope!==$this->expectedScope
            || !is_array($candidate)
            || ($candidate['scope']??null)!==$scope
            || ($candidate['status']??null)!=='REVIEWED_COMMAND_CANDIDATE_ONLY'
            || ($candidate['human_decision_authenticated']??null)!==false
            || ($candidate['actual_m07_review_record_written']??null)!==false
            || ($candidate['actual_m10_release_or_correction']??null)!==false
            || !is_string($candidate['plan_digest']??null)){
            return Outcome::refuse('UI02_FIXTURE_COMMAND_NOT_ALLOWED');
        }
        $copy=$candidate;$checksum=$copy['plan_digest'];unset($copy['plan_digest']);
        if(!hash_equals($checksum,Digest::canonicalDigest($copy))){
            return Outcome::refuse('UI02_FIXTURE_COMMAND_PLAN_CHANGED');
        }
        $this->records[$checksum]=true;
        return Outcome::ok([
            'status'=>'FIXTURE_REVIEW_WORK_CANDIDATE_RECORDED',
            'plan_digest'=>$checksum,
            'scope'=>$scope,
            'revision'=>$candidate['expected_review_revision'],
            'action'=>$candidate['action'],
            'record_count'=>count($this->records),
            'actual_business_mutation'=>false,
            'human_decision_admitted'=>false,
            'customer_visibility_authorized'=>false,
        ]);
    }
}
