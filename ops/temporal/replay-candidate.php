<?php

declare(strict_types=1);

/** TM-02 metadata-only replay availability, not a production SQL reader. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once dirname(__DIR__,2).'/app/autoload.php';

use Allfather\Loopia\Substrate\M17\{MemoryStore,Replay};

$args=array_slice($argv,1);
if(count($args)!==1||!in_array($args[0],['--help','--status'],true)){
    fwrite(STDERR,"TM02_DISPOSABLE_OWNER_SESSION_REQUIRED\n");exit(3);
}
echo json_encode([
    'version'=>'tm02.p01-memory-v2-readback.v1',
    'status'=>'NOT_AVAILABLE_WITHOUT_DISPOSABLE_OWNER_SESSION',
    'replay_engine'=>Replay::VERSION,
    'historical_use_admitted'=>false,
    'owner_source_bytes_available'=>false,
    'live_factory_available'=>false,
    'provider_or_customer_release_authorized'=>false,
],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
