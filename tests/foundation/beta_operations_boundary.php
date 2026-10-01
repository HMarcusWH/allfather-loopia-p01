<?php

declare(strict_types=1);

/**
 * Structural tripwires for PR7's beta-operations seam.
 *
 * These need no database. They are about SHAPE — that the beta bundle carries the permissions it
 * says and no others, that the operator commands cannot be handed a password on the command line,
 * that the support surface holds nothing it could leak, and that the lifecycle gate is enforced in
 * the Kernel rather than in a screen. Every one of them is a property a future edit could remove
 * without any behavioural test noticing, because the behaviour would still work — for the caller
 * who is not attacking it.
 */

use Allfather\Loopia\Beta\BetaBundle;
use Allfather\Loopia\Http\RequestId;
use Allfather\Loopia\Http\RequestLog;
use Allfather\Loopia\Http\Response;
use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);

/**
 * One PHP file's EXECUTABLE source, with comments removed.
 *
 * Several assertions below are "this class name must not appear here", and the clearest way to
 * document such a rule is to state it in the file it governs — which would then trip the assertion
 * that enforces it. Stripping comments is what lets the prose say the true thing while the check
 * still reads only the code. `tests/foundation/no_external_action_surface.php` does the same, for
 * the same reason.
 */
$code = static function (string $path): string {
    return implode('', array_map(
        static function (array|string $token): string {
            if (is_string($token)) {
                return $token;
            }

            return in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1];
        },
        token_get_all((string) file_get_contents($path)),
    ));
};

// ============================================================ the beta bundle is exactly six reads

Assert::equalsList(
    [
        'battlecard.read',
        'entity_twin.read',
        'marketing_asset.read',
        'priority_outreach_queue.read',
        'prospect.read',
        'role_briefing.read',
    ],
    BetaBundle::PERMISSIONS,
    'the beta tier carries exactly the six wired owner-safe read permissions',
);

// SORTED, because `ensureTier` compares the stored set to this one by equality rather than by
// membership. An unsorted constant would make a correctly-defined tier look like a mismatch.
$sorted = BetaBundle::PERMISSIONS;
sort($sorted, SORT_STRING);
Assert::equalsList($sorted, BetaBundle::PERMISSIONS, 'and is kept sorted, because the tier check is an equality');

// Every one of them is a READ. The entitlement plane narrows; a write permission sold commercially
// would be an authority arriving through the billing door.
foreach (BetaBundle::PERMISSIONS as $permission) {
    Assert::true(
        str_ends_with($permission, '.read'),
        "the beta tier grants only reads: {$permission}",
    );
}

// The specific things it must NOT carry, named individually rather than inferred from the suffix
// rule above — a future `outreach.read` would pass that rule and still be wrong.
foreach ([
    'outreach.draft', 'outreach.approve', 'outreach.execute',
    'external_message.approve_or_send', 'crm.send', 'entity_twin.live_crm_write',
    'entity_twin.public_publish', 'entity_twin.external_dispatch',
    'customer_catalogue.upload', 'priority_outreach_queue.review', 'company_signal.review',
    'entity_twin.propose_state_change', 'entity_twin.release_snapshot',
    'distribution.route', 'distribution.configure_targets',
    'tenant.administer', 'workspace.administer', 'role.assign', 'identity.register',
] as $forbidden) {
    Assert::false(
        in_array($forbidden, BetaBundle::PERMISSIONS, true),
        "the beta tier grants no send/publish/export/administer permission: {$forbidden}",
    );
}

// `decision.read` is an M02 role concern: `DecisionLedgerAdapter` gates on the access guard ALONE
// and never consults the entitlement plane, so selling it would grant nothing and misstate what
// the tenant bought. The assertion pins both halves — the absence, and the reason for it.
Assert::false(in_array('decision.read', BetaBundle::PERMISSIONS, true), 'decision.read is not a commercial entitlement');
$ledgerAdapter = $code($root . '/app/Http/ReadAdapters/DecisionLedgerAdapter.php');
Assert::false(
    str_contains($ledgerAdapter, 'Entitlements') || str_contains($ledgerAdapter, 'tenantTierGrants'),
    'and the M10 read still gates on M02 alone, which is why it is not one',
);

