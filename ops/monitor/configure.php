<?php
declare(strict_types=1);

// Private CLI only. Input contains approved identifiers and limits, never a provider secret.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require __DIR__.'/../../app/autoload.php';

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Support\Env;
use Allfather\Loopia\Workflow\MissionConfiguration;

$args=getopt('',['env:','configuration:']);
if (!is_string($args['env']??null) || !is_string($args['configuration']??null)) {
    fwrite(STDERR,"Usage: php ops/monitor/configure.php --env=/private/runtime.env --configuration=/private/approved-monitor.json\n"); exit(2);
}
try {
    $path=realpath($args['configuration']); $public=realpath(__DIR__.'/../../public_html');
    if ($path===false || !is_file($path) || ($public!==false && str_starts_with($path,$public.DIRECTORY_SEPARATOR)) || filesize($path)>65536) { throw new RuntimeException('MONITOR_CONFIGURATION_FILE_INVALID'); }
    $document=json_decode((string)file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
    if (!is_array($document) || array_diff(array_keys($document),['scope','definition','execution_mode'])!==[] || !is_array($document['scope']??null) || !is_array($document['definition']??null)) { throw new RuntimeException('MONITOR_CONFIGURATION_FILE_INVALID'); }
    $env=Env::load($args['env']);
    $db=Database::connect($env->get('DB_HOST','127.0.0.1'),$env->int('DB_PORT',3306),$env->require('DB_NAME'),$env->require('DB_USER'),$env->get('DB_PASSWORD',''),$env->get('DB_SOCKET'));
    $out=(new MissionConfiguration($db,new Clock()))->configure($document['scope'],$document['definition'],(string)($document['execution_mode']??''));
    // No definition, private file path or provider credential is echoed.
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    exit($out->isOk()?0:1);
} catch (\Throwable) { fwrite(STDERR,"MONITOR_CONFIGURATION_FAILED: no activation granted; inspect private configuration and current permissions.\n"); exit(2); }
