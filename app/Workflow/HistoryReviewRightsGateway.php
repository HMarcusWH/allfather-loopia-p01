<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Product\P01\SourceOriginAssertionReader;
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * HS-02: owner-native historical-use decision seam.
 *
 * Current P01/M02/M05 reference authentication is necessary but NOT
 * sufficient for U03 historical reuse or U06 model/provider disclosure.
 * This adapter can never return ALLOW before the six independent source-
 * owner/rights decisions are adopted in a separately reviewed contract.
 *
 * No SQL, source bytes or transport are handled here. Rights are always
 * re-evaluated through the existing owner reader at the invoked step.
 */
final class HistoryReviewRightsGateway
{
    public const VERSION='hs02.current-use-gateway-candidate.v1';
    public const PURPOSES=[
        'INTERNAL_DETERMINISTIC_REUSE',
        'MODEL_ASSISTED_HISTORICAL_ANALYSIS',
        'PROVIDER_SOURCE_BYTE_DISCLOSURE',
    ];
    public const STAGES=[
        'BEFORE_BASIS_REUSE',
        'BEFORE_MODEL_DISPATCH',
        'AFTER_MODEL_RETURN',
        'BEFORE_INFORMATION_RELEASE',
    ];
    public const HOLD='HOLD_U03_U06_UNRATIFIED';

    public function __construct(
        private readonly SourceOriginAssertionReader $originOwner,
    ) {}

    /**
     * @param array<string,mixed> $actor current M02 actor, not caller-granted
     * @param array<string,mixed> $basis exactly frozen HS01 metadata
     * @param array<string,mixed> $selector closed purpose/stage/snapshot binding
     */
    public function inspect(array $actor,array $basis,array $selector): Outcome
    {
        if (!self::keys($actor,['tenant_id','workspace_id','actor_type','actor_id'])
            || !Vocabulary::id($actor['tenant_id']??null)
            || !Vocabulary::id($actor['workspace_id']??null)
            || !Vocabulary::id($actor['actor_id']??null)
            || !in_array($actor['actor_type']??null,['OPERATOR','SERVICE'],true)
            || !self::keys($selector,[
                'competitor_id','purpose','stage','review_at_utc',
                'basis_digest','source_snapshot_ids',
            ])
            || !Vocabulary::id($selector['competitor_id']??null)
            || !in_array($selector['purpose']??null,self::PURPOSES,true)
            || !in_array($selector['stage']??null,self::STAGES,true)
            || !HistoricalEvidenceSelector::utc($selector['review_at_utc']??null)
            || !self::hex($selector['basis_digest']??null)
            || !is_array($selector['source_snapshot_ids']??null)
            || !array_is_list($selector['source_snapshot_ids'])
            || count($selector['source_snapshot_ids'])<1
            || count($selector['source_snapshot_ids'])>SourceOriginAssertionReader::MAX_REFERENCES
            || count($selector['source_snapshot_ids'])!==
                count(array_unique($selector['source_snapshot_ids']))) {
            return Outcome::refuse('HS02_SELECTOR_INVALID');
        }
        foreach($selector['source_snapshot_ids'] as $id){
            if(!Vocabulary::id($id))return Outcome::refuse('HS02_SELECTOR_INVALID');
        }
        if (!PointInTimeHistoricalBasis::verify($basis)->isOk()
            || ($basis['status']??null)!=='OFFLINE_NOT_ADMITTED'
            || ($basis['selection_integrity']??null)!=='COMPLETE_WITHIN_INPUT'
            || ($basis['historical_reuse_admitted']??null)!==false
            || ($basis['rights_disposition']??null)!=='NOT_ASSESSED') {
            return Outcome::refuse('HS02_BASIS_NOT_VERIFIED');
        }
        if (($basis['scope']['tenant_id']??null)!==$actor['tenant_id']
            || ($basis['scope']['workspace_id']??null)!==$actor['workspace_id']
            || ($basis['scope']['competitor_id']??null)!==$selector['competitor_id']
            || $basis['review_at_utc']!==$selector['review_at_utc']
            || $basis['basis_digest']!==$selector['basis_digest']) {
            return Outcome::refuse('HS02_BASIS_SCOPE_OR_CUTOFF_MISMATCH');
        }
        // Point-in-time metadata is not enough: the supplied selector must
        // name *exactly* the captured snapshot set, not an arbitrary subset.
        $named=$selector['source_snapshot_ids'];
        $basisIds=array_column($basis['selected_references'],'snapshot_id');
        sort($named,SORT_STRING);
        sort($basisIds,SORT_STRING);
        if ($basisIds!==$named
            || count($basisIds)>SourceOriginAssertionReader::MAX_REFERENCES) {
            return Outcome::refuse('HS02_SOURCE_REFERENCE_SET_MISMATCH');
        }

        // Re-query NOW, not at the old knowledge cut: revoked/expired cards,
        // M02 role, tenant tier and M05 source registration can narrow usage.
        // This is exactly the OB-03 owner path, not a second source-reader SQL.
        $read=$this->originOwner->inspect(
            $actor,$selector['competitor_id'],$named);
        if(!$read->isOk()){
            return $read->isFailure()
                ? Outcome::failure('HS02_CURRENT_SOURCE_OWNER_DEPENDENCY_FAILED')
                : Outcome::refuse('HS02_CURRENT_SOURCE_OWNER_REFUSED');
        }
        $owner=$read->value();
        if (($owner['current_owner_read_verified']??null)!==true
            || ($owner['origin_independence_certified']??null)!==false
            || ($owner['source_use_for_new_analysis_admitted']??null)!==false
            || ($owner['observed_captures']??null)!==count($named)
            || !is_array($owner['references']??null)){
            return Outcome::refuse('HS02_OWNER_READ_CONTRACT_INVALID');
        }
        $evidenceBySnapshot=[];
        foreach($basis['selected_references'] as $ref){
            $evidenceBySnapshot[$ref['snapshot_id']]=[$ref['source_ref_id'],$ref['evidence_id']];
        }
        foreach($owner['references'] as $ref){
            $expected=$evidenceBySnapshot[$ref['source_snapshot_id']??'']??null;
            if($expected===null
                || $expected!==[$ref['source_ref_id'],$ref['evidence_id']]){
                return Outcome::refuse('HS02_OWNER_REFERENCE_LINEAGE_MISMATCH');
            }
        }
        // A verified reference is NOT a verified original publisher, current
        // historical right, provider sharing right, or executed human review.
        // Never produce a signed or unrestricted ALLOW with an invented U03 id.
        $decision=[
            'schema_version'=>self::VERSION,
            'decision'=>self::HOLD,
            'purpose'=>$selector['purpose'],
            'stage'=>$selector['stage'],
            'scope'=>[
                'tenant_id'=>$actor['tenant_id'],
                'workspace_id'=>$actor['workspace_id'],
                'competitor_id'=>$selector['competitor_id'],
            ],
            'actor_type'=>$actor['actor_type'],
            'review_at_utc'=>$selector['review_at_utc'],
            'historical_basis_digest'=>$basis['basis_digest'],
            'owner_reference_digest'=>$owner['assertion_digest'],
            'reference_count'=>count($owner['references']),
            'current_reference_scope_check'=>'CURRENT_M02_P01_M05_PASS',
            'source_license_for_historical_reuse'=>'UNRESOLVED_U03',
            'current_terms_for_model_use'=>'UNRESOLVED_U03_U06',
            'retained_bytes_availability'=>'NOT_PROVEN_BY_REFERENCE_READBACK',
            'original_publisher_identity'=>'UNKNOWN',
            'redaction_projection'=>'NOT_ADOPTED',
            'u03_multi_owner_ratification'=>'PENDING',
            'u06_model_purpose_adoption'=>'PENDING',
            'can_read_original_source_bytes'=>false,
            'can_use_for_historical_analysis'=>false,
            'can_send_to_external_provider'=>false,
            'can_admit_model_result'=>false,
            'can_release_to_customer'=>false,
        ];
        $decision['decision_digest']=Digest::canonicalDigest($decision);
        return Outcome::ok($decision);
    }

