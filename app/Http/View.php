<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http;

/**
 * The template renderer.
 *
 * ESCAPING IS THE DEFAULT AND THERE IS NO OPT-OUT. `View::e()` is the only way a value reaches the
 * page, and no template receives a "raw" helper — because the moment one exists, somebody uses it
 * on a field that turns out to be operator-supplied. The console shows evidence summaries,
 * competitor names and reason codes, all of which originate outside this system.
 */
final class View
{
    public function __construct(private readonly string $templateDir)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $body = $this->capture($template, $data);

        return $this->capture('layout', [...$data, 'content' => $body]);
    }

    /** Render without the layout — for fragments. */
    public function partial(string $template, array $data = []): string
    {
        return $this->capture($template, $data);
    }

    /** HTML-escape. The only route from a value to the page. */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * The semantic class for a governed vocabulary term.
     *
     * COLOUR CARRIES MEANING HERE, it is not decoration. Gold is AUTHORITY — who may do what.
     * Cyan is EVIDENCE — what something rests on. Violet is GOVERNANCE — an act of the control
     * plane. A refusal is red and an uncertainty amber, because both are states an operator must
     * not mistake for the others.
     *
     * Every one is paired with a text label in the markup, so the meaning survives for a reader who
     * cannot distinguish the colours.
     */
    public static function tone(string $term): string
    {
        return match (true) {
            in_array($term, ['ALLOW_INFORMATIONAL_RELEASE', 'RELEASED', 'RELEASED_INTERNAL', 'ACTIVE_AS_RECORDED', 'PASS', 'CONTROL_PASS', 'VISIBLE', 'GRANTED'], true) => 'is-ok',
            in_array($term, ['BLOCK', 'BLOCKED', 'QUARANTINE', 'DENIED', 'REFUSED', 'FAIL', 'REJECTED'], true) => 'is-refused',
            in_array($term, ['HOLD', 'REQUIRE_MORE_EVIDENCE', 'REQUIRE_REVISION', 'PENDING_VERDICT', 'APPROVAL_REQUIRED', 'REQUIRE_HUMAN_APPROVAL', 'INCOMPLETE'], true) => 'is-held',
            in_array($term, ['HYPOTHESIS', 'INFERRED_HYPOTHESIS', 'UNKNOWN', 'STALE', 'SUPERSEDED', 'INVALIDATED_FOR_CURRENT_RELIANCE'], true) => 'is-uncertain',
            default => 'is-neutral',
        };
    }

    /** @param array<string, mixed> $data */
    private function capture(string $template, array $data): string
    {
        $file = $this->templateDir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("template not found: {$template}");
        }

        $render = static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                require $__file;

                return (string) ob_get_clean();
            } catch (\Throwable $error) {
                ob_end_clean();

                throw $error;
            }
        };

        return $render($file, $data);
    }
}
