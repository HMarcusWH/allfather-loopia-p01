<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Product\P01\HistoricalFindingAdapter;
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * AN-03: explicit HOLD between a descriptive historical candidate and
 * genuine M02/M05/M07/M08/M10/M16, P02, P04 native release owners.
 *
 * There is NO CompanySignals single-diff fallback, synthetic diff ID,
 * customer-visible projection, recipient write or source-use override.
 */
final class HistoryReviewRelease
{
    public const VERSION='an03.provisional-multi-evidence-release-hold.v1';
    public const STAGES=['PROPOSAL','POST_REVIEW','PRE_RELEASE'];

    /**
     * Accept optional native HS-02 owner inspection for SAFETY DIAGNOSTICS
     * only. Even a real native M02/P01/M05 current-use check remains
     * HOLD_U03, not permission to historically reuse bytes.
     *
     * Self-reported human approval or M10 verdict is never accepted here.
     */
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
            // This is a caller-supplied shape check, not a native adapter
            // invocation or permission. The actual owner must independently
            // re-read M05/source policies at genuine release time.
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

    /** No caller data, role, configured policy or synthetic proof grants release. */
    public static function release(
        array $finding,array $callerClaims=[],
    ):Outcome {
        return Outcome::refuse('AN03_U03_U06_U07_M10_HISTORICAL_RELEASE_NOT_ADOPTED');
    }
}
