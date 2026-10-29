<?php

declare(strict_types=1);

/** AN-01: no-live-transport reconciliation posture, never a paid retry. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once dirname(__DIR__,2).'/app/autoload.php';

use Allfather\Loopia\Workflow\HistoryReviewReconciliation;

$args=array_slice($argv,1);
if(count($args)!==1||!in_array($args[0],['--status','--help'],true)){
    fwrite(STDERR,"AN01_PROVIDER_OWNER_RECONCILIATION_REQUIRED\n");exit(3);
}
echo json_encode([
    'version'=>HistoryReviewReconciliation::VERSION,
    'state'=>'SOURCE_ONLY_UNKNOWN_OUTCOME_HOLD',
    'owner_provider_response_available'=>false,
    'live_attempt_reconciliation'=>false,
    'automatic_paid_retry'=>false,
    'refund_on_unknown_outcome'=>false,
    'admitted_model_context'=>false,
    'customer_output_authorized'=>false,
],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
