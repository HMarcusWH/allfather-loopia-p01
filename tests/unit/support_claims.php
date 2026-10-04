<?php

declare(strict_types=1);

use Allfather\Loopia\Http\View;
use Allfather\Loopia\Tests\Assert;

$view = new View(dirname(__DIR__, 2) . '/app/Http/templates');
$hostile = '<script>not-trusted</script>';
$html = $view->partial('support', [
    'requestId' => 'synthetic-request',
    'workspace' => ['display_name' => $hostile, 'environment_class' => 'TEST'],
    'deploymentEnvironment' => 'staging',
    'supportContact' => $hostile,
    'releaseRef' => str_repeat('a', 40),
    'betaPermissions' => ['competition_monitoring.read'],
    'session' => null,
]);
$text = preg_replace('/\s+/', ' ', strip_tags($html));
foreach ([
    'Released-intelligence views are read-only.',
    'Authorized operators can configure Monitor setup, propose or stand down schedules, and record human reviews',
    'Those actions do not admit execution grants or schedules.',
    'Viewing a page does not capture a source or call a model.',
    'requires separately admitted authority',
    'requires a separately admitted schedule and bounded execution grant.',
    'No send, publish or CRM action exists.',
    'Outputs are internal only.',
] as $claim) {
    Assert::contains($claim, $text, 'support distinguishes governed administration from execution authority');
}
foreach ([
    'Nothing in this console creates, edits, approves or dispatches.',
    'Sources are not being captured live.',
    'Recurring work is not running.',
] as $stale) {
    Assert::false(str_contains($text, $stale), 'support does not fabricate current execution posture or deny implemented controls');
}
Assert::false(str_contains($html, $hostile), 'support still escapes workspace/contact material');
Assert::contains('&lt;script&gt;not-trusted&lt;/script&gt;', $html, 'escaped hostile material remains readable as text');
Assert::false(str_contains($html, '<form'), 'support page itself introduces no mutation control');
