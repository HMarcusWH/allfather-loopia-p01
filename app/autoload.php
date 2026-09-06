<?php

declare(strict_types=1);

/**
 * Zero-dependency PSR-4 autoloader.
 *
 * Loopia shared hosting is the deployment target, so the runtime must not require a Composer
 * vendor directory to be present or a Composer version to be available on the host. When
 * `vendor/autoload.php` exists it is used (development convenience); otherwise this mapping is
 * the loader. The PSR-4 prefix and directory match `composer.json` exactly.
 */

$vendor = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendor)) {
    require_once $vendor;
    return;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Allfather\\Loopia\\';
    $length = strlen($prefix);
    if (strncmp($prefix, $class, $length) !== 0) {
        return;
    }
    $relative = substr($class, $length);
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
