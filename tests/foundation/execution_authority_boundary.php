<?php

declare(strict_types=1);

/**
 * Where execution authority can come from, and where it cannot.
 *
 * An execution grant or prompt approval is admitted only by citing a reviewed owner decision under
 * `docs/decisions/`. That is only a control while three things stay true, and each is asserted here:
 * no code path writes that directory; no web surface can reach the classified authority vocabulary
 * (admission, prompt approval and withdrawal, the execution-grant lifecycle, owner decisions, role
 * assignment, entitlement, credential creation/rotation) beyond the two P09 pages' exact allowance —
 * setup: plan and apply on MonitorSetup; schedules: plan, propose, suspend and revoke on
 * ScheduleService; and the execution paths ask the one trusted resolver and the admission validator
 * rather than looking prompts up for themselves.
 */

use Allfather\Loopia\Auth\AccountService;
use Allfather\Loopia\Http\Controllers\{MonitorScheduleController,MonitorSetupController};
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\PromptOps\PromptApprovalStore;
use Allfather\Loopia\Provider\{CredentialRotation,CredentialStore,KeyRing,SecretBox};
use Allfather\Loopia\Substrate\M02\Seed as M02Seed;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\{ExecutionAdmission,ExecutionGrantStore,MonitorSetup,NativeOperations,OwnerDecisionRegistry,ScheduleService};

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
//
// THE CLASSIFIED VOCABULARY. Every public method through which one of these owners creates, changes,
// consumes or stands down authority — or touches credential plaintext — is CLASSIFIED; everything
// else the owner exposes is named under `other`. The partition is checked against reflection below,
// so a mutator added to any owner fails this file until someone decides, here and in review, whether
// the web may reach it. Method names are matched case-insensitively, as PHP resolves them.
$owners = [
    // P09 Monitor setup: profile import stays a private CLI act; plan and apply are the setup page's.
    MonitorSetup::class => ['family' => 'monitor setup', 'classified' => ['importProfile', 'plan', 'apply'], 'other' => ['receipt', 'validateRequest']],
    // P07 recurrence: admission and the bulk stand-down stay private CLI acts.
    ScheduleService::class => ['family' => 'schedule lifecycle', 'classified' => ['plan', 'propose', 'admit', 'suspend', 'revoke', 'standDown'], 'other' => ['runKey', 'overview', 'find', 'findById', 'status', 'current', 'delegation', 'admittedCandidates', 'digest']],
    // Execution-grant lifecycle, the per-step reservation that consumes a grant, and the stand-down.
    ExecutionGrantStore::class => ['family' => 'execution-grant lifecycle', 'classified' => ['plan', 'propose', 'admit', 'suspend', 'revoke'], 'other' => ['find', 'findById', 'status', 'admission', 'currentFor', 'admittedIn', 'consumed', 'digest']],
    ExecutionAdmission::class => ['family' => 'execution-grant lifecycle', 'classified' => ['reserve'], 'other' => ['grants', 'check']],
    NativeOperations::class => ['family' => 'execution-grant lifecycle', 'classified' => ['standDown'], 'other' => ['readiness']],
    // Prompt approval.
    PromptApprovalStore::class => ['family' => 'prompt approval', 'classified' => ['approve', 'withdraw'], 'other' => ['find', 'current']],
    // Owner decisions: the registry has NO writer. A decision is a reviewed file under
    // docs/decisions/, and the first block of this file proves no code path writes one.
    OwnerDecisionRegistry::class => ['family' => 'owner decision', 'classified' => [], 'other' => ['forRoot', 'resolve', 'authorizesGrant', 'authorizesSchedule', 'authorizesPrompt']],
    // M02 role vocabulary, role membership and the login lifecycle. `changePassword` is the
    // signed-in login changing its OWN password behind its current one (/account/password): it
    // grants no role, touches no membership and creates no login, so it is not classified.
    M02Seed::class => ['family' => 'role assignment', 'classified' => ['ensureVocabulary'], 'other' => ['roleIdFor']],
    AccountService::class => ['family' => 'role assignment', 'classified' => ['createLogin', 'addRoleMembership', 'resetPassword', 'suspend', 'reactivate', 'retire', 'revokeSessions'], 'other' => ['changePassword', 'findByEmailAddress', 'inspect', 'memberships', 'normalizeEmailAddress']],
    // Commercial entitlement: setup requires a subscription and never makes one.
    Entitlements::class => ['family' => 'tenant entitlement', 'classified' => ['defineTier', 'subscribe'], 'other' => ['tenantTierGrants', 'activeTier']],
    // Provider credentials and master keys: creation, rotation, revocation and plaintext use.
    CredentialStore::class => ['family' => 'credential creation/rotation', 'classified' => ['save', 'revoke', 'withCredential'], 'other' => ['describe']],
    CredentialRotation::class => ['family' => 'credential creation/rotation', 'classified' => ['plan', 'apply'], 'other' => ['status']],
    KeyRing::class => ['family' => 'credential creation/rotation', 'classified' => ['seal', 'open'], 'other' => ['single', 'fromEnv', 'hasConfiguration', 'activeVersion', 'versions', 'mode', 'hasVersion', 'fingerprint']],
    SecretBox::class => ['family' => 'credential creation/rotation', 'classified' => ['seal', 'open', 'generateMasterKey'], 'other' => ['fromMasterKey', 'keyVersion', 'fingerprint']],
];
foreach ($owners as $owner => $partition) {
    $public = array_values(array_filter(
        array_map(static fn (ReflectionMethod $m): string => $m->getName(), (new ReflectionClass($owner))->getMethods(ReflectionMethod::IS_PUBLIC)),
        static fn (string $name): bool => $name !== '__construct',
    ));
    sort($public);
    $named = [...$partition['classified'], ...$partition['other']];
    sort($named);
    Assert::same($public, $named, "{$owner}: every public method is either classified or named as non-authority ({$partition['family']})");
    Assert::same([], array_values(array_intersect($partition['classified'], $partition['other'])), "{$owner}: no method is both classified and not");
}
Assert::same([], $owners[OwnerDecisionRegistry::class]['classified'], 'the owner-decision registry exposes no writer at all: a decision is a reviewed commit, never a call');
$classified = [];
foreach ($owners as $partition) {
    foreach ($partition['classified'] as $name) {
        $classified[strtolower($name)] = $name;
    }
}

