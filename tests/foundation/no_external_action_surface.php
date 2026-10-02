<?php

declare(strict_types=1);

/**
 * Structural proof that no external-action surface exists in this repository.
 *
 * This is not a style check. The projection's central claim is that D/E action classes are
 * UNREACHABLE from this codebase — not merely unused, not merely gated behind a flag. A grep-level
 * tripwire is the cheapest way to keep that true across future edits, because the way this
 * boundary actually breaks is somebody adding one convenient `mail()` call.
 *
 * The reviewed source-read and inactive bounded-model transports are the two network leaves.
 * Neither grants external-action authority; their activation is asserted separately below.
 */

use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);

/** @return list<string> */
$phpFiles = static function (string $dir): array {
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $out[] = $file->getPathname();
        }
    }
    sort($out);

    return $out;
};

$sources = [
    ...$phpFiles($root . '/app'),
    ...$phpFiles($root . '/public_html'),
    ...$phpFiles($root . '/worker'),
];

// Functions that would give this codebase a real external side effect.
$forbidden = [
    'mail(' => 'PHP mail() — email send is P07-B, which is NOT authorized',
    'imap_' => 'IMAP — external mail transport',
    'ftp_' => 'FTP — external file transport',
    'fsockopen(' => 'raw socket — bypasses the governed egress guard',
    'pfsockopen(' => 'raw persistent socket — bypasses the governed egress guard',
    'stream_socket_client(' => 'raw socket client — bypasses the governed egress guard',
    'shell_exec(' => 'shell execution',
    'proc_open(' => 'process execution',
    'passthru(' => 'process execution',
    'popen(' => 'process execution',
];

/** One file's EXECUTABLE source. Comments are stripped so a prohibition can be documented in prose
 * without the prose tripping the assertion that enforces it. */
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

foreach ($forbidden as $needle => $why) {
    $hits = [];
    foreach ($sources as $file) {
        if (str_contains($code($file), $needle)) {
            $hits[] = str_replace($root . '/', '', $file);
        }
    }
    Assert::same([], $hits, "no external-action surface: {$needle} ({$why})");
}

// Exact leaves only: adding another file anywhere, including Provider/, must fail this check.
$curlUsers = [];
foreach ($sources as $file) {
    if (str_contains((string) file_get_contents($file), 'curl_exec(')) {
        $curlUsers[] = str_replace($root . '/', '', $file);
    }
}
Assert::same(['app/Ingestion/CurlHttpsTransport.php','app/Provider/OpenAIResponsesTransport.php'], $curlUsers,
    'curl_exec appears only in the two exact reviewed transport leaves');
$modelWiring=[];
foreach ([...$sources,...$phpFiles($root.'/ops')] as $file) {
    if (preg_match('/new\s+(\\\\?[A-Za-z\\\\]*\\\\)?OpenAIResponsesTransport\s*\(/', $code($file))===1) $modelWiring[]=str_replace($root.'/', '', $file);
}
$factoryExists=is_file($root.'/app/Workflow/MonitorFactory.php');
Assert::same($factoryExists?['app/Workflow/MonitorFactory.php']:[], $modelWiring, 'only the reviewed factory may construct the native model transport');
if($factoryExists){
    $factoryCode=$code($root.'/app/Workflow/MonitorFactory.php');
    // ONE constructor site, and its activation is never a literal: it is `$admitted`, computed from
    // an ADMITTED execution grant in `admittedModelTransport()`, or `false` for the inactive composition.
    Assert::same(1,preg_match_all('/new\s+OpenAIResponsesTransport\s*\(/',$factoryCode),
        'the factory contains exactly one native transport constructor');
    Assert::same(1,preg_match_all('/return\s+new\s+OpenAIResponsesTransport\s*\(\s*\$admitted\s*\)\s*;/',$factoryCode),
        'the sole constructor takes its activation from the admission check, never a literal');
    preg_match_all('/self::modelTransport\(\s*([^)]*?)\s*\)/',$factoryCode,$activations);
    $activationArgs=array_values(array_unique($activations[1]));
    sort($activationArgs);
    Assert::same(['$admitted','false'],$activationArgs,'the transport is requested only as inactive or as the result of an admission check');
    Assert::true(preg_match('/\$admitted\s*=\s*\$grant\s*!==\s*null\s*&&\s*\$grant\[\'purpose\'\]\s*===\s*\$purpose\s*&&\s*\$grants->status\(\$grantId\)\s*===\s*\'ADMITTED\'\s*;/',$factoryCode)===1,
        'activation requires an existing grant, of the right purpose, ADMITTED now');
    Assert::false(str_contains($factoryCode,'modelTransport(true)'),'nothing asks for an activated transport by fiat');
}

// ============================================================ the live-read activation boundary

