<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P04;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Workflow\{NativeReleaseProposal,HistoryReviewOutcomes};
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * AQ-02: a closed candidate, not an M10 release, P04 distribution or message.
 * The only positive adapter is a CLI-only fixed synthetic owner. A real owner
 * must eventually check M10 current reliance, source rights, M16 audience,
 * P04 redaction/role and U09 policy without trusting caller booleans.
 */
final class InternalAttention
{
    public const VERSION='aq02.internal-attention-candidate.v1';
    private const HEX='/^[a-f0-9]{64}$/D';
    public const STATUSES=[
        'ATTENTION_CANDIDATE_INTERNAL_ONLY',
        'SUPPRESSED_SOURCE_RIGHTS_HOLD',
        'SUPPRESSED_STALE_M10_RELIANCE',
        'SUPPRESSED_ROLE_OR_REDACTION',
        'SUPPRESSED_DUPLICATE_COOLDOWN',
        'DEFERRED_BY_HUMAN_CANDIDATE',
        'SUPPRESSED_BY_HUMAN_CANDIDATE',
    ];
    private const KEYS=[
        'policy','released_output','native_release_proposal','outcome_annotation',
        'queue_watermarks','at_utc','reason_code','operator_choice',
        'defer_until_utc','existing_dedup_keys',
    ];

