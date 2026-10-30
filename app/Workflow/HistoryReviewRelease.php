<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Product\P01\HistoricalFindingAdapter;
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * AN-03: explicit HOLD between historical evidence and genuine product release.
 * Only CLI test fixtures can exercise the ordered candidate-to-owner handshake.
 * No production factory or release grant is registered here.
 */
final class HistoryReviewRelease
{
    public const VERSION='an03.provisional-multi-evidence-release-hold.v1';
    public const STAGES=['PROPOSAL','POST_REVIEW','PRE_RELEASE'];
    public const FIXTURE_STAGE_VERSION='an03.fixture-owner-stage.v1';
    public const OWNER_STAGES=[
        'M05_SOURCE_USE','HUMAN_FACTUAL','M04_PROPOSAL','M07_REVIEW',
        'M08_ADMISSIBILITY','M10_CANDIDATE','HUMAN_DELIVERY','M16_CANDIDATE',
    ];
    public const FIXTURE_OWNER_STAGES=self::OWNER_STAGES;

    /** Inspect metadata only. A well-shaped source-owner HOLD remains HOLD. */
    public static function inspect(
        array $finding,string $stage='PRE_RELEASE',
        ?array $sourceOwnerInspection=null,
    ):Outcome {
        if(!HistoricalFindingAdapter::verify($finding)->isOk()
            || !in_array($stage,self::STAGES,true)){
            return Outcome::refuse('AN03_PRE_RELEASE_FINDING_OR_STAGE_INVALID');
        }
        $sourcePosture='NO_CURRENT_OWNER_READ_ATTACHED';
        $ownerFingerprint=null;
        if($sourceOwnerInspection!==null){
            if(!is_array($sourceOwnerInspection)
                || ($sourceOwnerInspection['version']??null)!==HistoryReviewRightsGateway::VERSION
                || ($sourceOwnerInspection['decision']??null)!=='HOLD_U03_HISTORICAL_USE_NOT_RATIFIED'
                || ($sourceOwnerInspection['basis_digest']??null)!==$finding['basis_digest']
                || ($sourceOwnerInspection['current_m02_p01_m05_checked']??null)!==true
                || ($sourceOwnerInspection['m10_current_reliance_checked']??null)!==false
                || ($sourceOwnerInspection['u03_owner_decisions_ratified']??null)!==false
                || ($sourceOwnerInspection['historical_reuse_admitted']??null)!==false
                || ($sourceOwnerInspection['customer_release_authorized']??null)!==false
                || ($sourceOwnerInspection['recheck_stage']??null)!=='PRE_RELEASE'
                || !is_string($sourceOwnerInspection['current_owner_fingerprint']??null)
                || preg_match('/^[a-f0-9]{64}$/D',
                    $sourceOwnerInspection['current_owner_fingerprint'])!==1){
                return Outcome::refuse('AN03_UNTRUSTED_OR_NONHOLD_OWNER_ASSERTION');
            }
            $sourcePosture='SHAPE_CHECKED_HS02_HOLD_NOT_AUTHENTICATED_BY_THIS_CLASS';
            $ownerFingerprint=$sourceOwnerInspection['current_owner_fingerprint'];
        }
        $v=[
            'version'=>self::VERSION,'stage'=>$stage,
            'finding_digest'=>$finding['finding_digest'],
            'basis_digest'=>$finding['basis_digest'],
            'review_run_digest'=>$finding['review_run_digest'],
            'primary_ref_count'=>$finding['selected_primary_ref_count'],
            'support_ref_count'=>$finding['support_ref_count'],
            'contrary_ref_count'=>$finding['contrary_ref_count'],
            'source_owner_inspection_posture'=>$sourcePosture,
            'source_owner_fingerprint'=>$ownerFingerprint,
            'disposition'=>'HOLD_U03_U06_U07_NATIVE_MULTI_EVIDENCE_ROUTE_NOT_ADOPTED',
            'original_m05_source_bytes_authenticated_here'=>false,
            'current_m10_reliance_authenticated_here'=>false,
            'human_factual_review_attested'=>false,
            'human_delivery_review_attested'=>false,
            'new_company_signal_or_diff_created'=>false,
            'm07_m08_m10_m16_release_authorized'=>false,
            'p02_twin_write_authorized'=>false,
            'p04_distribution_authorized'=>false,
            'customer_visibility_authorized'=>false,
        ];
        $v['receipt_digest']=Digest::canonicalDigest($v);
        return Outcome::ok($v);
    }

