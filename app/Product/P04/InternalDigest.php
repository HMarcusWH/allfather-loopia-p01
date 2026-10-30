<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P04;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * AQ-02: private, role-scoped candidate composition.
 * This neither calls P04's live release path nor publishes a message.
 */
final class InternalDigest
{
    public const VERSION='aq02.inert-internal-digest.v1';
    public const MAX_INPUT_EVENTS=64;
    private const HEX='/^[a-f0-9]{64}$/D';

    public static function freeze(array $input):Outcome
    {
        if(!Vocabulary::exactKeys($input,[
            'policy','attention_candidates',
            'period_start_utc','period_end_utc','generated_at_utc',
        ]) || !is_array($input['policy'])
            || !AttentionPolicy::verify($input['policy'])->isOk()
            || !is_array($input['attention_candidates'])
            || !array_is_list($input['attention_candidates'])
            || count($input['attention_candidates'])>self::MAX_INPUT_EVENTS
            || !Vocabulary::utc($input['period_start_utc'])
            || !Vocabulary::utc($input['period_end_utc'])
            || !Vocabulary::utc($input['generated_at_utc'])
            || $input['period_start_utc']>=$input['period_end_utc']
            || $input['period_end_utc']>$input['generated_at_utc']){
            return Outcome::refuse('AQ02_DIGEST_INPUT_OR_PERIOD_INVALID');
        }
        $policy=$input['policy'];$candidates=$input['attention_candidates'];
        $seen=[];$eligible=[];$suppressed=[];
        foreach($candidates as $candidate){
            if(!is_array($candidate)
                || !InternalAttention::verify($candidate)->isOk()
                || $candidate['scope']!==$policy['scope']
                || $candidate['target_org_role']!==$policy['target_org_role']
                || $candidate['policy_digest']!==$policy['policy_digest']
                || $candidate['redaction_profile_digest']
                    !==$policy['redaction_profile_digest']
                || $candidate['inspected_at_utc']<$input['period_start_utc']
                || $candidate['inspected_at_utc']>=$input['period_end_utc']
                || isset($seen[$candidate['attention_digest']])){
                return Outcome::refuse('AQ02_DIGEST_SCOPE_OR_ATTENTION_INVALID');
            }
            $seen[$candidate['attention_digest']]=true;
            if($candidate['status']==='ATTENTION_CANDIDATE_INTERNAL_ONLY'){
                $eligible[]=$candidate;
            }else{
                $suppressed[]=[
                    'attention_digest'=>$candidate['attention_digest'],
                    'source_id'=>$candidate['source_id'],
                    'reason'=>$candidate['status'],
                    'source_decision_record_id'=>$candidate['source_decision_record_id'],
                ];
            }
        }
        $rank=['HIGH'=>0,'NORMAL'=>1,'LOW'=>2];
        usort($eligible,static fn(array $a,array $b):int=>
            [$rank[$a['urgency_recommendation']],$a['inspected_at_utc'],
             $a['attention_digest']]
            <=>
            [$rank[$b['urgency_recommendation']],$b['inspected_at_utc'],
             $b['attention_digest']]);
        $dedup=[];$members=[];
        foreach($eligible as $candidate){
            $reason=null;
            if(isset($dedup[$candidate['attention_dedup_key']])){
                $reason='SUPPRESSED_DUPLICATE_IN_DIGEST';
            }elseif(count($members)>=$policy['max_digest_items']){
                $reason='SUPPRESSED_DIGEST_CAP';
            }
            if($reason!==null){
                $suppressed[]=[
                    'attention_digest'=>$candidate['attention_digest'],
                    'source_id'=>$candidate['source_id'],
                    'reason'=>$reason,
                    'source_decision_record_id'=>$candidate['source_decision_record_id'],
                ];
                continue;
            }
            $dedup[$candidate['attention_dedup_key']]=true;
            $members[]=[
                'attention_digest'=>$candidate['attention_digest'],
                'attention_dedup_key'=>$candidate['attention_dedup_key'],
                'source_kind'=>$candidate['source_kind'],
                'source_id'=>$candidate['source_id'],
                'source_decision_record_id'=>$candidate['source_decision_record_id'],
                'urgency'=>$candidate['urgency_recommendation'],
                'reason'=>$candidate['reason_code'],
                'historical_finding_digest'=>$candidate['historical_finding_digest'],
                'outcome_annotation_digest'=>$candidate['outcome_annotation_digest'],
            ];
        }
        usort($suppressed,static fn(array $a,array $b):int=>
            [$a['reason'],$a['attention_digest']]
            <=>
            [$b['reason'],$b['attention_digest']]);
        $v=[
            'version'=>self::VERSION,
            'status'=>$members===[]?'EMPTY_SUPPRESSED_DIGEST_HOLD'
                :'DIGEST_CANDIDATE_INTERNAL_ONLY',
            'scope'=>$policy['scope'],
            'target_org_role'=>$policy['target_org_role'],
            'policy_digest'=>$policy['policy_digest'],
            'redaction_profile_digest'=>$policy['redaction_profile_digest'],
            'period_start_utc'=>$input['period_start_utc'],
            'period_end_utc'=>$input['period_end_utc'],
            'generated_at_utc'=>$input['generated_at_utc'],
            'input_attention_digests'=>array_column($candidates,'attention_digest'),
            'members'=>$members,
            'member_count'=>count($members),
            'suppression_entries'=>$suppressed,
            'suppressed_count'=>count($suppressed),
            'all_input_count'=>count($candidates),
            'per_role_redaction_admitted'=>false,
            'fresh_m10_reliance_authenticated'=>false,
            'actual_role_entitlement_authenticated'=>false,
            'real_m16_projection_created'=>false,
            'sent'=>false,
            'customer_visibility_authorized'=>false,
            'external_delivery_authorized'=>false,
        ];
        $v['digest']=Digest::canonicalDigest($v);
        return Outcome::ok($v);
    }

