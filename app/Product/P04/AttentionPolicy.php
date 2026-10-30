<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P04;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * AQ-02: versioned internal attention POLICY CANDIDATE.
 *
 * This describes a proposed narrow role, cadence, redaction and event budget.
 * Only adopted P04/M16/M10 owners may provide real authorization. No caller
 * array, boolean or self-computed digest grants U09, visibility or delivery.
 */
final class AttentionPolicy
{
    public const VERSION='aq02.inert-attention-policy.v1';
    public const REASONS=[
        'ADMITTED_FINDING_REVIEW','CONTRARY_EVIDENCE_REVIEW',
        'CRITICAL_INVALIDATION_REVIEW','QUIET_REVIEW_DUE',
    ];
    public const URGENCY=['LOW','NORMAL','HIGH'];
    public const MAX_DIGEST_ITEMS=16;
    private const FIELDS=[
        'scope','policy_key','target_org_role','reason_allowlist',
        'max_digest_items','cooldown_seconds','redaction_profile_digest',
        'valid_from_utc','valid_until_utc',
    ];

    public static function freeze(array $input):Outcome
    {
        if(!Vocabulary::exactKeys($input,self::FIELDS)
            || !Vocabulary::scope($input['scope']??null)
            || !Vocabulary::id($input['policy_key']??null)
            || !in_array($input['target_org_role']??null,Distribution::TARGET_ORG_ROLES,true)
            || !is_array($input['reason_allowlist']??null)
            || !array_is_list($input['reason_allowlist'])
            || count($input['reason_allowlist'])<1
            || count($input['reason_allowlist'])>count(self::REASONS)
            || count(array_unique($input['reason_allowlist']))!==count($input['reason_allowlist'])
            || array_diff($input['reason_allowlist'],self::REASONS)!==[]
            || !is_int($input['max_digest_items'])
            || $input['max_digest_items']<1
            || $input['max_digest_items']>self::MAX_DIGEST_ITEMS
            || !is_int($input['cooldown_seconds'])
            || $input['cooldown_seconds']<0
            || $input['cooldown_seconds']>604800
            || !is_string($input['redaction_profile_digest'])
            || preg_match('/^[a-f0-9]{64}$/D',$input['redaction_profile_digest'])!==1
            || !Vocabulary::utc($input['valid_from_utc']??null)
            || !Vocabulary::utc($input['valid_until_utc']??null)
            || $input['valid_until_utc']<=$input['valid_from_utc']){
            return Outcome::refuse('AQ02_POLICY_INPUT_OR_SCOPE_INVALID');
        }
        $v=[
            'version'=>self::VERSION,
            'status'=>'CANDIDATE_U09_NOT_ADOPTED',
            ...$input,
            'purpose'=>'INTERNAL_OPERATOR_ATTENTION_ONLY',
            'audience_ceiling'=>'OPERATOR_ONLY',
            'external_delivery_authorized'=>false,
            'customer_visibility_authorized'=>false,
            'policy_adopted_by_owner'=>false,
        ];
        $v['policy_digest']=Digest::canonicalDigest($v);
        return Outcome::ok($v);
    }

    public static function verify(array $policy):Outcome
    {
        $input=[];
        foreach(self::FIELDS as $key){
            if(!array_key_exists($key,$policy)){
                return Outcome::refuse('AQ02_POLICY_REQUIRED_FIELD_MISSING');
            }
            $input[$key]=$policy[$key];
        }
        $fresh=self::freeze($input);
        if(!$fresh->isOk() || $fresh->value()!==$policy){
            return Outcome::refuse('AQ02_POLICY_REHASH_OR_GRANT_FORGED');
        }
        return Outcome::ok([
            'integrity'=>'EXACT_CANDIDATE_ONLY',
            'owner_adopted'=>false,
        ]);
    }
}
