<?php

declare(strict_types=1);

namespace Allfather\Loopia\Storage;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Outcome;

/**
 * Content-addressed evidence payload storage.
 *
 * MariaDB holds governance state and metadata; bulk evidence bytes live on the private filesystem
 * at `storage/evidence/sha256/ab/cd/<full_digest>`, outside `public_html`.
 *
 * **What this is, precisely.** Content-addressed **append-only application semantics** — NOT
 * physically immutable storage. The filesystem underneath is ordinary and writable by whatever the
 * host permits; the guarantees below are this class's behavior, and calling them "immutable
 * storage" would be a claim the host does not support.
 *
 * The guarantees:
 *  - a payload is named by the sha256 of its own bytes, so a name cannot refer to two contents;
 *  - writing a digest that already exists with DIFFERENT bytes FAILS CLOSED and returns a refusal
 *    the caller must record as an audit finding — it never overwrites;
 *  - writing a digest that already exists with the SAME bytes is a no-op success (idempotent
 *    retry, which the job machine depends on);
 *  - a write is atomic: bytes go to a temporary file in the same directory and are `rename()`d
 *    into place, so a reader never observes a partial payload;
 *  - reads verify the digest, so silent corruption surfaces as a refusal rather than as evidence.
 *
 * Raw paths are never exposed to users. The UI serves authorized, safe projections through PHP.
 */
final class EvidenceStore
{
    public function __construct(private readonly string $root)
    {
    }

    /**
     * Store `$bytes`, returning its digest.
     *
     * @return Outcome Outcome::ok(array{digest: string, size: int, deduplicated: bool})
     */
    public function put(string $bytes): Outcome
    {
        $digest = Digest::contentDigest($bytes);
        $path = $this->pathFor($digest);

        if (is_file($path)) {
            $existing = @file_get_contents($path);
            if ($existing === false) {
                return Outcome::failure('EVIDENCE_STORE_UNREADABLE', ['digest' => $digest], 'DEPENDENCY');
            }
            if ($existing === $bytes) {
                return Outcome::ok(['digest' => $digest, 'size' => strlen($bytes), 'deduplicated' => true]);
            }

            // Same digest, different bytes. Either sha256 collided or the store was tampered with;
            // both are incidents, and neither is a reason to overwrite.
            return Outcome::refuse('EVIDENCE_DIGEST_COLLISION', [
                'digest' => $digest,
                'stored_size' => strlen($existing),
                'incoming_size' => strlen($bytes),
            ], 'INTEGRITY', 'S4', 'OPEN_INCIDENT');
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            return Outcome::failure('EVIDENCE_STORE_MKDIR_FAILED', ['dir' => $this->relative($dir)], 'DEPENDENCY');
        }

        // Temp file in the SAME directory: rename() is only atomic within one filesystem, and a
        // system temp dir may be a different mount.
        $temp = $dir . '/.tmp-' . bin2hex(random_bytes(8));
        if (@file_put_contents($temp, $bytes, LOCK_EX) !== strlen($bytes)) {
            @unlink($temp);

            return Outcome::failure('EVIDENCE_STORE_WRITE_FAILED', ['digest' => $digest], 'DEPENDENCY');
        }
        @chmod($temp, 0o640);

        if (!@rename($temp, $path)) {
            @unlink($temp);
            // A concurrent writer may have won the race; that is fine if the bytes agree.
            if (is_file($path) && @file_get_contents($path) === $bytes) {
                return Outcome::ok(['digest' => $digest, 'size' => strlen($bytes), 'deduplicated' => true]);
            }

            return Outcome::failure('EVIDENCE_STORE_RENAME_FAILED', ['digest' => $digest], 'DEPENDENCY');
        }

        return Outcome::ok(['digest' => $digest, 'size' => strlen($bytes), 'deduplicated' => false]);
    }

    /**
     * Read a payload by digest, verifying integrity.
     *
     * @return Outcome Outcome::ok(string) with the bytes
     */
    public function get(string $digest): Outcome
    {
        if (preg_match('/^[0-9a-f]{64}$/', $digest) !== 1) {
            // Also the path-traversal guard: a digest is the ONLY thing that becomes a path here,
            // and it is confined to 64 hex characters before it is used.
            return Outcome::refuse('EVIDENCE_DIGEST_MALFORMED', ['digest' => substr($digest, 0, 80)], 'VALIDATION');
        }

        $path = $this->pathFor($digest);
        if (!is_file($path)) {
            return Outcome::refuse('EVIDENCE_PAYLOAD_MISSING', ['digest' => $digest], 'EVIDENCE');
        }

        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return Outcome::failure('EVIDENCE_STORE_UNREADABLE', ['digest' => $digest], 'DEPENDENCY');
        }

        $actual = Digest::contentDigest($bytes);
        if (!hash_equals($digest, $actual)) {
            // Verify-on-read: a payload whose bytes no longer hash to their own name is not
            // evidence. Fail closed and let the caller open an incident.
            return Outcome::refuse('EVIDENCE_INTEGRITY_FAILED', [
                'expected_digest' => $digest,
                'actual_digest' => $actual,
            ], 'INTEGRITY', 'S4', 'OPEN_INCIDENT');
        }

        return Outcome::ok($bytes);
    }

    public function has(string $digest): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $digest) === 1 && is_file($this->pathFor($digest));
    }

    public function sizeOf(string $digest): ?int
    {
        if (!$this->has($digest)) {
            return null;
        }
        $size = @filesize($this->pathFor($digest));

        return $size === false ? null : $size;
    }

    /**
     * There is deliberately no `delete()`.
     *
     * Evidence is append-only application semantics; a GDPR erasure obligation is met by
     * retention downgrade and redaction at the projection layer (matching the upstream P07-A rule
     * that erasure is a retention downgrade, never a deletion), not by unlinking a payload that a
     * decision record still cites. Physical expiry is an operator runbook step with its own
     * governed record, not a method on this class.
     */
    public function retentionNote(): string
    {
        return 'append-only application semantics; no delete surface';
    }

    private function pathFor(string $digest): string
    {
        return sprintf(
            '%s/sha256/%s/%s/%s',
            rtrim($this->root, '/'),
            substr($digest, 0, 2),
            substr($digest, 2, 2),
            $digest,
        );
    }

    private function relative(string $path): string
    {
        return str_replace(rtrim($this->root, '/') . '/', '', $path);
    }
}
