<?php

declare(strict_types=1);

namespace Allfather\Loopia\Tests;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Workflow\{HistoryReviewRightsGateway,PointInTimeHistoricalBasis};

/**
 * HS-02 NON-EXPORTABLE synthetic permission to execute deterministic
 * *fixture-only* metadata arithmetic. Deliberately outside app/autoload.php.
 * No M02/M05 rights, source bytes, provider requests or release service.
 */
final class Hs02SyntheticUse
{
    public const VERSION='hs02.synthetic-only-internal-computation.v1';

    /** @param array<string,mixed> $basis */
    public static function run(array $basis,string $purpose): Outcome
    {
        if(PHP_SAPI!=='cli' || !defined('ALLFATHER_HS02_TEST_FIXTURE')
            || constant('ALLFATHER_HS02_TEST_FIXTURE')!==true) {
            return Outcome::refuse('HS02_FIXTURE_COMPUTATION_NOT_ENABLED');
        }
        if($purpose!=='DETERMINISTIC_INTERNAL_REVIEW'
            || !PointInTimeHistoricalBasis::verify($basis)->isOk()
            || $basis['selected_count']<1
            || $basis['selected_count']>HistoryReviewRightsGateway::MAX_REFERENCES
            || $basis['selection_integrity']!=='COMPLETE_WITHIN_INPUT') {
            return Outcome::refuse('HS02_FIXTURE_DETERMINISTIC_PURPOSE_ONLY');
        }
        $refs=$basis['selected_references'];
        $distinct=[];
        $contrary=0;
        foreach($refs as $ref) {
            $distinct[$ref['payload_sha256']]=true;
            if($ref['semantic_polarity']==='CONTRARY')$contrary++;
        }
        $counts=[
            'fixture_version'=>self::VERSION,
            'computation'=>'BOUNDED_SYNTHETIC_COUNTER',
            'basis_digest'=>$basis['basis_digest'],
            'captured_reference_count'=>count($refs),
            'exact_byte_class_count'=>count($distinct),
            'contrary_reference_count'=>$contrary,
            'real_world_event_count'=>null,
            'independent_publisher_count'=>null,
            'business_materiality'=>'NOT_ASSESSED',
            'fixture_computation_permitted'=>true,
            'fixture_is_owner_source_authority'=>false,
            'historical_reuse_admitted'=>false,
            'provider_disclosure_authorized'=>false,
            'customer_release_authorized'=>false,
        ];
        $counts['fixture_result_digest']=Digest::canonicalDigest($counts);
        return Outcome::ok($counts);
    }
}
