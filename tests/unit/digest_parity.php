<?php

declare(strict_types=1);

/**
 * Canonical digest parity.
 *
 * The vectors in `tests/fixtures/canonical_digest_vectors.json` were produced by executing the
 * canonical `canonicalize` / `canonicalDigest` / `chainDigest` algorithm from
 * `packages/allfather-substrate-core/src/digest.ts` @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d.
 *
 * Digest parity is load-bearing rather than cosmetic: content hashes bind evidence to its record,
 * chained record hashes are the decision ledger's integrity, and a frozen artifact is identified
 * BY its digest. A PHP implementation that hashed differently would produce a system that looks
 * correct and cannot verify anything against upstream.
 *
 * Two PHP-specific divergences were found and closed while writing this port; both are pinned
 * below so they cannot regress:
 *   1. numeric object keys must encode as `"0"`, not bare `0` — PHP coerces them to int on the
 *      way in, and the encoder has to cast them back;
 *   2. `[]` is ambiguous in PHP — an empty JSON object must be `new stdClass()`.
 */

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Tests\Assert;

$vectors = json_decode(
    (string) file_get_contents(dirname(__DIR__) . '/fixtures/canonical_digest_vectors.json'),
    false,
    512,
    JSON_THROW_ON_ERROR,
);

foreach ($vectors as $index => $vector) {
    Assert::same($vector->canonical, Digest::canonicalize($vector->input), "vector {$index}: canonical encoding");
    Assert::same($vector->digest, Digest::canonicalDigest($vector->input), "vector {$index}: sha256 digest");
    Assert::same($vector->chained_empty, Digest::chainDigest('', $vector->input), "vector {$index}: genesis chain");
    Assert::same($vector->chained_abc, Digest::chainDigest('abc', $vector->input), "vector {$index}: chain onto prior");
}

// --- the two PHP-specific hazards, pinned ------------------------------------------------------

// PHP coerces the string keys '0'/'1' to ints, making this array a LIST — so `['0'=>6,'1'=>7]`
// legitimately encodes as `[6,7]`, and a caller who meant an object must say so with stdClass.
Assert::same('[6,7]', Digest::canonicalize(['0' => 6, '1' => 7]), 'consecutive numeric keys are a list in PHP');
Assert::same(
    '{"0":6,"1":7}',
    Digest::canonicalize((object) ['0' => 6, '1' => 7]),
    'an object with numeric keys encodes them QUOTED (the int-coercion hazard)',
);
Assert::same(
    '{"2":"b","7":"a"}',
    Digest::canonicalize((object) [7 => 'a', 2 => 'b']),
    'numeric object keys sort by string spelling, as JSON renders them',
);
Assert::same('{}', Digest::canonicalize(new stdClass()), 'stdClass is an empty OBJECT');
Assert::same('[]', Digest::canonicalize([]), 'an empty PHP array is an empty LIST');
Assert::notSame(
    Digest::canonicalDigest(new stdClass()),
    Digest::canonicalDigest([]),
    'empty object and empty list must not collide',
);

// --- key-order independence, the property the whole scheme exists for --------------------------

Assert::same(
    Digest::canonicalDigest(['a' => 1, 'b' => 2, 'c' => 3]),
    Digest::canonicalDigest(['c' => 3, 'a' => 1, 'b' => 2]),
    'digest is independent of key insertion order',
);
Assert::notSame(
    Digest::canonicalDigest(['a' => 1, 'b' => 2]),
    Digest::canonicalDigest(['a' => 2, 'b' => 1]),
    'digest still distinguishes different values',
);

// --- array order IS significant ---------------------------------------------------------------

Assert::notSame(
    Digest::canonicalDigest([1, 2, 3]),
    Digest::canonicalDigest([3, 2, 1]),
    'array order is preserved and significant',
);

// --- absent vs null -----------------------------------------------------------------------------

Assert::same(
    '{"b":2}',
    Digest::canonicalize(['a' => \Allfather\Loopia\Core\Absent::value(), 'b' => 2]),
    'an Absent property is omitted (upstream `undefined` semantics)',
);
Assert::same(
    '{"a":null,"b":2}',
    Digest::canonicalize(['a' => null, 'b' => 2]),
    'an explicit null is encoded, not omitted',
);

// --- chain digests are order-dependent and prefix-bound ------------------------------------------

$c1 = Digest::chainDigest('aa', ['x' => 1]);
$c2 = Digest::chainDigest('ab', ['x' => 1]);
Assert::notSame($c1, $c2, 'chain digest binds the prior record hash');

// A NUL separator prevents the prior-hash/content boundary from being ambiguous. Without it,
// chain("ab", X) and chain("a", "b"+X) could collide.
Assert::notSame(
    Digest::chainDigest('ab', 'c'),
    Digest::chainDigest('a', 'bc'),
    'the NUL separator prevents boundary ambiguity between prior hash and content',
);

// --- raw byte content digest --------------------------------------------------------------------

Assert::same(hash('sha256', 'hello'), Digest::contentDigest('hello'), 'content digest is sha256 over raw bytes');
Assert::same(
    'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    Digest::contentDigest(''),
    'the empty-payload digest is the well-known sha256 of the empty string',
);
