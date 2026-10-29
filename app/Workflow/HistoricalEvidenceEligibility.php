<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\Outcome;

/**
 * LI-05A descriptive input posture, NOT an M02/P01/M05 historical-use decision.
 * The caller cannot submit a permission/allowed flag or cause an ADMITTED result.
 */
final class HistoricalEvidenceEligibility
{
    public const VERSION = 'li05.offline-disposition.v1';
    public const STATES = ['UNASSESSED', 'DENIED', 'UNKNOWN', 'QUARANTINED', 'REVOKED', 'MISSING_BYTES'];

    /** @param array<string,mixed> $reported */
    public static function describe(array $reported): Outcome
    {
        if (array_keys($reported) !== ['reported_disposition']
            || !is_string($reported['reported_disposition'])
            || !in_array($reported['reported_disposition'], self::STATES, true)) {
            return Outcome::refuse('LI05_DISPOSITION_CONTRACT_INVALID');
        }

        $state = $reported['reported_disposition'];
        return Outcome::ok([
            'reported_disposition' => $state,
            'rights_disposition' => 'NOT_ASSESSED',
            'historical_reuse_admitted' => false,
            'offline_metadata_candidate' => $state === 'UNASSESSED',
        ]);
    }
    /**
     * HS-02: distinguish historical-analysis *purposes* before any attempted
     * use. No one of these categories inherits permissions from another.
     */
    public static function purposePosture(string $purpose): Outcome
    {
        if (!in_array($purpose, HistoryReviewRightsGateway::PURPOSES, true)) {
            return Outcome::refuse('HS02_HISTORICAL_USE_PURPOSE_UNKNOWN');
        }
        return Outcome::ok([
            'version'=>'hs02.purpose-posture.v1',
            'purpose'=>$purpose,
            'original_li05_disposition_owner'=>'HistoricalEvidenceEligibility.v1',
            'current_native_scope_read_possible'=>true,
            'historical_source_use_admitted'=>false,
            'model_analysis_admitted'=>false,
            'external_provider_byte_disclosure_admitted'=>false,
            'm10_current_reliance_verified'=>false,
            'u03_contract_hash'=>null,
            'u03_owner_ratification'=>'PENDING',
            'decision'=>'HOLD',
        ]);
    }

}