    /** Immutable structural record only, never a right or authority receipt. */
    public static function verifyCandidate(array $decision): Outcome
    {
        if(($decision['schema_version']??null)!==self::VERSION
            || ($decision['decision']??null)!==self::HOLD
            || !in_array($decision['purpose']??null,self::PURPOSES,true)
            || !in_array($decision['stage']??null,self::STAGES,true)
            || ($decision['u03_multi_owner_ratification']??null)!=='PENDING'
            || ($decision['u06_model_purpose_adoption']??null)!=='PENDING'
            || ($decision['source_license_for_historical_reuse']??null)!=='UNRESOLVED_U03'
            || ($decision['can_send_to_external_provider']??null)!==false
            || ($decision['can_use_for_historical_analysis']??null)!==false
            || ($decision['can_release_to_customer']??null)!==false
            || !self::hex($decision['decision_digest']??null)){
            return Outcome::refuse('HS02_CURRENT_USE_CANDIDATE_INVALID');
        }
        $digest=$decision['decision_digest'];
        unset($decision['decision_digest']);
        try {
            if(!hash_equals($digest,Digest::canonicalDigest($decision))){
                return Outcome::refuse('HS02_CURRENT_USE_DIGEST_MISMATCH');
            }
        }catch(\Throwable){
            return Outcome::refuse('HS02_CURRENT_USE_ENCODING_INVALID');
        }
        return Outcome::ok([
            'integrity'=>'SCOPED_CANDIDATE_ONLY',
            'historical_source_use_authorized'=>false,
        ]);
    }

    private static function keys(mixed $v,array $names):bool {
        if(!is_array($v))return false;
        $actual=array_keys($v);sort($actual,SORT_STRING);sort($names,SORT_STRING);
        return $actual===$names;
    }
    private static function hex(mixed $v):bool {
        return is_string($v)&&preg_match('/^[a-f0-9]{64}$/D',$v)===1;
    }
}