// `LIVE_READ_ACTIVATED` stays false, and the claim rests on something stronger than a flag. The
// production transport and the real DNS resolver EXIST, are reviewed and are tested — and there is
// exactly ONE place that constructs them: `MonitorFactory::liveSource($admitted)`, where `$admitted`
// is computed from an ADMITTED MANUAL_MONITOR execution grant in the workspace. An admitted grant
// needs a reviewed owner decision under docs/decisions/, and this repository ships none. So the
// leaf is wired behind admission, never by a line in a refactor: any other constructor, or an
// activation that is not the admission check, fails here.
$liveWiring = [];
$resolverWiring = [];
foreach ([...$sources, ...$phpFiles($root . '/worker'), ...$phpFiles($root . '/ops')] as $file) {
    $relative = str_replace($root . '/', '', $file);
    if (str_contains($relative, 'Ingestion/CurlHttpsTransport.php') || str_contains($relative, 'Ingestion/DnsHostResolver.php') || $relative === 'app/Workflow/MonitorFactory.php') {
        continue;
    }
    if (preg_match('/new\s+(\\\\?[A-Za-z\\\\]*\\\\)?CurlHttpsTransport\s*\(/', (string) file_get_contents($file)) === 1) {
        $liveWiring[] = $relative;
    }
    if (preg_match('/new\s+(\\\\?[A-Za-z\\\\]*\\\\)?DnsHostResolver\s*\(/', (string) file_get_contents($file)) === 1) {
        $resolverWiring[] = $relative;
    }
}
Assert::same([], $liveWiring, 'nothing outside the admitted Monitor composition constructs the live transport');
Assert::same([], $resolverWiring, 'and nothing outside it constructs the real host resolver either');
if ($factoryExists) {
    $factoryCode = $code($root . '/app/Workflow/MonitorFactory.php');
    Assert::same(1, preg_match_all('/new\s+CurlHttpsTransport\s*\(/', $factoryCode), 'the factory constructs the live transport in one place');
    Assert::same(1, preg_match_all('/new\s+DnsHostResolver\s*\(/', $factoryCode), 'and the real resolver in one place');
    Assert::same(1, preg_match('/return\s+\$admitted\s*\?\s*\[\s*new\s+CurlHttpsTransport\(\)\s*,\s*new\s+DnsHostResolver\(\)\s*\]\s*:\s*\[\s*new\s+NoNetworkTransport\(\)\s*,\s*new\s+FixedHostResolver\(\[\]\)\s*\]\s*;/', $factoryCode), 'both exist only on the admitted branch; otherwise there is no network leaf');
    preg_match_all('/self::liveSource\(\s*([^)]*?)\s*\)/', $factoryCode, $liveArgs);
    Assert::same(['$admitted'], array_values(array_unique($liveArgs[1])), 'live read is requested only as the result of the admission check');
    Assert::same(1, preg_match('/\$admitted\s*=\s*\$grants->admittedIn\(\$tenantId\s*,\s*\$workspaceId\s*,\s*\'MANUAL_MONITOR\'\)\s*!==\s*\[\]\s*;/', $factoryCode), 'the admission check is an ADMITTED MANUAL_MONITOR grant in this workspace');
    Assert::false(str_contains($factoryCode, 'liveSource(true)'), 'nothing asks for live read by fiat');
}

// The claim-boundary flags live in a file no code writes. They are changed by a human editing
// `upstream.lock.json`, which is what "operator-assisted" means here: this build cannot record
// that a road test happened, cannot record that live read was activated, and cannot record its
// own launch authority.
//
// `deployment-evidence.json` is held to the SAME rule, and for a sharper reason. It is the only
// thing that can support an operational claim — "staging is deployed", "the host was qualified" —
// so a code path able to append to it would be a code path able to qualify its own deployment.
// Recording evidence is a diff a human writes and somebody else reviews; that review is the
// mechanism, and a writer here would make it optional on exactly the days it matters.
//
// THE SCAN IS DELIBERATELY COARSE: any file that NAMES one of these and also calls a write
// primitive fails, without anybody having to prove the two are connected. A precise scan would have
// to model which variable holds which path, and the day it got that wrong is the day it got it
// wrong silently.
//
// Two exemptions, and they are exact spellings rather than a category. `fwrite(STDERR, …)` and
// `fwrite(STDOUT, …)` are how every command in `ops/` reports a refusal, and neither can write a
// file: the stream is a constant, not a path. Excluding them by exact spelling keeps the tripwire
// at full strength — `fwrite($handle, …)` still trips it — while letting the one command whose job
// is to READ these files report why it refused.
$writePrimitives = '/file_put_contents|fopen|fwrite|rename\s*\(/';
foreach (['upstream.lock.json', 'deployment-evidence.json'] as $governedFile) {
    $writers = [];
    foreach ([...$sources, ...$phpFiles($root . '/worker'), ...$phpFiles($root . '/ops')] as $file) {
        $contents = str_replace(
            ['fwrite(STDERR,', 'fwrite(STDOUT,', 'fwrite(\STDERR,', 'fwrite(\STDOUT,'],
            '',
            $code($file),
        );
        if (str_contains($contents, $governedFile) && preg_match($writePrimitives, $contents) === 1) {
            $writers[] = str_replace($root . '/', '', $file);
        }
    }
    Assert::same(
        [],
        $writers,
        "no code path writes {$governedFile} — what it records is a human's to change",
    );
}

// The exemption itself is asserted, so it cannot quietly widen: a stream write is exempt, a write
// through a handle is not.
Assert::true(
    preg_match($writePrimitives, str_replace('fwrite(STDERR,', '', 'fwrite(STDERR, "x");')) !== 1,
    'the standard-stream exemption removes exactly the stream write',
);
Assert::true(
    preg_match($writePrimitives, str_replace('fwrite(STDERR,', '', 'fwrite($handle, "x");')) === 1,
    'and leaves a write through a file handle trippable',
);
