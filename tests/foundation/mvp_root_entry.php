<?php

declare(strict_types=1);

/** The Railway application root must not strand visitors on a 404 or widen public auth. */

use Allfather\Loopia\Http\Response;
use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$index = (string) file_get_contents($root . '/public_html/index.php');
$kernel = (string) file_get_contents($root . '/app/Http/Kernel.php');

Assert::contains(
    "\$kernel->route('GET', '/', static fn (Request \$request, ?array \$session): Response => Response::redirect('/console/app'));",
    $index,
    'the service root redirects through the existing protected React console route'
);
Assert::false(
    str_contains($index, "\$kernel->route('POST', '/'"),
    'the root adds no POST or state-changing route'
);
Assert::contains(
    "private array \$publicPaths = ['/login', '/healthz'];",
    $kernel,
    'the root is not made public: anonymous visitors still redirect to login'
);
Assert::contains(
    'self::PASSWORD_CHANGE_PATHS',
    $kernel,
    'the root still passes through the forced-password-change gate'
);
$response = Response::redirect('/console/app');
Assert::same(303, $response->status, 'root destination uses the existing 303 redirect response');
Assert::same('/console/app', $response->headers['Location'], 'redirect targets the existing authenticated UI');
