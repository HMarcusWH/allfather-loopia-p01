<?php

declare(strict_types=1);

namespace Allfather\Loopia\Core;

/**
 * Canonical integrity digests.
 *
 * Ported semantics: `packages/allfather-substrate-core/src/digest.ts`
 * @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d
 *
 * The canonical rule is sha256 over a deterministic, key-sorted JSON encoding, so any two
 * structurally-equal values hash identically regardless of key insertion order. This is required
 * for stable ledger chains and frozen-artifact verification, so the encoding is reproduced exactly
 * rather than approximated with `json_encode` defaults:
 *
 *  - object keys sorted lexicographically by byte value (matching `Object.keys` + `Array#sort`,
 *    which sorts by UTF-16 code unit; for the ASCII key space this build uses these agree, and
 *    `assertJsonValue` rejects the non-ASCII-key cases where they could diverge — see
 *    `Json::assertCanonicalKeys`);
 *  - arrays keep order;
 *  - null object properties are omitted only when they represent an absent value, matching the
 *    upstream treatment of `undefined`; an explicit JSON `null` is encoded as `null`;
 *  - primitives encode with JSON escaping identical to `JSON.stringify`.
 *
 * PHP's `json_encode` escapes `/` as `\/` and non-ASCII as `\uXXXX` by default, while
 * `JSON.stringify` does neither. `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` aligns them.
 *
 * **Empty array vs empty object.** PHP's `[]` is both an empty list and an empty map; JavaScript's
 * `[]` and `{}` are different values and hash differently. `[]` therefore encodes as `[]`, and an
 * empty JSON *object* must be passed as `new \stdClass()`. A `stdClass` at any depth is always
 * encoded as an object, so a caller that needs object-ness preserved can state it. Silently
 * guessing here would make a digest depend on how a value happened to reach PHP.
 */
final class Digest
{
    /**
     * Deterministic encoder. Throws rather than silently producing an unstable digest.
     */
    public static function canonicalize(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException('canonicalize: non-finite number is outside the JSON law');
            }
            // JSON.stringify renders an integral float without a fractional part (1.0 -> "1").
            if (floor($value) === $value && abs($value) < 1.0e15) {
                return (string) (int) $value;
            }

            return self::encodePrimitive($value);
        }
        if (is_string($value)) {
            return self::encodePrimitive($value);
        }
        if ($value instanceof \stdClass) {
            return self::canonicalizeObject(get_object_vars($value));
        }
        if (is_array($value)) {
            if (self::isList($value)) {
                $parts = [];
                foreach ($value as $item) {
                    $parts[] = self::canonicalize($item);
                }

                return '[' . implode(',', $parts) . ']';
            }

            return self::canonicalizeObject($value);
        }

        throw new \InvalidArgumentException('canonicalize: unsupported value type ' . get_debug_type($value));
    }

    /**
     * Encode a JSON object. PHP coerces numeric-string array keys to int, so every key is cast
     * back to its string spelling before both sorting and encoding — without the cast, key `0`
     * renders as bare `0` instead of `"0"` and the digest diverges from canonical.
     *
     * @param array<array-key, mixed> $value
     */
    private static function canonicalizeObject(array $value): string
    {
        $keys = array_map(static fn (int|string $k): string => (string) $k, array_keys($value));
        sort($keys, SORT_STRING);

        $parts = [];
        foreach ($keys as $key) {
            // Read back through the original key: PHP re-coerces "0" to 0 on lookup, so both
            // spellings must be tried or an int-coerced entry is missed.
            $child = array_key_exists($key, $value) ? $value[$key] : $value[(int) $key];
            if ($child instanceof Absent) {
                continue; // upstream `undefined` — omitted, matching JSON object semantics
            }
            $parts[] = self::encodePrimitive($key) . ':' . self::canonicalize($child);
        }

        return '{' . implode(',', $parts) . '}';
    }

    /** sha256 hex digest over the canonical encoding. Stable across key ordering. */
    public static function canonicalDigest(mixed $value): string
    {
        return hash('sha256', self::canonicalize($value));
    }

    /**
     * Chain a record digest onto a prior digest (Decision Ledger pattern, M10).
     * The empty string is the conventional genesis predecessor for the first record in a stream.
     */
    public static function chainDigest(string $priorRecordHash, mixed $content): string
    {
        return hash('sha256', $priorRecordHash . "\x00" . self::canonicalize($content));
    }

    /** sha256 over raw bytes — the content-address of an evidence payload. */
    public static function contentDigest(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    private static function encodePrimitive(string|int|float|bool|null $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($encoded === false) {
            throw new \InvalidArgumentException('canonicalize: primitive could not be JSON encoded');
        }

        return $encoded;
    }

    /** @param array<mixed> $value */
    private static function isList(array $value): bool
    {
        return $value === [] || array_is_list($value);
    }
}