    /**
     * The fixture evaluates real control FLOW through an interface,
     * never the current authorization of actual source/customer metadata.
     */
    public static function composeFixture(array $input,AttentionReadPort $owner):Outcome
    {
        if(!self::fixturePortAllowed($owner)){
            return Outcome::refuse('AQ02_NATIVE_ATTENTION_OWNER_NOT_ADOPTED');
        }
        if(!Vocabulary::exactKeys($input,self::KEYS)
            || !is_array($input['policy'])
            || !AttentionPolicy::verify($input['policy'])->isOk()
            || !is_array($input['released_output'])
            || !self::validSource($input['released_output'])
            || !is_array($input['native_release_proposal'])
            || !NativeReleaseProposal::verify($input['native_release_proposal'])->isOk()
            || !is_array($input['outcome_annotation'])
            || !HistoryReviewOutcomes::verify($input['outcome_annotation'])->isOk()
            || !is_array($input['queue_watermarks'])
            || !self::validQueue($input['queue_watermarks'],$input['at_utc']??null)
            || !Vocabulary::utc($input['at_utc']??null)
            || !in_array($input['reason_code'],AttentionPolicy::REASONS,true)
            || !in_array($input['operator_choice'],['SHOW','DEFER','SUPPRESS'],true)
            || !is_array($input['existing_dedup_keys'])
            || !array_is_list($input['existing_dedup_keys'])
            || count($input['existing_dedup_keys'])>64
            || count(array_unique($input['existing_dedup_keys']))!==count($input['existing_dedup_keys'])){
            return Outcome::refuse('AQ02_ATTENTION_INPUT_OR_SOURCE_INVALID');
        }
        foreach($input['existing_dedup_keys'] as $key){
            if(!is_string($key)||preg_match(self::HEX,$key)!==1){
                return Outcome::refuse('AQ02_DEDUP_HISTORY_INVALID');
            }
        }
        $p=$input['policy'];$source=$input['released_output'];
        $native=$input['native_release_proposal'];
        $annotation=$input['outcome_annotation'];
        if($p['scope']!==$source['scope']
            || $p['scope']!==$native['scope']
            || $p['scope']!==$annotation['scope']
            || $native['finding_digest']!==$annotation['finding_digest']
            || $native['basis_digest']!==$annotation['historical_basis_digest']
            || $native['finding_digest']!==$native['historical_finding']['finding_digest']
            || $native['known_at_cutoff_utc']>$input['at_utc']
            || $annotation['inspected_at_utc']>$input['at_utc']
            || $input['at_utc']<$p['valid_from_utc']
            || $input['at_utc']>$p['valid_until_utc']
            || !in_array($input['reason_code'],$p['reason_allowlist'],true)){
            return Outcome::refuse('AQ02_ATTENTION_SCOPE_POLICY_OR_CLOCK_HOLD');
        }
        $defer=$input['defer_until_utc'];
        if($input['operator_choice']==='DEFER'){
            if(!Vocabulary::utc($defer)||$defer<=$input['at_utc']){
                return Outcome::refuse('AQ02_HUMAN_DEFER_WINDOW_INVALID');
            }
        }elseif($defer!==null){
            return Outcome::refuse('AQ02_NONDEFER_CANNOT_SET_DEADLINE');
        }
        $inspection=self::fixtureRead($owner,$source,$p,'PREPARE');
        if(!$inspection->isOk())return $inspection;
        $read=$inspection->value();
        $dedup=Digest::canonicalDigest([
            'version'=>self::VERSION,
            'scope'=>$p['scope'],
            'source_kind'=>$source['source_kind'],
            'source_id'=>$source['source_id'],
            'source_release_digest'=>$source['release_digest'],
            'role'=>$p['target_org_role'],
            'reason'=>$input['reason_code'],
            'policy_digest'=>$p['policy_digest'],
        ]);
        $status=match(true){
            $read['source_rights']!=='INTERNAL_DERIVED_ALLOWED'
                =>'SUPPRESSED_SOURCE_RIGHTS_HOLD',
            $read['current_reliance']!=='CURRENT'
                =>'SUPPRESSED_STALE_M10_RELIANCE',
            !$read['role_entitled'] || !$read['redaction_eligible']
                =>'SUPPRESSED_ROLE_OR_REDACTION',
            in_array($dedup,$input['existing_dedup_keys'],true)
                =>'SUPPRESSED_DUPLICATE_COOLDOWN',
            $input['operator_choice']==='DEFER'
                =>'DEFERRED_BY_HUMAN_CANDIDATE',
            $input['operator_choice']==='SUPPRESS'
                =>'SUPPRESSED_BY_HUMAN_CANDIDATE',
            default=>'ATTENTION_CANDIDATE_INTERNAL_ONLY',
        };
        $urgency=match($input['reason_code']){
            'CRITICAL_INVALIDATION_REVIEW'=>'HIGH',
            'CONTRARY_EVIDENCE_REVIEW'=>'NORMAL',
            'ADMITTED_FINDING_REVIEW'=>'NORMAL',
            default=>'LOW',
        };
        $v=[
            'version'=>self::VERSION,
            'status'=>$status,
            'scope'=>$p['scope'],
            'target_org_role'=>$p['target_org_role'],
            'policy_digest'=>$p['policy_digest'],
            'redaction_profile_digest'=>$p['redaction_profile_digest'],
            'source'=>$source,
            'source_read_receipt_digest'=>$read['receipt_digest'],
            'source_kind'=>$source['source_kind'],
            'source_id'=>$source['source_id'],
            'source_decision_record_id'=>$source['decision_record_id'],
            'source_release_digest'=>$source['release_digest'],
            'historical_finding_digest'=>$native['finding_digest'],
            'outcome_annotation_digest'=>$annotation['annotation_digest'],
            'queue_candidate_digest'=>$input['queue_watermarks']['queue_candidate_digest'],
            'queue_pending_reviews'=>$input['queue_watermarks']['pending_reviews'],
            'reason_code'=>$input['reason_code'],
            'urgency_recommendation'=>$urgency,
            'operator_choice'=>$input['operator_choice'],
            'defer_until_utc'=>$defer,
            'attention_dedup_key'=>$dedup,
            'inspected_at_utc'=>$input['at_utc'],
            'suppression_reason'=>$status==='ATTENTION_CANDIDATE_INTERNAL_ONLY'?null:$status,
            'is_fixture'=>true,
            'source_rights_authenticated'=>false,
            'm10_reliance_authenticated'=>false,
            'role_entitlement_authenticated'=>false,
            'real_human_choice_authenticated'=>false,
            'sent'=>false,
            'scheduler_action_authorized'=>false,
            'p04_distribution_released'=>false,
            'm16_visibility_activated'=>false,
            'customer_visibility_authorized'=>false,
            'external_delivery_authorized'=>false,
        ];
        $v['attention_digest']=Digest::canonicalDigest($v);
        return Outcome::ok($v);
    }

