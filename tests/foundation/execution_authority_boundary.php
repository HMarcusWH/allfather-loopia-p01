<?php

declare(strict_types=1);

/**
 * Where execution authority can come from, and where it cannot.
 *
 * An execution grant or prompt approval is admitted only by citing a reviewed owner decision under
 * `docs/decisions/`. That is only a control while three things stay true, and each is asserted here:
 * no code path writes that directory; no web surface can propose, admit, approve, withdraw, suspend
 * or revoke; and the execution paths ask the one trusted resolver and the admission validator
 * rather than looking prompts up for themselves.
 */

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\OwnerDecisionRegistry;

$root = dirname(__DIR__, 2);

/** @return list<string> */
$phpFiles = static function (string $dir): array {
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $out[] = $file->getPathname();
        }
    }
    sort($out);

    return $out;
};
$code = static fn (string $path): string => implode('', array_map(
    static fn (array|string $t): string => is_string($t) ? $t : (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]),
    token_get_all((string) file_get_contents($path)),
));

// ---------------------------------------------------------------- nothing writes a decision
$writers = [];
foreach ([...$phpFiles($root . '/app'), ...$phpFiles($root . '/public_html'), ...$phpFiles($root . '/worker'), ...$phpFiles($root . '/ops')] as $file) {
    $source = str_replace(['fwrite(STDERR,', 'fwrite(STDOUT,', 'fwrite(\STDERR,', 'fwrite(\STDOUT,'], '', $code($file));
    if ((str_contains($source, 'docs/decisions') || str_contains($source, "'/docs/decisions'")) && preg_match('/file_put_contents|fopen|fwrite|rename\s*\(|copy\s*\(|mkdir\s*\(/', $source) === 1) {
        $writers[] = str_replace($root . '/', '', $file);
    }
}
Assert::same([], $writers, 'no code path writes docs/decisions — an owner decision is a reviewed commit');

// Every decision shipped with a release is well-formed; malformed authority fails the build.
$registry = OwnerDecisionRegistry::forRoot($root);
foreach (glob($root . '/docs/decisions/*.json') ?: [] as $file) {
    $name = basename($file);
    Assert::true(
        $registry->resolve('docs/decisions/' . $name, hash_file('sha256', $file))->isOk(),
        "docs/decisions/{$name} is a well-formed owner decision",
    );
}
Assert::true(is_file($root . '/docs/decisions/README.md'), 'the decisions directory documents its contract');

// ---------------------------------------------------------------- no web surface administers authority
$web = [...$phpFiles($root . '/public_html'), ...$phpFiles($root . '/app/Http')];
foreach ($web as $file) {
    Assert::same(
        0,
        preg_match('/->(propose|admit|approve|withdraw|suspend|revoke|plan)\s*\(/', $code($file)),
        str_replace($root . '/', '', $file) . ' cannot propose, admit, approve, withdraw, suspend or revoke execution authority',
    );
}

// ---------------------------------------------------------------- execution asks the trusted resolver
foreach (['app/Workflow/MonitorService.php', 'app/Provider/PreparedModelRequest.php', 'app/Provider/ModelExecutor.php'] as $path) {
    Assert::false(
        str_contains($code($root . '/' . $path), 'ProductPromptPack::resolve('),
        "{$path} selects prompts through PromptSelectionResolver, never by looking content up itself",
    );
}
$executor = $code($root . '/app/Provider/ModelExecutor.php');
$gate = strpos($executor, "'MODEL_EXECUTION_NOT_ADMITTED'");
$dispatch = strpos($executor, 'requestModelDispatch(');
Assert::true(
    is_int($gate) && is_int($dispatch) && $gate < $dispatch,
    'a live transport without admission is refused before M06 is asked to dispatch',
);
foreach (["'MODEL_BEFORE_RESERVE'", "'MODEL_BEFORE_DISPATCH'", "'MODEL_AFTER_RETURN'"] as $checkpoint) {
    Assert::contains($checkpoint, $executor, "the executor checks admission at {$checkpoint}");
}
$service = $code($root . '/app/Workflow/MonitorService.php');
foreach (["'SOURCE_CAPTURE'", "'RELEASE_P01'", "'RELEASE_ROUTING'"] as $checkpoint) {
    Assert::contains($checkpoint, $service, "the Monitor checks admission at {$checkpoint}");
}

// ---------------------------------------------------------------- the transport's own refusals stay
$transport = $code($root . '/app/Provider/OpenAIResponsesTransport.php');
Assert::true(
    strpos($transport, "'MODEL_TRANSPORT_INACTIVE'") < strpos($transport, 'curl_init('),
    'an unactivated transport refuses before any cURL handle exists',
);
Assert::true(
    strpos($transport, "'MODEL_PROMPT_INACTIVE'") < strpos($transport, 'curl_init('),
    'an inactive prompt is refused by the transport itself, whatever its caller did',
);

$factory = $code($root . '/app/Workflow/MonitorFactory.php');
Assert::contains(
    'EvaluationReport::approvalEvidence',
    $factory,
    'the deployed Monitor revalidates the accepted evaluation behind every manual prompt approval',
);
