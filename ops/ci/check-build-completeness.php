<?php

declare(strict_types=1);

/**
 * Offline, stdout-only candidate package receipt. No writes, DB, network,
 * credential access, subprocess, Git ref mutations or deployment.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}
require dirname(__DIR__, 2) . '/app/autoload.php';

use Allfather\Loopia\Ops\BuildFirstReceipt;

try {
    $package = null;
    $headSha = null;
    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--package=([A-Z]{2}-\d{2})$/D', $argument, $match) === 1 && $package === null) {
            $package = $match[1];
        } elseif (preg_match('/^--declared-head=([a-f0-9]{40})$/D', $argument, $match) === 1 && $headSha === null) {
            $headSha = $match[1];
        } else {
            throw new \DomainException('BF01_UNRECOGNIZED_OR_DUPLICATED_ARGUMENT');
        }
    }
    if ($package === null) {
        throw new \DomainException('BF01_REQUIRED_PACKAGE_ARGUMENT');
    }
    $receipt = BuildFirstReceipt::compose(dirname(__DIR__, 2), $package, $headSha);
    echo json_encode($receipt, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} catch (\Throwable $error) {
    fwrite(STDERR, 'BF01_PACKAGE_RECEIPT_REFUSED: ' . $error->getMessage() . "\n");
    exit(1);
}