    /** Typed native-owner input; creating it grants no owner approval. */
    public static function prepareOwnerProposal(array $finding): Outcome
    {
        return NativeReleaseProposal::fromFinding($finding);
    }

    /**
     * Execute the *real ordered owner-interface calls* against the one reviewed
     * CLI-only fixture implementation. Never dispatch an actual source/provider,
     * insert a real M10 record, or create a customer-visible artifact.
     *
     * Refuses all other ports and every non-test entrypoint. This is not a
     * general public DI entrypoint for caller-supplied M10-like authority.
     */
    public static function exerciseFixtureOwnerPath(
        array $proposal,HistoricalFindingReleaseOwnerPort $port,
    ):Outcome {
        $expectedFixture=realpath(dirname(__DIR__,2).'/tests/support/An03FixtureReleaseOwner.php');
        $actualFixture=(new \ReflectionClass($port))->getFileName();
        if(PHP_SAPI!=='cli'
            || !defined('ALLFATHER_AN03_FIXTURE_OWNER_TEST')
            || constant('ALLFATHER_AN03_FIXTURE_OWNER_TEST')!==true
            || $port::class!=='Allfather\\Loopia\\Tests\\An03FixtureReleaseOwner'
            || $expectedFixture===false || $actualFixture!==$expectedFixture){
            return Outcome::refuse('AN03_NATIVE_OWNER_PORT_NOT_ADOPTED');
        }
        if(!NativeReleaseProposal::verify($proposal)->isOk()){
            return Outcome::refuse('AN03_OWNER_PROPOSAL_INVALID');
        }
        $methods=[
            'M05_SOURCE_USE'=>'recheckCurrentSourceUse',
            'HUMAN_FACTUAL'=>'readFactualHumanDecision',
            'M04_PROPOSAL'=>'submitM04MultiEvidenceProposal',
            'M07_REVIEW'=>'recordM07IndependentReview',
            'M08_ADMISSIBILITY'=>'evaluateM08Admissibility',
            'M10_CANDIDATE'=>'prepareM10CurrentRelianceRecord',
            'HUMAN_DELIVERY'=>'readDeliveryHumanDecision',
            'M16_CANDIDATE'=>'prepareM16InternalRepresentation',
        ];
        $prior=null;$receipts=[];$human=[];$humanTimes=[];
        foreach($methods as $stage=>$method){
            $result=$port->{$method}($proposal,$prior);
            if(!$result->isOk())return $result;
            if(!is_array($result->value())){
                return Outcome::refuse('AN03_FIXTURE_OWNER_RECEIPT_NOT_OBJECT');
            }
            $receipt=$result->value();
            $valid=self::verifyFixtureStage($proposal,$receipt,$stage,$prior);
            if(!$valid->isOk())return $valid;
            if($receipt['human_decision']!==null){
                $human[$stage]=$receipt['human_decision']['decision_digest'];
                $humanTimes[$stage]=$receipt['human_decision']['decided_at_utc'];
            }
            $receipts[]=$receipt['receipt_digest'];
            $prior=$receipt;
        }
        if(!isset($human['HUMAN_FACTUAL'],$human['HUMAN_DELIVERY'])
            || $human['HUMAN_FACTUAL']===$human['HUMAN_DELIVERY']
            || $humanTimes['HUMAN_FACTUAL']>$humanTimes['HUMAN_DELIVERY']){
            return Outcome::refuse('AN03_DISTINCT_HUMAN_REVIEW_PURPOSES_REQUIRED');
        }
        $v=[
            'version'=>'an03.fixture-owner-composition.v1',
            'status'=>'FIXTURE_OWNER_SEQUENCE_PASS_NO_LIVE_RELEASE',
            'proposal_digest'=>$proposal['proposal_digest'],
            'basis_digest'=>$proposal['basis_digest'],
            'owner_stage_sequence'=>array_keys($methods),
            'stage_receipt_digests'=>$receipts,
            'human_factual_decision_digest'=>$human['HUMAN_FACTUAL'],
            'human_delivery_decision_digest'=>$human['HUMAN_DELIVERY'],
            'native_owner_adoption'=>'CONTRACT_BLOCKED_U03_U06_U07',
            'source_rights_authenticated'=>false,
            'actual_human_decisions_authenticated'=>false,
            'native_m10_record_written'=>false,
            'customer_visibility_authorized'=>false,
            'p02_mutation_authorized'=>false,
            'p04_distribution_authorized'=>false,
        ];
        $v['receipt_digest']=Digest::canonicalDigest($v);
        return Outcome::ok($v);
    }