// Zero charge, and a TRIAL. `no_external_charge_executed` is CHECK-pinned true; a beta tier with a
// price would be a statement that somebody is being billed.
Assert::same(0, BetaBundle::MONTHLY_FEE_MINOR_UNITS, 'the beta tier is zero charge');
Assert::same('TRIAL', BetaBundle::SUBSCRIPTION_STATUS, 'and a closed beta is a trial in the schema\'s own vocabulary');
Assert::same('LOOPIA_SME_BETA', BetaBundle::TIER_KEY, 'the beta tier key is the one the roadmap names');

// NO BETA ROLE ANYWHERE. Roles are the authority plane; a role minted for the beta would put
// authority in the commercial plane by the back door.
$sources = [];
$walk = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS)
);
foreach ($walk as $file) {
    if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
        $sources[] = $file->getPathname();
    }
}
foreach ([...$sources, ...(glob($root . '/ops/*.php') ?: [])] as $file) {
    Assert::false(
        str_contains($code($file), 'ROLE_BETA_PARTNER'),
        'no ROLE_BETA_PARTNER is defined in ' . str_replace($root . '/', '', $file),
    );
}
foreach (glob($root . '/database/migrations/*.sql') ?: [] as $file) {
    Assert::false(
        str_contains((string) file_get_contents($file), 'ROLE_BETA_PARTNER'),
        'nor seeded by ' . str_replace($root . '/', '', $file),
    );
}

// The bundle REUSES the entitlement mechanism rather than competing with it. A second component
// writing `subscription_tiers` directly would be a second answer to "what has this tenant paid
// for?", which is the exact thing `Entitlements` refuses to allow.
$bundle = $code($root . '/app/Beta/BetaBundle.php');
Assert::contains('Entitlements', $bundle, 'the bundle drives the existing entitlement component');
Assert::false(
    str_contains($bundle, "insert('subscription_tiers'") || str_contains($bundle, "insert('subscription_tier_permissions'"),
    'and never writes the tier tables itself',
);
Assert::false(
    str_contains($bundle, "insert('permissions'"),
    'and never mints a permission — an undefined one is refused by defineTier, not created',
);
Assert::contains(
    'BETA_TIER_DEFINITION_MISMATCH',
    $bundle,
    'an existing tier under this key with a different definition is refused, not repaired',
);

// ============================================================ the operator commands hold no secret

foreach (['password', 'new-password', 'pass', 'pwd', 'secret'] as $refused) {
    Assert::true(
        in_array($refused, BetaCli::REFUSED_SECRET_FLAGS, true),
        "--{$refused} is refused by name rather than merely unknown",
    );
}

foreach (['/ops/onboard-beta-user.php', '/ops/manage-beta-user.php'] as $command) {
    $source = $code($root . $command);
    $rawSource = (string) file_get_contents($root . $command);
    $name = basename($command);

    // The value never arrives through argv. `parseArgs` refuses the flag names above, and nothing
    // in either command reads a password out of the parsed arguments.
    foreach (["\$args['password']", "\$args['new-password']", "\$args['secret']"] as $argvRead) {
        Assert::false(
            str_contains($source, $argvRead),
            "{$name} never reads a password from parsed arguments: {$argvRead}",
        );
    }

    // Nor does it leave by the front door. Everything these commands print goes through
    // `BetaCli::emit`, and no password-bearing variable is ever put in an emitted payload.
    Assert::false(
        str_contains($source, "'password' =>") || str_contains($source, '"password" =>'),
        "{$name} puts no password key in its JSON result",
    );
    Assert::false(
        str_contains($source, 'password_hash') || str_contains($source, 'password_algo'),
        "{$name} never names a stored credential column",
    );
    Assert::false(
        str_contains($source, 'session_token') || str_contains($source, 'csrf_token')
        || str_contains($source, 'token_hash') || str_contains($source, 'APP_MASTER_KEY'),
        "{$name} names no session token, CSRF token or master key",
    );
    Assert::contains('BetaCli::readPassword', $source, "{$name} reads any password through the one shared prompt");
    Assert::contains('--password-stdin', $rawSource, "{$name} documents the non-interactive route");

    // KNOWN ARGUMENTS ONLY, the same rule provision-staging.php already follows. A silently ignored
    // flag is a support action an operator believes they performed.
    Assert::contains('BetaCli::parseArgs', $source, "{$name} refuses unknown arguments");
}

