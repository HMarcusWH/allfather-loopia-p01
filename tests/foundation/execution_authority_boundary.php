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
// P09 adds exactly two scriptless administration pages, and this is their whole allowance:
//   - the schedule page may PLAN (read-only), PROPOSE (a PROPOSED version grants nothing) and STAND
//     DOWN (suspend/revoke, which only ever removes recurrence) a ScheduleService version;
//   - the setup page may PLAN a MonitorSetup (its apply grants no role and no execution).
// Nothing on the web may ADMIT anything, APPROVE or WITHDRAW a prompt, or touch an execution grant's
// lifecycle: admission is a reviewed owner decision used from the private CLI.
$web = [...$phpFiles($root . '/public_html'), ...$phpFiles($root . '/app/Http')];
$webAllowance = [
    'app/Http/Controllers/MonitorScheduleController.php' => ['plan', 'propose', 'suspend', 'revoke'],
    'app/Http/Controllers/MonitorSetupController.php' => ['plan'],
];
foreach ($web as $file) {
    $relative = str_replace($root . '/', '', $file);
    preg_match_all('/->(propose|admit|approve|withdraw|suspend|revoke|plan)\s*\(/', $code($file), $calls);
    $unexpected = array_values(array_unique(array_diff($calls[1], $webAllowance[$relative] ?? [])));
    Assert::same(
        [],
        $unexpected,
        $relative . ' cannot propose, admit, approve, withdraw, suspend or revoke execution authority beyond its P09 allowance',
    );
    Assert::same(0, preg_match('/->(admit|approve|withdraw)\s*\(/', $code($file)), "{$relative} never admits, approves or withdraws anything");
    if ($relative !== 'public_html/index.php') {
        Assert::false(
            str_contains($code($file), 'ExecutionGrantStore') || str_contains($code($file), 'PromptApprovalStore'),
            "{$relative} does not reach the grant or prompt-approval stores (only the composition root builds them, for ScheduleService)",
        );
    }
}
foreach ($webAllowance as $relative => $allowed) {
    Assert::true(is_file($root . '/' . $relative), "{$relative} exists as the allowance names it");
    // The allowance is for the owning service only: every allowed call is on `$this->service()`.
    preg_match_all('/(\S+)->(?:' . implode('|', $allowed) . ')\s*\(/', $code($root . '/' . $relative), $receivers);
    Assert::same(['$this->service()'], array_values(array_unique($receivers[1])), "{$relative} calls its allowance only on its own owner service");
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
foreach (["'SOURCE_CAPTURE'", "'SOURCE_BEFORE_DISPATCH'", "'RELEASE_P01'", "'RELEASE_ROUTING'"] as $checkpoint) {
    Assert::contains($checkpoint, $service, "the Monitor checks admission at {$checkpoint}");
}
$capture = $code($root . '/app/Product/P01/Capture.php');
$preFetch = strpos($capture, '$beforeFetch()');
$egress = strpos($capture, 'EgressGuard::guardedFetch(');
Assert::true(
    is_int($preFetch) && is_int($egress) && $preFetch < $egress,
    'source execution re-checks deployment admission inside the M01 callback before DNS/HTTP begins',
);

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

$authorizationDoc = (string) file_get_contents($root . '/docs/MONITOR_EXECUTION_AUTHORIZATION.md');
Assert::contains(
    'MonitorFactory::forWorkspace()',
    $authorizationDoc,
    'execution-authorization currentness names the deployed conditional composition',
);
Assert::false(
    str_contains($authorizationDoc, 'deployed factory is still `MonitorFactory::inactive()`'),
    'execution-authorization currentness does not repeat the pre-P03 deployment description',
);