    public static function verify(array $v):Outcome
    {
        if(($v['version']??null)!==self::VERSION
            || !Vocabulary::scope($v['scope']??null)
            || !in_array($v['target_org_role']??null,Distribution::TARGET_ORG_ROLES,true)
            || !in_array($v['status']??null,self::STATUSES,true)
            || !in_array($v['reason_code']??null,AttentionPolicy::REASONS,true)
            || !in_array($v['urgency_recommendation']??null,AttentionPolicy::URGENCY,true)
            || !is_string($v['attention_digest']??null)
            || preg_match(self::HEX,$v['attention_digest'])!==1
            || !is_string($v['attention_dedup_key']??null)
            || preg_match(self::HEX,$v['attention_dedup_key'])!==1
            || !Vocabulary::utc($v['inspected_at_utc']??null)
            || !self::validSource($v['source']??null)
            || $v['source']['scope']!==$v['scope']
            || $v['source']['source_id']!==($v['source_id']??null)
            || $v['source']['release_digest']!==($v['source_release_digest']??null)
            || $v['source']['decision_record_id']!==($v['source_decision_record_id']??null)
            || !is_string($v['policy_digest']??null)
            || preg_match(self::HEX,$v['policy_digest'])!==1
            || !is_string($v['outcome_annotation_digest']??null)
            || preg_match(self::HEX,$v['outcome_annotation_digest'])!==1
            || ($v['status']==='ATTENTION_CANDIDATE_INTERNAL_ONLY'
                ?($v['suppression_reason']??null)!==null || !array_key_exists('suppression_reason',$v)
                :($v['suppression_reason']??null)!==$v['status'])){
            return Outcome::refuse('AQ02_ATTENTION_CANDIDATE_INVALID');
        }
        foreach([
            'source_rights_authenticated','m10_reliance_authenticated',
            'role_entitlement_authenticated','real_human_choice_authenticated',
            'sent','scheduler_action_authorized','p04_distribution_released',
            'm16_visibility_activated','customer_visibility_authorized',
            'external_delivery_authorized',
        ] as $flag){
            if(($v[$flag]??null)!==false){
                return Outcome::refuse('AQ02_ATTENTION_FORGED_AUTHORITY');
            }
        }
        if(($v['is_fixture']??null)!==true){
            return Outcome::refuse('AQ02_ATTENTION_NOT_SYNTHETIC');
        }
        try{
            $s=$v;$hash=$s['attention_digest'];unset($s['attention_digest']);
            if(!hash_equals($hash,Digest::canonicalDigest($s))){
                return Outcome::refuse('AQ02_ATTENTION_DIGEST_MISMATCH');
            }
        }catch(\Throwable){
            return Outcome::refuse('AQ02_ATTENTION_REPLAY_INVALID');
        }
        return Outcome::ok(['integrity'=>'SELF_HASHED_FIXTURE_ONLY','native_release'=>false]);
    }

    /** Always re-read owner posture at render, never trust a stored prior PASS. */
    public static function recheckFixtureAtRender(array $attention,AttentionReadPort $owner):Outcome
    {
        if(!self::fixturePortAllowed($owner)
            || !self::verify($attention)->isOk()){
            return Outcome::refuse('AQ02_RENDER_OWNER_OR_CANDIDATE_INVALID');
        }
        $p=[
            'scope'=>$attention['scope'],
            'target_org_role'=>$attention['target_org_role'],
            'redaction_profile_digest'=>$attention['redaction_profile_digest'],
        ];
        $read=self::fixtureRead($owner,$attention['source'],$p,'RENDER');
        if(!$read->isOk())return $read;
        $v=$read->value();
        if($v['current_reliance']!=='CURRENT'
            || $v['source_rights']!=='INTERNAL_DERIVED_ALLOWED'
            || !$v['role_entitled'] || !$v['redaction_eligible']){
            return Outcome::refuse('AQ02_RENDER_CURRENT_OWNER_HOLD');
        }
        return Outcome::ok([
            'status'=>'SYNTHETIC_METADATA_ELIGIBLE_FOR_OPERATOR_PREVIEW',
            'at_render_receipt_digest'=>$v['receipt_digest'],
            'actual_m10_reliance_verified'=>false,
            'customer_visibility_authorized'=>false,
        ]);
    }

