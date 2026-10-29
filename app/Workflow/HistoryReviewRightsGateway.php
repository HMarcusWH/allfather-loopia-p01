<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Digest,Outcome};
use Allfather\Loopia\Substrate\M17\{HistoricalCaptureProbe,Vocabulary};

/**
 * HS-02: owner-native source-use inspection and stage rechecks for an
 * immutable HS-01 candidate. A successful native owner proof is NEVER a
 * historical-use, model/provider-disclosure or release authorization.
 *
 * The existing M02/P01/M05 source-card, terms, entitlement, evidence, and
 * PRIVATE retained-byte checks are delegated to HistoricalCaptureProbe.
 * No parallel SQL rights evaluator, credential selector or source read exists.
 *
 * U03's six owners have not ratified historical-use purposes, so all
 * production purposes are HOLD even if the current capture is coherent.
 */
final class HistoryReviewRightsGateway
{
    public const VERSION = 'hs02.historical-use-inspection.v1';
    public const PURPOSES = [
        'DETERMINISTIC_INTERNAL_REVIEW',
        'MODEL_ASSISTED_HISTORICAL_ANALYSIS',
        'EXTERNAL_PROVIDER_BYTE_DISCLOSURE',
    ];
    public const STAGES = ['PRE_DISPATCH','POST_RETURN','PRE_RELEASE'];
    public const MAX_REFERENCES = 20;
    private const HEX = '/^[a-f0-9]{64}$/D';

    public function __construct(
        private readonly HistoricalCaptureProbe $owner,
        private readonly Clock $clock,
    ) {}

