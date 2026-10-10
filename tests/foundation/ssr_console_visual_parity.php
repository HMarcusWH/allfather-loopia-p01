<?php

declare(strict_types=1);

/**
 * PHP pages remain scriptless, but their authenticated visual chrome
 * follows the React Operator Console instead of a separate legacy shell.
 * This checks the source contract; rendered viewport parity needs browser QA.
 */

use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$layout = (string) file_get_contents($root . '/app/Http/templates/layout.php');
$css = (string) file_get_contents($root . '/public_html/assets/console.css');
$reactLogo = (string) file_get_contents($root . '/frontend/console/src/assets/logo-lockup.svg');
$ssrLogo = (string) file_get_contents($root . '/public_html/assets/allfather-logo.svg');

Assert::same($reactLogo, $ssrLogo, 'the scriptless shell uses the exact branded React logo asset');
Assert::contains('src="/assets/allfather-logo.svg"', $layout, 'public branded logo has a stable path');
Assert::contains('class="rail__primary"', $layout, 'authenticated PHP pages have the unified primary rail');
Assert::contains('class="rail__utilities"', $layout, 'workspace/account/support utilities stay reachable');
Assert::contains('class="shell__topbar"', $layout, 'PHP pages have the same structural topbar as React');
Assert::contains('href="/console/app?view=p01"', $layout, 'PHP navigation returns to React P01');
Assert::contains('href="/console/app?view=p02"', $layout, 'PHP navigation returns to React P02');
Assert::contains('method="post" action="/logout"', $layout, 'sign-out stays a POST');
Assert::contains('name="csrf_token"', $layout, 'sign-out retains the session-bound CSRF input');
Assert::contains('Hiding an item is not a permission', $layout, 'rail still disclaims navigation authority');
Assert::contains("View::e(\$session['display_name'])", $layout, 'identity remains escaped');
Assert::false(str_contains($layout, '<script'), 'server-rendered page does not introduce script');
Assert::contains('.shell__topbar', $css, 'SSR CSS includes shared topbar structure');
Assert::contains('.shell .rail__link[aria-current="page"]', $css, 'SSR CSS includes React-style active link');
Assert::contains('@media (max-width: 760px)', $css, 'SSR rail has explicit mobile behavior');
Assert::false(str_contains($ssrLogo, '<script'), 'copied logo contains no executable script');
