<?php

declare(strict_types=1);

/**
 * HS-01 local synthetic metadata inspection. Deliberately no CLI actor
 * impersonation, source acquisition, DB, model, action or live migration.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once dirname(__DIR__,2).'/app/autoload.php';

use Allfather\Loopia\Workflow\PointInTimeHistoricalBasis;

$args=array_slice($argv,1);
if (count($args)!==1 || !in_array($args[0],['--help','--status','--synthetic-development'],true)) {
    fwrite(STDERR,"HS01_NATIVE_U03_OWNER_CONTEXT_NOT_AVAILABLE\n");
    exit(3);
}
if ($args[0]==='--help'||$args[0]==='--status') {
    echo json_encode([
        'version'=>PointInTimeHistoricalBasis::VERSION,
        'state'=>'OFFLINE_METADATA_CANDIDATE_ONLY',
        'owner_scope'=>'NOT_AUTHENTICATED_BY_CLI',
        'calendar'=>PointInTimeHistoricalBasis::CALENDAR,
        'candidate_tables'=>'270_NOT_RUNTIME_MIGRATED',
        'native_historical_reuse'=>false,
        'source_bytes_available'=>false,
        'model_dispatch_available'=>false,
        'customer_release_authorized'=>false,
        'synthetic_test_mode'=>'--synthetic-development requires ALLFATHER_HS01_SYNTHETIC_TEST=1',
    ],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    exit(0);
}
if (getenv('ALLFATHER_HS01_SYNTHETIC_TEST')!=='1') {
    fwrite(STDERR,"HS01_SYNTHETIC_TEST_MODE_REQUIRED\n");
    exit(3);
}
$path=dirname(__DIR__,2).'/tests/fixtures/li05_offline_basis_vectors.json';
if(!is_file($path)) {
    fwrite(STDERR,"HS01_SYNTHETIC_FIXTURE_MISSING\n");
    exit(3);
}
try {
    $v=json_decode((string)file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
    $input=[
        'scope'=>$v['scope'],'period'=>$v['period'],'baseline_period'=>$v['baseline_period'],
        'baseline_null_reason'=>null,'knowledge_cutoff_utc'=>$v['knowledge_cutoff_utc'],
        'rows'=>$v['rows'],'max_references'=>8,'coverage'=>$v['known_coverage'],
    ];
    $candidate=PointInTimeHistoricalBasis::freeze([
        'calendar'=>PointInTimeHistoricalBasis::CALENDAR,
        'legacy_input'=>$input,'previous_basis_digest'=>null,
        'review_at_utc'=>'2026-03-16T00:00:00Z',
    ]);
    if(!$candidate->isOk()) {
        fwrite(STDERR,"HS01_SYNTHETIC_BASIS_REFUSED\n");
        exit(4);
    }
    $preview=PointInTimeHistoricalBasis::preview($candidate->value());
    if(!$preview->isOk()) {
        fwrite(STDERR,"HS01_SYNTHETIC_PREVIEW_REFUSED\n");
        exit(4);
    }
    echo json_encode($preview->value(),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
} catch(Throwable) {
    fwrite(STDERR,"HS01_SYNTHETIC_PREVIEW_UNAVAILABLE\n");
    exit(4);
}