    private static function fixtureRead(
        AttentionReadPort $owner,array $source,array $policy,string $stage,
    ):Outcome {
        $r=$owner->inspectForAttention(
            $source,$policy['scope'],$policy['target_org_role'],
            $policy['redaction_profile_digest'],$stage,
        );
        if(!$r->isOk())return $r;
        $v=$r->value();
        if(!is_array($v)
            || ($v['version']??null)!=='aq02.fixed-fixture-owner-inspection.v1'
            || ($v['stage']??null)!==$stage
            || ($v['scope']??null)!==$policy['scope']
            || ($v['source_id']??null)!==$source['source_id']
            || ($v['decision_record_id']??null)!==$source['decision_record_id']
            || ($v['source_release_digest']??null)!==$source['release_digest']
            || ($v['target_org_role']??null)!==$policy['target_org_role']
            || ($v['redaction_profile_digest']??null)!==$policy['redaction_profile_digest']
            || !in_array($v['current_reliance']??null,['CURRENT','SUPERSEDED'],true)
            || !in_array($v['source_rights']??null,['INTERNAL_DERIVED_ALLOWED','REVOKED','UNKNOWN'],true)
            || !is_bool($v['role_entitled']??null)
            || !is_bool($v['redaction_eligible']??null)
            || ($v['synthetic_only']??null)!==true
            || ($v['actual_owner_authenticated']??null)!==false
            || ($v['real_m10_record_queried']??null)!==false
            || ($v['customer_visibility_authorized']??null)!==false
            || !is_string($v['receipt_digest']??null)){
            return Outcome::refuse('AQ02_OWNER_RECEIPT_NOT_VERIFIABLE');
        }
        try{
            $copy=$v;$hash=$copy['receipt_digest'];unset($copy['receipt_digest']);
            if(!hash_equals($hash,Digest::canonicalDigest($copy))){
                return Outcome::refuse('AQ02_OWNER_RECEIPT_DIGEST_INVALID');
            }
        }catch(\Throwable){
            return Outcome::refuse('AQ02_OWNER_RECEIPT_UNREADABLE');
        }
        return $r;
    }

    private static function fixturePortAllowed(AttentionReadPort $owner):bool
    {
        if(PHP_SAPI!=='cli'
            || !defined('ALLFATHER_AQ02_SYNTHETIC_TEST')
            || constant('ALLFATHER_AQ02_SYNTHETIC_TEST')!==true
            || $owner::class!=='Allfather\\Loopia\\Tests\\Aq02FixtureAttentionOwner'){
            return false;
        }
        $path=realpath(dirname(__DIR__,3).'/tests/support/Aq02FixtureAttentionOwner.php');
        return $path!==false
            && (new \ReflectionClass($owner))->getFileName()===$path;
    }

    private static function validSource(mixed $source):bool
    {
        return is_array($source)
            && Vocabulary::exactKeys($source,[
                'version','scope','source_kind','source_id',
                'decision_record_id','release_digest',
                'raw_source_bytes_included','synthetic_only',
            ])
            && ($source['version']??null)==='aq02.synthetic-released-output-ref.v1'
            && Vocabulary::scope($source['scope']??null)
            && in_array($source['source_kind']??null,Distribution::SOURCE_KINDS,true)
            && Vocabulary::id($source['source_id']??null)
            && Vocabulary::id($source['decision_record_id']??null)
            && is_string($source['release_digest']??null)
            && preg_match(self::HEX,$source['release_digest'])===1
            && $source['raw_source_bytes_included']===false
            && $source['synthetic_only']===true;
    }

    private static function validQueue(mixed $q,mixed $at):bool
    {
        return is_array($q) && Vocabulary::exactKeys($q,[
            'queue_candidate_digest','pending_reviews',
            'oldest_pending_at_utc','last_human_reviewed_at_utc',
        ])
            && ($q['queue_candidate_digest']===null
                || (is_string($q['queue_candidate_digest'])
                    && preg_match(self::HEX,$q['queue_candidate_digest'])===1))
            && is_int($q['pending_reviews'])
            && $q['pending_reviews']>=0 && $q['pending_reviews']<=100
            && ($q['pending_reviews']===0
                ?$q['oldest_pending_at_utc']===null
                :Vocabulary::utc($q['oldest_pending_at_utc']))
            && ($q['last_human_reviewed_at_utc']===null
                || Vocabulary::utc($q['last_human_reviewed_at_utc']))
            && ($q['oldest_pending_at_utc']===null
                || (Vocabulary::utc($at)&&$q['oldest_pending_at_utc']<=$at))
            && ($q['last_human_reviewed_at_utc']===null
                || (Vocabulary::utc($at)&&$q['last_human_reviewed_at_utc']<=$at));
    }
}
