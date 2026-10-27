<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Digest,Outcome};

/**
 * LI-05F: source-identity and schedule-coverage evidence for a U03 OWNER REVIEW.
 *
 * Deliberately NOT a source-use decision engine. Neither a caller's native
 * preview JSON nor a signed review packet is a replacement for M02/M05 source
 * owners re-reading CURRENT rights at actual execution time. This class never
 * authorizes reuse, disclosure, inference, migration, or a customer release.
 */
final class HistoricalUseReviewPacket
{
    public const VERSION = 'li05f.u03-owner-review-candidate.v1';
    private const REQUIRED_DECISIONS = [
        'M05_SOURCE_OWNER_HISTORICAL_PURPOSE_AND_RETENTION',
        'P01_ORIGINAL_CAPTURE_AND_SCHEDULE_SCOPE',
        'M02_CURRENT_PRINCIPAL_AND_TENANT_SCOPE',
        'M10_CURRENT_RELIANCE_AND_DEPENDENCY_WITHDRAWAL',
        'OPS_SOURCE_RESTORATION_AND_REVOCATION',
        'U03_CANONICAL_ADOPTION_AND_EXACT_VECTOR_DIGEST',
    ];

    /**
     * @param array<string,mixed> $native Returned by the *original*
     *   NativeCaptureHistoryCandidate::preview; not yet trusted by a runtime.
     */
    public static function prepare(array $native):Outcome
    {
        $basis=$native['history_basis']??null;
        if(!is_array($basis)
            || ($native['version']??null)!==NativeCaptureHistoryCandidate::VERSION
            || ($native['status']??null)!=='OWNER_NATIVE_CAPTURE_METADATA_VERIFIED_U03_HOLD'
            || ($native['owner_provenance_verified']??null)!==true
            || ($native['source_bytes_returned']??null)!==false
            || ($native['U03_historical_use_admitted']??null)!==false
            || ($native['U06_model_context_authorized']??null)!==false
            || ($native['M17_input_set_authorized']??null)!==false
            || ($native['scheduler_action_authorized']??null)!==false
            || ($native['customer_output_authorized']??null)!==false
            || !is_int($native['verified_capture_count']??null)
            || $native['verified_capture_count']<0
            || $native['verified_capture_count']>HistoricalEvidenceSelector::MAX_INPUT_ROWS
            || !self::hash($native['proof_manifest_digest']??null)
            || !HistoryReviewBasis::verify($basis)->isOk()
            || $basis['historical_reuse_admitted']!==false
            || $basis['rights_disposition']!=='NOT_ASSESSED') {
            return Outcome::refuse('LI05F_OWNER_EVIDENCE_NOT_VERIFIABLE');
        }
        // A schedule receipt is its own independent, versioned review object.
        // A manually claimed "known coverage" without one is never review-ready.
        $coverage=$native['schedule_coverage_report']??null;
        $complete=($native['source_coverage_proven']??null)===true;
        if(!in_array(($native['source_coverage_proven']??null),[true,false],true)
            || ($complete && (!is_array($coverage)
                || ($coverage['version']??null)!==ScheduleHistoryCoverage::VERSION
                || ($coverage['status']??null)!=='COMPLETE_OWNER_SLOT_COVERAGE'
                || ($coverage['source_coverage_complete']??null)!==true
                || !self::hash($coverage['receipt_digest']??null)
                || !self::hash($coverage['schedule_digest']??null)
                || !is_string($coverage['schedule_version_id']??null)
                || $coverage['schedule_version_id']===''
                || ($basis['selection_integrity']??null)!=='COMPLETE_WITHIN_INPUT'
                || ($basis['coverage']['state']??null)!=='KNOWN'))
            || (!$complete && (
                ($basis['selection_integrity']??null)!=='INSUFFICIENT_BASIS'
                || ($basis['coverage']['state']??null)!=='UNKNOWN'
                || ($coverage!==null && (
                    !is_array($coverage)
                    || ($coverage['source_coverage_complete']??null)!==false
                ))
            ))) {
            return Outcome::refuse('LI05F_OWNER_COVERAGE_CONTRADICTORY');
        }
        if ($native['verified_capture_count'] < $basis['selected_count']) {
            return Outcome::refuse('LI05F_CAPTURE_PROOF_COUNT_MISMATCH');
        }
        // We don't pretend the caller-supplied preview is self-authenticating.
        // This packet only names the proof and the current owner decisions
        // reviewers need to verify independently.
        $evidence=[
            'version'=>self::VERSION,
            'source_status'=>'SOURCE_PREFLIGHT_REVIEW_ONLY',
            'basis_digest'=>$basis['manifest_digest'],
            'proof_manifest_digest'=>$native['proof_manifest_digest'],
            'scope'=>$basis['scope'],
            'period'=>$basis['period'],
            'knowledge_cutoff_utc'=>$basis['knowledge_cutoff_utc'],
            'selected_capture_count'=>$basis['selected_count'],
            'verified_capture_count'=>$native['verified_capture_count'],
            'schedule_receipt_digest'=>$complete?$coverage['receipt_digest']:null,
            'schedule_version_id'=>$complete?$coverage['schedule_version_id']:null,
            'coverage_status'=>$complete?'COMPLETE_WITHIN_OWNER_SCHEDULE':'UNKNOWN_OR_GAPPED',
            'owner_decisions_required'=>self::REQUIRED_DECISIONS,
            'historical_purpose_granted'=>false,
            'provider_disclosure_granted'=>false,
            'runtime_M17_persistence_granted'=>false,
            'model_context_granted'=>false,
            'scheduler_execution_granted'=>false,
            'customer_release_granted'=>false,
        ];
        return Outcome::ok([
            ...$evidence,
            'packet_digest'=>Digest::canonicalDigest($evidence),
            'review_readiness'=>$complete && $basis['selected_count']>0
                ? 'READY_FOR_U03_OWNER_REVIEW_NOT_ADMITTED'
                : 'HOLD_COVERAGE_OR_UNVERIFIED_SCOPE',
        ]);
    }

    /** An evidence *review* object is never an owner-granted use token. */
    public static function admit(array $packet, array $purportedApproval):Outcome
    {
        return Outcome::refuse('LI05F_U03_HISTORICAL_USE_NOT_RATIFIED');
    }

    private static function hash(mixed $value):bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D',$value)===1;
    }
}