// ONBOARDING IS ONE UNIT. Subscription, operator, login and memberships commit together or not at
// all. Half-done onboarding left a beta subscription, an orphan operator and no login — and with
// --replace-live-subscription it had already cancelled the tenant's previous subscription to get
// there — a state an operator then has to recognise and unpick by hand.
$onboard = $code($root . '/ops/onboard-beta-user.php');
Assert::contains('$db->transaction(', $onboard, 'onboarding performs its writes inside one transaction');
foreach (["insert('operators'", 'createLogin(', 'addRoleMembership(', 'subscribeTenant('] as $write) {
    Assert::true(
        strpos($onboard, '$db->transaction(') < strpos($onboard, $write),
        "and {$write} happens inside it, never before it",
    );
}

// A DRY RUN MUST MEAN IT. `ensureTier` CREATES the tier when it is absent, so a preflight that
// called it would add a row to the platform's global commercial catalogue and then report that
// nothing was written.
Assert::contains('$beta->inspectTier()', $onboard, 'the preflight uses the read-only tier inspection');
Assert::false(str_contains($onboard, '$beta->ensureTier()'), 'and never the creating one');
Assert::true(
    strpos($onboard, "isset(\$args['dry-run'])") < strpos($onboard, '$db->transaction('),
    'the dry-run exit comes before any write at all',
);

// THE PREFLIGHT MIRRORS EVERY REFUSAL THE REAL RUN CAN KNOW IN ADVANCE, which for a preflight is
// the only useful definition of one. A dry run that answered DRY_RUN_OK and was then refused on the
// first statement would be worse than no dry run, because the operator would have believed it.
foreach ([
    'that address already has a login outside this tenant',
    'is not an address',
    '--display-name cannot be blank',
    'Pass --replace-live-subscription',
    'is restored deliberately, not by re-running onboarding',
    'does not accumulate new role memberships',
] as $preflight) {
    Assert::true(
        strpos($onboard, $preflight) < strpos($onboard, 'BetaCli::readPassword'),
        "identity is validated before the password prompt: {$preflight}",
    );
}
$inspectTier = $code($root . '/app/Beta/BetaBundle.php');
Assert::true(
    strpos($inspectTier, 'public function inspectTier') > 0,
    'the bundle exposes a read-only inspection distinct from creation',
);

// The shared helper never puts a password anywhere but the hasher's hands.
$cli = $code($root . '/app/Ops/BetaCli.php');
$rawCli = (string) file_get_contents($root . '/app/Ops/BetaCli.php');
Assert::false(str_contains($cli, 'error_log('), 'the CLI helper logs nothing');

// STDIN ONLY, AND NO FALLBACK. PHP has no portable way to suppress terminal echo: the
// readline-callback trick suppresses it on some builds and displays the password on others, and
// shelling out to `stty` is process execution the no-external-action tripwire forbids. An unproven
// host capability is a missing one, so there is no interactive path to fall back FROM — and none to
// fall back TO, because a prompt that might display the password is worse than none.
foreach (['readline_callback_handler_install', 'stream_get_contents(STDIN', 'stream_isatty'] as $absent) {
    Assert::false(
        str_contains($cli, $absent),
        "no unproven no-echo primitive remains in the CLI helper: {$absent}",
    );
}
Assert::false(
    stripos($cli, 'WARNING') !== false,
    'and no branch warns that it is about to echo a password — there is no such branch',
);
Assert::contains(
    'if (!$fromStdin) {',
    $cli,
    'a password that was not piped in is refused rather than prompted for',
);
Assert::contains('--password-stdin', $rawCli, 'and the refusal names the flag that works');

// ============================================================ the request reference is opaque