    public static function verify(array $v):Outcome
    {
        if(($v['version']??null)!==self::VERSION
            || !Vocabulary::scope($v['scope']??null)
            || !in_array($v['status']??null,[
                'DIGEST_CANDIDATE_INTERNAL_ONLY','EMPTY_SUPPRESSED_DIGEST_HOLD',
            ],true)
            || !in_array($v['target_org_role']??null,Distribution::TARGET_ORG_ROLES,true)
            || !is_array($v['members']??null)
            || !array_is_list($v['members'])
            || !is_array($v['suppression_entries']??null)
            || !array_is_list($v['suppression_entries'])
            || !is_array($v['input_attention_digests']??null)
            || !array_is_list($v['input_attention_digests'])
            || count($v['input_attention_digests'])>self::MAX_INPUT_EVENTS
            || ($v['member_count']??null)!==count($v['members'])
            || ($v['suppressed_count']??null)!==count($v['suppression_entries'])
            || ($v['all_input_count']??null)!==
                count($v['input_attention_digests'])
            || $v['all_input_count']!==$v['member_count']+$v['suppressed_count']
            || !is_string($v['digest']??null)
            || preg_match(self::HEX,$v['digest'])!==1
            || ($v['status']==='DIGEST_CANDIDATE_INTERNAL_ONLY')
                !==($v['member_count']>0)){
            return Outcome::refuse('AQ02_DIGEST_CANDIDATE_INVALID');
        }
        foreach([
            'per_role_redaction_admitted','fresh_m10_reliance_authenticated',
            'actual_role_entitlement_authenticated',
            'real_m16_projection_created','sent','customer_visibility_authorized',
            'external_delivery_authorized',
        ] as $flag){
            if(($v[$flag]??null)!==false){
                return Outcome::refuse('AQ02_DIGEST_FORGED_AUTHORITY');
            }
        }
        try{
            $copy=$v;$hash=$v['digest'];unset($copy['digest']);
            if(!hash_equals($hash,Digest::canonicalDigest($copy))){
                return Outcome::refuse('AQ02_DIGEST_HASH_MISMATCH');
            }
        }catch(\Throwable){
            return Outcome::refuse('AQ02_DIGEST_UNREADABLE');
        }
        return Outcome::ok(['integrity'=>'CANDIDATE_ONLY',
            'fresh_owner_reliance_verified'=>false]);
    }

    /**
     * Re-run the synthetic owner read for every proposed member.
     * A digest stored yesterday is not a current-rights authorization.
     */
    public static function renderFixture(
        array $digest,array $candidates,AttentionReadPort $port,
    ):Outcome {
        if(!self::verify($digest)->isOk()
            || !is_array($candidates)
            || !array_is_list($candidates)){
            return Outcome::refuse('AQ02_DIGEST_RENDER_INPUT_INVALID');
        }
        $byId=[];
        foreach($candidates as $a){
            if(!is_array($a) || !InternalAttention::verify($a)->isOk()
                || isset($byId[$a['attention_digest']])){
                return Outcome::refuse('AQ02_DIGEST_RENDER_ATTENTION_INVALID');
            }
            $byId[$a['attention_digest']]=$a;
        }
        if(array_keys($byId)!==$digest['input_attention_digests']){
            return Outcome::refuse('AQ02_DIGEST_RENDER_INPUT_SET_CHANGED');
        }
        $fresh=[];
        foreach($digest['members'] as $member){
            $a=$byId[$member['attention_digest']]??null;
            if($a===null || $a['status']!=='ATTENTION_CANDIDATE_INTERNAL_ONLY'
                || $a['scope']!==$digest['scope']
                || $a['target_org_role']!==$digest['target_org_role']
                || $a['source_id']!==$member['source_id']
                || $a['source_decision_record_id']!==$member['source_decision_record_id']){
                return Outcome::refuse('AQ02_DIGEST_MEMBER_OWNER_MISMATCH');
            }
            $recheck=InternalAttention::recheckFixtureAtRender($a,$port);
            if(!$recheck->isOk())return $recheck;
            $fresh[]=$recheck->value()['at_render_receipt_digest'];
        }
        return Outcome::ok([
            'status'=>'SYNTHETIC_PRIVATE_DIGEST_PREVIEW_ONLY',
            'digest'=>$digest['digest'],
            'member_count'=>count($digest['members']),
            'redacted_metadata_only'=>true,
            'fresh_fixture_recheck_digests'=>$fresh,
            'actual_owner_authorization'=>false,
            'p04_distribution_written'=>false,
            'm16_visibility_activated'=>false,
            'customer_visibility_authorized'=>false,
            'external_delivery_authorized'=>false,
        ]);
    }
}
