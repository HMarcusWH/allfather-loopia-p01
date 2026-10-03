<?php

declare(strict_types=1);

/** Add canonical vocabulary only; never assign memberships or provision tenant/product state. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require __DIR__ . '/../app/autoload.php';

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\Substrate\M02\Seed;
use Allfather\Loopia\Support\Env;

$fail = static function (string $message, int $code = 2): never {
    // Fixed labels only: never echo an exception, environment value or supplied argument.
    fwrite(STDERR, "role-vocabulary: request refused\n");
    exit($code);
};
try {
    $args = BetaCli::parseArgs($argv, ['env', 'apply'], $fail);
    if (($args['apply'] ?? null) !== '1' || ($args['env'] ?? '') === '') {
        $fail('explicit --apply and --env are required');
    }
    $env = Env::load($args['env']);
    if (!in_array($env->get('APP_ENV'), ['staging', 'testing'], true)) {
        $fail('staging/testing only');
    }
    $db = BetaCli::connect($env, $fail);
    $created = (new Seed($db, new Clock()))->ensureVocabulary();
    BetaCli::emit(['status' => 'ROLE_VOCABULARY_PROVISIONED', 'created' => $created,
        'memberships_assigned' => 0, 'memberships_upgraded' => 0,
        'native_execution_admitted' => false, 'production_authorized' => false]);
} catch (Throwable) {
    $fail('canonical vocabulary conflict or provisioning failure', 4);
}