$first = RequestId::issue();
$second = RequestId::issue();
Assert::notSame($first, $second, 'two requests get two references');
Assert::true(RequestId::isWellFormed($first), 'a reference has the declared shape');
Assert::same(22, strlen($first), 'and a fixed length, so it carries no variable-length payload');
Assert::same(1, preg_match('/^[A-Za-z0-9_-]+$/', $first), 'it is URL-safe and opaque');

// NOT DERIVED FROM ANYTHING. The generator is handed nothing, so there is nothing it could encode —
// the assertion is on the SIGNATURE, because a reference that took an argument would be one
// refactor from taking a tenant id.
$issue = new ReflectionMethod(RequestId::class, 'issue');
Assert::same(0, $issue->getNumberOfParameters(), 'the reference generator receives nothing it could encode');

// And it is never read from the caller. An inbound X-Request-ID would let somebody choose the
// string their own requests are recorded under.
$request = $code($root . '/app/Http/Request.php');
Assert::contains('RequestId::issue()', $request, 'the reference is issued server-side');
Assert::false(str_contains($request, 'HTTP_X_REQUEST_ID'), 'and a caller-supplied one is never read');

// EVERY response carries it, because the Kernel stamps the dispatch result rather than trusting
// each surface to remember.
$kernel = (string) file_get_contents($root . '/app/Http/Kernel.php');
Assert::contains(
    '$this->dispatch($request)->withHeader(RequestId::HEADER, $request->requestId)',
    $kernel,
    'the Kernel stamps every response, including redirects and safe error pages',
);
Assert::same('X-Request-ID', RequestId::HEADER, 'on the conventional header name');

// AND RECORDS IT. A reference a partner can quote and an operator cannot look up is worse than no
// reference: it invites the question and leaves the person who receives it with nothing to do.
Assert::contains(
    'RequestLog::record($request->requestId, $request->method, $request->path, $response->status)',
    $kernel,
    'and records each request against its reference, once, at the same boundary',
);

$requestLog = $code($root . '/app/Http/RequestLog.php');
// THE RECORD IS A CLOSED SET OF FOUR FIELDS. A diagnostic log with no access control on it is the
// wrong place for anything that identifies a person or a tenant, and the correlation an operator
// needs is done from the session and audit records that already have their own access rules.
foreach ([
    'tenant_id', 'workspace_id', 'operator_id', 'platform_user_id', 'session',
    'email', 'password', 'csrf', 'user_agent', 'cookie', 'query',
] as $mustNotRecord) {
    Assert::false(
        str_contains($requestLog, $mustNotRecord),
        "the request log records no {$mustNotRecord}",
    );
}
// The path is caller-influenced, and a log line is a format somebody's tooling parses. A newline in
// it would let a caller forge a second record.
Assert::contains('preg_replace', $requestLog, 'and sanitizes the fields it does record');
Assert::contains('\x20-\x7E', $requestLog, 'to printable characters only, so a log line cannot be forged');
Assert::contains('@error_log', $requestLog, 'a failure to log never fails the request it was logging');
Assert::contains(
    "if (PHP_SAPI === 'cli')",
    $requestLog,
    'and a CLI invocation records nothing — there is no HTTP request to correlate',
);

// The formatting, which is where the property actually lives: a log line is a format somebody's
// tooling parses, and the path reaching it is caller-influenced.
$line = RequestLog::format('AbCdEf0123456789_-xyzQ', 'GET', '/console/api/v1/context', 200);
Assert::same(
    'af-request id=AbCdEf0123456789_-xyzQ method=GET path=/console/api/v1/context status=200',
    $line,
    'an ordinary request records exactly four fields',
);

$forged = RequestLog::format('x', 'GET', "/a\naf-request id=forged method=GET path=/b status=200", 200);
Assert::false(str_contains($forged, "\n"), 'a newline in the path cannot forge a second record');
Assert::contains('path=/a?af-request', $forged, 'it is replaced rather than stripped, so tampering still looks like tampering');

$long = RequestLog::format('x', 'GET', '/' . str_repeat('a', 500), 200);
Assert::true(strlen($long) < 300, 'an unbounded path is truncated rather than wrapped');
Assert::contains('...', $long, 'and says it was truncated');