// THE PER-FILE ALLOWANCE, exactly. P09 adds two scriptless administration pages and this is their
// whole allowance, each reached only through the page's own owner service:
//   - the setup page may PLAN (read-only) and APPLY a reviewed MonitorSetup plan (apply re-plans,
//     must match the reviewed digest, grants no role and admits no execution);
//   - the schedule page may PLAN (read-only), PROPOSE (a PROPOSED version grants nothing) and STAND
//     DOWN (suspend/revoke, which only ever removes recurrence) a ScheduleService version.
// Every other web file — public_html and app/Http, templates included — may call NONE of the
// classified vocabulary. Admission, prompt approval and withdrawal, the execution-grant lifecycle,
// owner decisions, role assignment, entitlement and credential creation/rotation stay private.
$web = [...$phpFiles($root . '/public_html'), ...$phpFiles($root . '/app/Http')];
$webAllowance = [
    'app/Http/Controllers/MonitorScheduleController.php' => ['controller' => MonitorScheduleController::class, 'owner' => ScheduleService::class, 'calls' => ['plan', 'propose', 'suspend', 'revoke']],
    'app/Http/Controllers/MonitorSetupController.php' => ['controller' => MonitorSetupController::class, 'owner' => MonitorSetup::class, 'calls' => ['plan', 'apply']],
];
$allowedAnywhere = [];
foreach ($webAllowance as $relative => $allowance) {
    Assert::same([], array_values(array_diff($allowance['calls'], $owners[$allowance['owner']]['classified'])), "{$relative}: its allowance names only methods its own owner classifies");
    $allowedAnywhere = [...$allowedAnywhere, ...array_map('strtolower', $allowance['calls'])];
}
// Names no web file may call at all, whatever the receiver. The shared verbs (plan, apply, propose,
// suspend, revoke) are NOT here because two pages may call them on their own owner; for those the
// receiver and its declared type are what keeps ExecutionGrantStore::admit's siblings,
// CredentialRotation::apply and AccountService::suspend out of reach (checked below).
$forbiddenEverywhere = array_values(array_diff_key($classified, array_flip($allowedAnywhere)));
sort($forbiddenEverywhere);
foreach (['admit', 'approve', 'withdraw', 'standDown', 'reserve', 'ensureVocabulary', 'addRoleMembership', 'createLogin', 'subscribe', 'save', 'seal', 'withCredential', 'importProfile'] as $mustBeBanned) {
    Assert::true(in_array($mustBeBanned, $forbiddenEverywhere, true), "{$mustBeBanned} is banned on every web surface");
}
$vocabulary = '/(?:->|::)\s*(' . implode('|', array_map(static fn (string $n): string => preg_quote($n, '/'), array_values($classified))) . ')\s*\(/i';
$callers = [];
foreach ($web as $file) {
    $relative = str_replace($root . '/', '', $file);
    $source = $code($file);
    preg_match_all($vocabulary, $source, $calls);
    $found = array_values(array_unique(array_map(static fn (string $n): string => $classified[strtolower($n)], $calls[1])));
    sort($found);
    foreach ($found as $name) {
        $callers[$name][] = $relative;
    }
    $allowed = $webAllowance[$relative]['calls'] ?? [];
    $unexpected = array_values(array_diff($found, $allowed));
    Assert::same(
        [],
        $unexpected,
        $relative . ' cannot propose, admit, approve, withdraw, suspend or revoke execution authority beyond its P09 allowance',
    );
    $exact = $allowed;
    sort($exact);
    Assert::same($exact, $found, "{$relative} calls exactly its P09 allowance of the classified vocabulary (" . ($allowed === [] ? 'none' : implode(', ', $allowed)) . ')');
    Assert::same(0, preg_match('/->(admit|approve|withdraw)\s*\(/', $source), "{$relative} never admits, approves or withdraws anything");
    foreach ($owners as $partition) {
        $banned = array_values(array_intersect($partition['classified'], $forbiddenEverywhere));
        if ($banned !== []) {
            Assert::same(0, preg_match('/(?:->|::)\s*(' . implode('|', $banned) . ')\s*\(/i', $source), "{$relative} makes no {$partition['family']} call (" . implode(', ', $banned) . ')');
        }
    }
    // The scan above sees every call only while calls are spelled out: no variable method name,
    // no callable array, no call_user_func.
    Assert::same(0, preg_match('/(?:->|::)\s*[\$\{]|call_user_func|forward_static_call|\]\s*\(/', $source), "{$relative} makes no dynamic method call the vocabulary scan could miss");
    if ($relative !== 'public_html/index.php') {
        Assert::false(
            str_contains($source, 'ExecutionGrantStore') || str_contains($source, 'PromptApprovalStore'),
            "{$relative} does not reach the grant or prompt-approval stores (only the composition root builds them, for ScheduleService)",
        );
        Assert::false(
            (bool) preg_match('/\b(ExecutionAdmission|NativeOperations|KeyRing|Entitlements)\b/', $source),
            "{$relative} does not reach execution admission, native operations, the key ring or entitlements (only the composition root composes them)",
        );
    }
    Assert::false(
        (bool) preg_match('/\b(CredentialStore|CredentialRotation|SecretBox)\b|Substrate\\\\M02\\\\(\{[^}]*\b)?Seed\b/', $source),
        "{$relative} names no credential store, credential rotation, secret box or M02 role seed — not even the composition root",
    );
}
foreach ($webAllowance as $relative => $allowance) {
    Assert::true(is_file($root . '/' . $relative), "{$relative} exists as the allowance names it");
    $source = $code($root . '/' . $relative);
    // The allowance is for the owning service only: every allowed call is on `$this->service()`.
    preg_match_all('/(\S+)->(?:' . implode('|', $allowance['calls']) . ')\s*\(/i', $source, $receivers);
    Assert::same(['$this->service()'], array_values(array_unique($receivers[1])), "{$relative} calls its allowance only on its own owner service");
    // ...and `service()` is declared to return exactly that owner, so the receiver cannot be
    // CredentialRotation (apply), ExecutionGrantStore (plan/propose/suspend/revoke) or
    // AccountService (suspend) under the same method names.
    $service = new ReflectionMethod($allowance['controller'], 'service');
    $returns = $service->getReturnType();
    Assert::true(
        $returns instanceof ReflectionNamedType && !$returns->allowsNull() && $returns->getName() === $allowance['owner'],
        "{$relative}: service() is declared to return exactly " . $allowance['owner'],
    );
    Assert::true($service->isPrivate(), "{$relative}: service() is private to the page");
    $injected = (new ReflectionMethod($allowance['controller'], '__construct'))->getParameters()[0]->getType();
    $injectedTypes = $injected instanceof ReflectionUnionType ? array_map(static fn (ReflectionNamedType $t): string => $t->getName(), $injected->getTypes()) : [];
    sort($injectedTypes);
    $expectedTypes = [$allowance['owner'], 'Closure'];
    sort($expectedTypes);
    Assert::same($expectedTypes, $injectedTypes, "{$relative}: the page is constructed with its owner, or a closure that service() type-checks as that owner");
    // Whatever else the page asks its owner is one of the owner's declared non-authority reads.
    preg_match_all('/\$this->service\(\)->(\w+)\s*\(/', $source, $asked);
    $askedNames = array_values(array_unique($asked[1]));
    Assert::same([], array_values(array_diff($askedNames, [...$allowance['calls'], ...$owners[$allowance['owner']]['other']])), "{$relative} asks its owner only for its allowance and the owner's reads");
    Assert::same([], array_values(array_diff($allowance['calls'], $askedNames)), "{$relative} uses every method its allowance names, so the allowance stays exact");
}
// `apply` in particular: on the web it is invoked by the setup page alone, on its MonitorSetup owner.
Assert::same(['app/Http/Controllers/MonitorSetupController.php'], $callers['apply'] ?? [], 'apply is invoked by exactly one web file, the setup page');
preg_match_all('/(\S+)->apply\s*\(/i', $code($root . '/app/Http/Controllers/MonitorSetupController.php'), $applyReceivers);
Assert::same(['$this->service()'], array_values(array_unique($applyReceivers[1])), 'apply is invoked only on the setup page\'s own service()');
Assert::same(MonitorSetup::class, (string) (new ReflectionMethod(MonitorSetupController::class, 'service'))->getReturnType(), 'and that service() is declared to return MonitorSetup, never CredentialRotation');
Assert::same(['app/Http/Controllers/MonitorScheduleController.php', 'app/Http/Controllers/MonitorSetupController.php'], $callers['plan'] ?? [], 'plan is invoked by exactly the two administration pages');
foreach (['propose', 'suspend', 'revoke'] as $verb) {
    Assert::same(['app/Http/Controllers/MonitorScheduleController.php'], $callers[$verb] ?? [], "{$verb} is invoked by the schedule page alone");
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
