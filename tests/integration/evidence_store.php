<?php

declare(strict_types=1);

/**
 * Content-addressed evidence storage.
 *
 * The claim being tested is precisely "content-addressed append-only APPLICATION semantics" —
 * not physical immutability, which the host does not provide. What must hold: a digest never
 * refers to two different payloads, a re-put of identical bytes is a no-op, a re-put of DIFFERENT
 * bytes under the same digest FAILS CLOSED, a read verifies integrity, and a digest cannot become
 * a path traversal.
 */

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Tests\Assert;

$root = sys_get_temp_dir() . '/allfather-evidence-' . bin2hex(random_bytes(6));
$store = new EvidenceStore($root);

$payload = '<html><body>Pricing: 499 SEK/month</body></html>';
$digest = Digest::contentDigest($payload);

// --- put / get round trip ---------------------------------------------------------------------------

$put = $store->put($payload);
Assert::true($put->isOk(), 'a payload is stored');
Assert::same($digest, $put->value()['digest'], 'the digest is the sha256 of the bytes themselves');
Assert::same(false, $put->value()['deduplicated'], 'the first put is a real write');
Assert::same(strlen($payload), $put->value()['size'], 'the size is recorded');

$get = $store->get($digest);
Assert::true($get->isOk(), 'the payload reads back');
Assert::same($payload, $get->value(), 'the bytes round-trip exactly');

// --- the shard layout ----------------------------------------------------------------------------------

$expectedPath = sprintf('%s/sha256/%s/%s/%s', $root, substr($digest, 0, 2), substr($digest, 2, 2), $digest);
Assert::true(is_file($expectedPath), 'the payload lands at its content-addressed shard path');

// --- idempotent re-put ------------------------------------------------------------------------------------

$again = $store->put($payload);
Assert::true($again->isOk(), 're-putting identical bytes succeeds');
Assert::same(true, $again->value()['deduplicated'], 'and is recognized as a duplicate, not a second write');

// --- THE collision guard ---------------------------------------------------------------------------------
// Forge the situation a real sha256 collision or a tampered store would produce: the digest's file
// exists but holds different bytes. The store must refuse, never overwrite.

$forgedDigest = Digest::contentDigest('original content');
$store->put('original content');
$forgedPath = sprintf('%s/sha256/%s/%s/%s', $root, substr($forgedDigest, 0, 2), substr($forgedDigest, 2, 2), $forgedDigest);
file_put_contents($forgedPath, 'TAMPERED content of a different length');

$collision = $store->put('original content');
Assert::true($collision->isRefusal(), 'a same-digest / different-bytes put FAILS CLOSED');
Assert::same('EVIDENCE_DIGEST_COLLISION', $collision->reasonCode(), 'and is refused by name');
Assert::same('OPEN_INCIDENT', $collision->responseMode, 'a collision routes to an incident, not a log line');
Assert::same(
    'TAMPERED content of a different length',
    file_get_contents($forgedPath),
    'the refusal did NOT overwrite the stored bytes',
);

// --- verify on read --------------------------------------------------------------------------------------

$corrupted = $store->get($forgedDigest);
Assert::true($corrupted->isRefusal(), 'a payload whose bytes no longer hash to their own name is refused');
Assert::same('EVIDENCE_INTEGRITY_FAILED', $corrupted->reasonCode(), 'refused by name');

// --- path traversal --------------------------------------------------------------------------------------
// The digest is the ONLY caller-supplied value that becomes a filesystem path, so it is confined
// to 64 hex characters before it is used.

foreach ([
    '../../../../etc/passwd',
    'aa/../../../etc/passwd',
    str_repeat('g', 64),
    '',
    strtoupper($digest),
] as $malicious) {
    $refused = $store->get($malicious);
    Assert::true($refused->isRefusal(), 'a non-digest path is refused: ' . substr($malicious, 0, 24));
    Assert::same('EVIDENCE_DIGEST_MALFORMED', $refused->reasonCode(), 'refused by name, before any filesystem access');
}

// --- missing payload -----------------------------------------------------------------------------------------

$missing = $store->get(str_repeat('0', 64));
Assert::true($missing->isRefusal(), 'an absent payload is refused, not returned as empty');
Assert::same('EVIDENCE_PAYLOAD_MISSING', $missing->reasonCode(), 'refused by name');

// --- there is no delete surface --------------------------------------------------------------------------------

Assert::true(
    !method_exists($store, 'delete') && !method_exists($store, 'remove') && !method_exists($store, 'unlink'),
    'the evidence store exposes NO delete method — erasure is retention downgrade, not unlinking',
);

// --- empty payload is still a payload ----------------------------------------------------------------------------

$empty = $store->put('');
Assert::true($empty->isOk(), 'an empty capture is storable (an empty page is evidence too)');
Assert::same(
    'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    $empty->value()['digest'],
    'and addresses to the well-known empty-string digest',
);

// cleanup
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $entry) {
    $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
}
@rmdir($root);