Assert::same(
    'af-request id=- method=- path=- status=500',
    RequestLog::format('', '', '', 500),
    'an empty field is recorded as absent rather than as an empty value the format cannot express',
);

// `withHeader` returns a NEW response. A mutable one would be a response whose CSP a later layer
// could quietly relax.
$base = Response::html('<p>x</p>');
$stamped = $base->withHeader(RequestId::HEADER, 'abc');
Assert::false(isset($base->headers[RequestId::HEADER]), 'withHeader does not mutate the original response');
Assert::same('abc', $stamped->headers[RequestId::HEADER], 'and the copy carries the header');
Assert::same(
    $base->headers['Content-Security-Policy'],
    $stamped->headers['Content-Security-Policy'],
    'while every security header survives the copy',
);
$withCookie = Response::html('<p>x</p>')->withCookie('a=b')->withHeader('X-Test', '1');
Assert::equalsList(['a=b'], $withCookie->cookies, 'and cookies survive it too — a login rotates two');

// ============================================================ the lifecycle gate is server-side

Assert::contains(
    "\$session['must_change_password'] ?? false) === true",
    $kernel,
    'the forced-password-change gate is enforced in the Kernel, between routing and dispatch',
);
Assert::contains(
    "'/account/password', '/logout'",
    $kernel,
    'and exactly two paths are reachable under it',
);
Assert::contains(
    "'status' => 'PASSWORD_CHANGE_REQUIRED'",
    $kernel,
    'a JSON read under the gate fails closed with a distinct lifecycle reason',
);
// NO PRODUCT DATA under the gate. The refusal envelope has no `data` key at all — an empty one
// would be one refactor from a populated one.
$gateSection = substr($kernel, (int) strpos($kernel, 'PASSWORD_CHANGE_REQUIRED'), 600);
Assert::false(str_contains($gateSection, "'data' =>"), 'and returns no data envelope at all');

// The flag is re-read on EVERY session resolution. Captured at sign-in, an operator-issued reset
// would leave the already-live session exempt from the gate it has just become subject to.
$authenticator = $code($root . '/app/Auth/Authenticator.php');
Assert::contains(
    'u.must_change_password',
    $authenticator,
    'session resolution re-reads the account lifecycle rather than trusting the session row',
);

// The gate cannot be satisfied by the frontend. The React shell has no notion of it, and its own
// route is behind it.
$shellSource = (string) file_get_contents($root . '/frontend/console/src/shell/shell.jsx');
$appSource = (string) file_get_contents($root . '/frontend/console/src/app/app.jsx');
foreach ([$shellSource, $appSource] as $frontend) {
    Assert::false(
        str_contains($frontend, 'must_change_password'),
        'the browser holds no lifecycle state — the gate is not a frontend convention',
    );
}

// ============================================================ lifecycle writes are conditional

// The state a transition is permitted FROM is read before the transaction opens, so between the
// decision and the write another operator's command can commit. An unconditional UPDATE would then
// let a `reactivate` that read SUSPENDED overwrite a `retire` that landed first — and terminal
// retirement would be terminal only when nobody else was working at the same time.
//
// Structural because the failure needs two operators and a few milliseconds: a behavioural test
// cannot reliably produce the interleaving, and the predicate either guards the write or it does
// not.
$accountService = $code($root . '/app/Auth/AccountService.php');
Assert::contains(
    'AND status IN ({$placeholders})',
    $accountService,
    'a lifecycle transition writes only to a row still in a state it was permitted from',
);
Assert::contains(
    'ACCOUNT_TRANSITION_RACED',
    $accountService,
    'and a write that matched nothing is reported, never assumed to have happened',
);

// REACTIVATION IS AN END STATE, NOT A TRANSITION. The lockout the authenticator actually creates
// leaves `status` ACTIVE — `registerFailure` writes `failed_attempts` and `locked_until` and never
// the status — so a transition to ACTIVE would find the row already there and clear nothing, in the
// only case an operator ever meets.
Assert::contains(
    "SET status = 'ACTIVE', failed_attempts = 0, locked_until = NULL",
    $accountService,
    'reactivation writes the end state, so an ACTIVE-but-locked account is actually cleared',
);
$registerFailure = substr($code($root . '/app/Auth/Authenticator.php'), 0);
Assert::false(
    str_contains($registerFailure, "status = 'LOCKED'"),
    'and nothing in the authenticator writes the LOCKED status the earlier model assumed',
);