    /**
     * Inspect only. A returned Outcome::OK means the *inspection succeeded*
     * and contains HOLD, never that the requested operation is permitted.
     *
     * @param array<string,mixed> $actor The existing exact M02 principal
     * @param array<string,mixed> $basis A verified HS-01 candidate
     */
    public function inspect(
        array $actor,
        array $basis,
        string $purpose,
        string $stage = 'PRE_DISPATCH',
        ?string $priorOwnerFingerprint = null,
    ): Outcome {
        if (!Vocabulary::exactKeys($actor, ['tenant_id','workspace_id','actor_type','actor_id'])
            || !in_array($actor['actor_type'] ?? null, ['OPERATOR','SERVICE'], true)
            || !Vocabulary::id($actor['tenant_id'] ?? null)
            || !Vocabulary::id($actor['workspace_id'] ?? null)
            || !Vocabulary::id($actor['actor_id'] ?? null)
            || !in_array($purpose, self::PURPOSES, true)
            || !in_array($stage, self::STAGES, true)
            || ($stage === 'PRE_DISPATCH' && $priorOwnerFingerprint !== null)
            || ($stage !== 'PRE_DISPATCH' && !self::hex($priorOwnerFingerprint))) {
            return Outcome::refuse('HS02_SOURCE_USE_SELECTOR_INVALID');
        }
        if (!PointInTimeHistoricalBasis::verify($basis)->isOk()) {
            return Outcome::refuse('HS02_BASIS_INTEGRITY_INVALID');
        }
        $scope=$basis['scope'];
        if ($scope['tenant_id']!==$actor['tenant_id']
            || $scope['workspace_id']!==$actor['workspace_id']
            || !Vocabulary::id($scope['competitor_id'])
            || !Vocabulary::id($scope['question_id'])
            || $basis['selected_count']<1
            || $basis['selected_count']>self::MAX_REFERENCES
            || $basis['historical_reuse_admitted']!==false
            || $basis['provider_disclosure_authorized']!==false
            || $basis['model_context_authorized']!==false) {
            return Outcome::refuse('HS02_BASIS_SCOPE_OR_CAP_INVALID');
        }
        $now=$this->clock->now();
        $nowSecond=substr($now,0,19).'Z';
        if (!self::millis($now)
            || $basis['knowledge_cutoff_utc']>$nowSecond
            || $basis['review_at_utc']>$nowSecond) {
            return Outcome::refuse('HS02_CURRENT_REVIEW_CLOCK_INVALID');
        }

        // HistoricalCaptureProbe re-evaluates actual M02 grants, P01 source
        // card, current source terms/policy, M05 linked evidence and private
        // retained content bytes on every call. We never accept a caller-made
        // proof, evidence byte hash or rights flag in place of that owner.
        $proofs=[];
        foreach($basis['selected_references'] as $reference) {
            $checked=$this->owner->inspect(
                $actor,$scope['competitor_id'],$reference['snapshot_id'],$now,
            );
            if (!$checked->isOk()) {
                return $checked->isFailure()
                    ? Outcome::failure('HS02_OWNER_CAPTURE_DEPENDENCY_FAILED')
                    : Outcome::refuse('HS02_CURRENT_OWNER_SOURCE_HOLD');
            }
            $proof=$checked->value();
            $observed=self::floorSecond($proof['observed_at_utc']??null);
            $recorded=self::ceilSecond($proof['observed_at_utc']??null);
            $known=self::ceilSecond($proof['known_at_upper_bound_utc']??null);
            if (($proof['status']??null)!=='NATIVE_HISTORY_PREFLIGHT_VERIFIED_BUT_U03_HOLD'
                || ($proof['version']??null)!==HistoricalCaptureProbe::VERSION
                || ($proof['owner_native_metadata_verified']??null)!==true
                || ($proof['retained_bytes_verified_privately']??null)!==true
                || ($proof['source_bytes_returned']??null)!==false
                || ($proof['U03_historical_use_admitted']??null)!==false
                || ($proof['provider_disclosure_authorized']??null)!==false
                || ($proof['M17_input_set_authorized']??null)!==false
                || ($proof['runtime_persistence_authorized']??null)!==false
                || ($proof['source_snapshot_id']??null)!==$reference['snapshot_id']
                || ($proof['source_ref_id']??null)!==$reference['source_ref_id']
                || ($proof['evidence_id']??null)!==$reference['evidence_id']
                || ($proof['competitor_id']??null)!==$scope['competitor_id']
                || ($proof['capture_digest']??null)!==$reference['payload_sha256']
                || !self::hex($proof['current_source_policy_digest']??null)
                || $observed!==$reference['observed_at']
                || $recorded!==$reference['recorded_at']
                || $known!==$reference['known_at']
                || $known>$basis['knowledge_cutoff_utc']) {
                return Outcome::refuse('HS02_M05_OR_CLOCK_BINDING_MISMATCH');
            }
            $proofs[]=[
                'capture_snapshot_id'=>$proof['source_snapshot_id'],
                'm05_evidence_id'=>$proof['evidence_id'],
                'source_ref_id'=>$proof['source_ref_id'],
                'capture_content_digest'=>$proof['capture_digest'],
                'current_source_policy_digest'=>$proof['current_source_policy_digest'],
                'known_at_bound_utc'=>$known,
                'bytes_verified_privately'=>true,
                'historical_use_admitted'=>false,
            ];
        }
        $fingerprint=Digest::canonicalDigest([
            'version'=>self::VERSION,
            'actor'=>$actor,
            'scope'=>$scope,
            'basis_digest'=>$basis['basis_digest'],
            'purpose'=>$purpose,
            'owner_proofs'=>$proofs,
        ]);
        if ($stage!=='PRE_DISPATCH'
            && !hash_equals((string)$priorOwnerFingerprint,$fingerprint)) {
            return Outcome::refuse('HS02_OWNER_PROVENANCE_CHANGED_HOLD');
        }

        // An owner-authenticated capture reference proves that a stored
        // P01/M05 object exists and CURRENT rights still permit the narrow
        // native preflight. It does NOT prove historical-purpose rights,
        // independent publication, commercial materiality, M10 current
        // reliance, retention, provider disclosure, or legal re-use.
        return Outcome::ok([
            'version'=>self::VERSION,
            'decision'=>'HOLD_U03_HISTORICAL_USE_NOT_RATIFIED',
            'requested_purpose'=>$purpose,
            'recheck_stage'=>$stage,
            'basis_digest'=>$basis['basis_digest'],
            'current_owner_fingerprint'=>$fingerprint,
            'owner_native_capture_count'=>count($proofs),
            'current_m02_p01_m05_checked'=>true,
            'source_terms_policy_checked_at_current_time'=>true,
            'retained_bytes_verified_privately'=>true,
            'clock_and_exact_reference_binding_checked'=>true,
            'preexisting_owner_read_scope_only'=>true,
            'basis_completeness'=>$basis['selection_integrity'],
            'coverage_owner_verified'=>false,
            'semantic_claims_owner_verified'=>false,
            'publisher_independence_verified'=>false,
            'm10_current_reliance_checked'=>false,
            'u03_owner_decisions_ratified'=>false,
            'internal_deterministic_use_authorized'=>false,
            'model_historical_analysis_authorized'=>false,
            'provider_byte_disclosure_authorized'=>false,
            'source_bytes_returned'=>false,
            'model_dispatch_authorized'=>false,
            'historical_reuse_admitted'=>false,
            'customer_release_authorized'=>false,
            'production_authorized'=>false,
        ]);
    }

    private static function hex(mixed $value): bool
    {
        return is_string($value) && preg_match(self::HEX,$value)===1;
    }

    private static function millis(mixed $value): bool
    {
        if(!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D',$value)!==1) return false;
        $v=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z',
            $value,new \DateTimeZone('UTC'));
        return $v!==false && $v->format('Y-m-d\TH:i:s.v\Z')===$value;
    }

    private static function floorSecond(mixed $value): ?string
    {
        return self::millis($value)?substr($value,0,19).'Z':null;
    }

    private static function ceilSecond(mixed $value): ?string
    {
        if(!self::millis($value))return null;
        $v=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z',
            $value,new \DateTimeZone('UTC'));
        if($v===false)return null;
        if((int)$v->format('v')!==0)$v=$v->modify('+1 second');
        return $v->format('Y-m-d\TH:i:s\Z');
    }
}