    private static function verifyFixtureStage(
        array $proposal,array $receipt,string $stage,?array $prior,
    ):Outcome {
        if(!Vocabulary::exactKeys($receipt,[
            'version','stage','proposal_digest','basis_digest','scope',
            'source_ref_ids','prior_receipt_digest','result','human_decision',
            'fixture_only','actual_owner_authorization','actual_m10_record_written',
            'receipt_digest',
        ]) || ($receipt['version']??null)!==self::FIXTURE_STAGE_VERSION
            || ($receipt['stage']??null)!==$stage
            || ($receipt['proposal_digest']??null)!==$proposal['proposal_digest']
            || ($receipt['basis_digest']??null)!==$proposal['basis_digest']
            || ($receipt['scope']??null)!==$proposal['scope']
            || ($receipt['source_ref_ids']??null)!==array_column($proposal['primary_references'],'ref_id')
            || ($receipt['prior_receipt_digest']??null)!==($prior['receipt_digest']??null)
            || ($receipt['result']??null)!=='SYNTHETIC_STAGE_ACCEPTED'
            || ($receipt['fixture_only']??null)!==true
            || ($receipt['actual_owner_authorization']??null)!==false
            || ($receipt['actual_m10_record_written']??null)!==false
            || !is_string($receipt['receipt_digest']??null)){
            return Outcome::refuse('AN03_FIXTURE_OWNER_STAGE_BINDING_INVALID');
        }
        $copy=$receipt;unset($copy['receipt_digest']);
        if(!hash_equals($receipt['receipt_digest'],Digest::canonicalDigest($copy))){
            return Outcome::refuse('AN03_FIXTURE_OWNER_STAGE_DIGEST_INVALID');
        }
        $purpose=match($stage){
            'HUMAN_FACTUAL'=>'FACTUAL_REVIEW',
            'HUMAN_DELIVERY'=>'DELIVERY_REVIEW',
            default=>null,
        };
        $decision=$receipt['human_decision'];
        if($purpose===null){
            return $decision===null?Outcome::ok():
                Outcome::refuse('AN03_NONHUMAN_STAGE_CLAIMS_REVIEW');
        }
        if(!is_array($decision)||!Vocabulary::exactKeys($decision,[
            'actor_type','actor_id','purpose','decision','finding_digest',
            'basis_digest','proposal_digest','scope','decided_at_utc','decision_digest',
        ]) || ($decision['actor_type']??null)!=='HUMAN_FIXTURE'
            || !Vocabulary::id($decision['actor_id']??null)
            || ($decision['purpose']??null)!==$purpose
            || ($decision['decision']??null)!=='APPROVE_SYNTHETIC_ONLY'
            || ($decision['finding_digest']??null)!==$proposal['finding_digest']
            || ($decision['basis_digest']??null)!==$proposal['basis_digest']
            || ($decision['proposal_digest']??null)!==$proposal['proposal_digest']
            || ($decision['scope']??null)!==$proposal['scope']
            || !Vocabulary::utc($decision['decided_at_utc']??null)
            || $decision['decided_at_utc']<$proposal['known_at_cutoff_utc']
            || !is_string($decision['decision_digest']??null)){
            return Outcome::refuse('AN03_FIXTURE_HUMAN_DECISION_UNBOUND');
        }
        $h=$decision;unset($h['decision_digest']);
        if(!hash_equals($decision['decision_digest'],Digest::canonicalDigest($h))){
            return Outcome::refuse('AN03_FIXTURE_HUMAN_DECISION_DIGEST_INVALID');
        }
        return Outcome::ok();
    }

    /** No caller data, role, configured policy or synthetic proof grants release. */
    public static function release(array $finding,array $callerClaims=[]):Outcome
    {
        return Outcome::refuse('AN03_U03_U06_U07_M10_HISTORICAL_RELEASE_NOT_ADOPTED');
    }
}