// A stood-down operator accumulates no membership. M02 denies while it is inactive, which is what
// makes the row dangerous rather than harmless: latent authority, taking effect on reinstatement.
Assert::contains(
    "'SELECT operator_id, status FROM operators WHERE operator_id = ? AND tenant_id = ?'",
    $accountService,
    'a membership grant reads the operator lifecycle, not merely its tenant',
);
Assert::same(
    2,
    substr_count($accountService, 'OPERATOR_NOT_BINDABLE'),
    'and refuses a non-ACTIVE operator on BOTH paths — createLogin, and the grant an existing account reaches instead',
);

// Reactivation revokes rather than assuming a prior revocation. "suspend already did it" holds only
// if every SUSPENDED row got there through this service — and replacing the hand-SQL path is why
// this service exists, so rows that did not predate it by definition.
Assert::contains(
    "revokeAllSessions(\$platformUserId, 'ACCOUNT_REACTIVATED')",
    $accountService,
    'it revokes every outstanding session as part of the same transaction',
);

// An inactive membership is a decision. Re-running onboarding must not rewrite one back to ACTIVE:
// that erases the authority withdrawal AND the record of it.
Assert::contains('MEMBERSHIP_NOT_ACTIVE', $accountService, 'a non-ACTIVE membership is refused');
Assert::false(
    str_contains($accountService, "UPDATE workspace_memberships SET status = 'ACTIVE'"),
    'and never rewritten back to ACTIVE by a grant',
);
// The credential write carries the same protection against the one transition that must never be
// undone: a retirement committed between the check and the write must not receive a fresh password.
Assert::contains(
    "WHERE platform_user_id = ? AND status <> 'RETIRED'",
    $accountService,
    'and a RETIRED account is never handed a working credential, however the race falls',
);

// The login path re-verifies under a row lock in the same transaction that inserts the session.
// Without it, a password change committing mid-login revokes every session that EXISTS and leaves
// the one inserted a moment later alive — issued against the password just replaced.
$authenticatorSource = $code($root . '/app/Auth/Authenticator.php');
Assert::contains(
    'FOR UPDATE',
    $authenticatorSource,
    'the credential is re-read under a row lock before a session is issued',
);
Assert::true(
    strpos($authenticatorSource, 'FOR UPDATE') < strpos($authenticatorSource, "insert('platform_sessions'"),
    'and the re-verify happens BEFORE the session insert, inside the same transaction',
);

// The front controller's expected pre-Kernel refusals record through the SAME sink, not just the
// header. P10 added maintenance/quiescence before Bootstrap, alongside configuration and database
// startup refusals. They all return before `Kernel::handle`, so a reference stamped there and
// recorded nowhere would be unresolvable for exactly the startup failures the reference exists for.
$frontController = $code($root . '/public_html/index.php');
Assert::contains('RequestLog::record(', $frontController, 'pre-Kernel failures record their reference too');
Assert::true(
    strpos($frontController, 'Request::fromGlobals(') < strpos($frontController, 'Bootstrap::load('),
    'the request is built before configuration is read, so those branches have one to record',
);
Assert::same(
    3,
    substr_count($frontController, '$earlyFailure('),
    'maintenance, bootstrap and database pre-Kernel refusals use the one stamped-and-recorded path',
);

// ============================================================ the support surface holds no secret

