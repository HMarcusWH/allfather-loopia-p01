<?php

declare(strict_types=1);

/**
 * OB-02 operator PREVIEW entrypoint — no native authless SQL.
 *
 * A real preview must be invoked through the existing authenticated
 * ObservationHistory::previewAssessmentBackfill() owner factory, which
 * enforces current M02 subscription and M05 source eligibility. CLI actor
 * flags cannot impersonate that authorization. This command is a bounded
 * status/introspection tool, not a fallback to direct DB reads.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
$usage = in_array('--help', $argv, true);
$status = in_array('--status', $argv, true);
$version = 'ob02.cursor-ledger-preview.v1';
if ($usage || $status) {
    echo json_encode([
        'version'=>$version,
        'operation'=>'BACKFILL_PREVIEW',
        'state'=>'NATIVE_OWNER_CONTEXT_NOT_AVAILABLE_TO_CLI',
        'read_entrypoint'=>'ObservationHistory::previewAssessmentBackfill',
        'page_max'=>20,
        'requires'=>['M02 actor','evidence.read','competition_monitoring.read',
            'current M05 source eligibility','approved U01/U02 for live persistence'],
        'can_read_source_bytes'=>false,
        'can_apply_live'=>false,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}
fwrite(STDERR, "OB02_OWNER_AUTHENTICATED_HISTORY_CONTEXT_REQUIRED\n");
exit(3);
