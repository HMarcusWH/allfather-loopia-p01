<?php

declare(strict_types=1);

/**
 * OB-02 live apply is UNADOPTED. There is no CLI flag that bypasses U01/U02,
 * source use, migration approval, current M02 actor or M05 eligibility.
 *
 * Positive metadata-only append tests exist solely in disposable MariaDB
 * using ObservationAssessmentStore::forDisposableTest and a TEST workspace.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
if (in_array('--help', $argv, true) || in_array('--status', $argv, true)) {
    echo json_encode([
        'version'=>'ob02.disposable-append-only-assessments.v1',
        'operation'=>'BACKFILL_APPLY',
        'native_state'=>'U01_U02_NOT_ADOPTED',
        'candidate_schema'=>'docs/longitudinal/proposals/260_competitor_assessments_CANDIDATE.sql',
        'candidate_scope'=>'DISPOSABLE_MARIADB_TEST_ONLY',
        'native_apply_authorized'=>false,
        'irreversible_legacy_history_mutation'=>false,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}
fwrite(STDERR, "OB02_NATIVE_PERSISTENCE_U01_U02_NOT_ADOPTED\n");
exit(3);