$support = $code($root . '/app/Http/Controllers/SupportController.php');
foreach (['Database', 'AccessGuard', 'Entitlements', 'SecretBox', 'Product\\P0', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE '] as $forbidden) {
    Assert::false(
        str_contains($support, $forbidden),
        "the support controller holds no governed data/authority primitive: {$forbidden}",
    );
}
Assert::contains(
    "return Response::redirect('/login');",
    $support,
    'and an anonymous caller never reaches it, even by direct invocation',
);

// Its configuration reach is a CLOSED LIST of two non-secret keys, and both are the ones it reads.
Assert::equalsList(
    ['SUPPORT_CONTACT_TEXT', 'APP_RELEASE_REF'],
    \Allfather\Loopia\Http\Controllers\SupportController::READABLE_CONFIG_KEYS,
    'the support surface may read exactly two non-secret configuration keys',
);
preg_match_all("/\\\$this->env->get\('([A-Z_]+)'\)/", $support, $configReads);
sort($configReads[1], SORT_STRING);
$declared = \Allfather\Loopia\Http\Controllers\SupportController::READABLE_CONFIG_KEYS;
sort($declared, SORT_STRING);
Assert::equalsList($declared, array_values(array_unique($configReads[1])), 'and reads no key outside that list');
foreach (['DB_PASSWORD', 'DB_USER', 'DB_HOST', 'DB_NAME', 'APP_MASTER_KEY', 'SCHEDULER_TOKEN'] as $secret) {
    Assert::false(str_contains($support, $secret), "the support surface never names {$secret}");
}

// Both new configuration keys are documented where an operator will look for them.
$envExample = (string) file_get_contents($root . '/.env.example');
foreach (['SUPPORT_CONTACT_TEXT=', 'APP_RELEASE_REF='] as $key) {
    Assert::contains($key, $envExample, ".env.example documents {$key}");
}
// NO INVENTED DEFAULT. A support page naming an address nobody reads is worse than one that says
// no contact is configured.
Assert::same(
    1,
    preg_match('/^SUPPORT_CONTACT_TEXT=$/m', $envExample),
    'and the support contact ships empty rather than as an invented address',
);

// Its one read is a single session-scoped statement in a file that contains nothing else.
$supportAdapter = $code($root . '/app/Http/ReadAdapters/SupportContextAdapter.php');
Assert::same(
    1,
    substr_count($supportAdapter, 'SELECT '),
    'the support read adapter makes exactly one query',
);
Assert::contains(
    "[(string) \$session['tenant_id'], (string) \$workspaceId]",
    $supportAdapter,
    'scoped by the resolved session, never by request input',
);
Assert::false(str_contains($supportAdapter, '$request'), 'and no HTTP caller input reaches it');

// The support TEMPLATE restates the claim ceiling. This is the page somebody reads when the
// product has just surprised them, and the commonest surprise is reading fit as intent.
$supportTemplate = (string) file_get_contents($root . '/app/Http/templates/support.php');
Assert::contains('is not buyer intent', $supportTemplate, 'the support page states that fit is not buyer intent');
Assert::contains('internal only', $supportTemplate, 'that outputs are internal only');
Assert::contains('No send, publish or CRM action exists', $supportTemplate, 'and that no external action exists at all');
Assert::contains('Reporting incorrect intelligence', $supportTemplate, 'and it separates incorrect intelligence from a technical failure');

// ============================================================ sign-out is still a POST

$layout = (string) file_get_contents($root . '/app/Http/templates/layout.php');
Assert::contains('<form method="post" action="/logout"', $layout, 'server-rendered sign-out remains a POST');
Assert::contains('name="csrf_token"', $layout, 'carrying the session CSRF token');
Assert::false(str_contains($layout, 'href="/logout"'), 'and is never a GET link');
foreach (['/account', '/support', '/console/workspaces'] as $utility) {
    Assert::contains('href="' . $utility . '"', $layout, "the server-rendered rail links to {$utility}");
}

$index = (string) file_get_contents($root . '/public_html/index.php');
foreach ([
    ["GET", '/account'],
    ["GET", '/account/password'],
    ["POST", '/account/password'],
    ["GET", '/support'],
] as [$method, $path]) {
    Assert::contains(
        "\$kernel->route('{$method}', '{$path}'",
        $index,
        "PR7 registers exact {$method} route {$path}",
    );
}
// The account and support surfaces are HTML, not a second JSON API. A JSON account route would be
// a browser-reachable read of account state that nothing in the shell needs.
Assert::false(str_contains($index, "'/console/api/v1/account'"), 'no JSON account API is invented');
Assert::false(str_contains($index, "'/console/api/v1/support'"), 'and no JSON support API either');
