<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P07A;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P02\PrivacyPatterns;

/**
 * The P07-A PII firewall.
 *
 * ONE ENTRANCE, AND A STORE BEHIND IT. `pseudonymize()` is the only function in the projection that
 * ever sees a raw recipient identifier. It computes a salted, non-reversible pseudonym and DISCARDS
 * the identifier — it is never persisted, never logged, never returned. Everything downstream —
 * consent, artifacts, evidence, audits — carries only `recipient_pseudonym_ref`.
 *
 * THE SALT IS THE SEPARATION. Under GDPR Art. 4(5) a pseudonym is only a pseudonym while the
 * additional information that would reverse it is kept apart. Here that is the salt. P07-A ships a
 * DOCUMENTED DEMO SALT, in the open, because there are no real recipients in this projection and a
 * secret nobody uses is a secret nobody rotates. The function is salt-injectable precisely so a
 * deployment with real recipients supplies a sealed per-tenant secret instead — and the port ships
 * no path that would give it one, because it ships no path that sends.
 *
 * THE PSEUDONYM IS BASE64URL, NOT HEX, and that is load-bearing rather than cosmetic. A hex digest
 * is a long run of digits, which the individual-identifier detector would read as a phone number —
 * so the firewall's own output would trip the audit that checks the firewall held.
 *
 * ERASURE IS A DOWNGRADE, NEVER A DELETE. GDPR erasure clears the sealed locator and moves the
 * retention class to ERASED_PSEUDONYMIZED; the row survives, and the no-delete trigger enforces
 * that independently. The mapping has to persist for the pseudonym in past audit records to still
 * mean something — deleting it would erase the audit trail rather than the person.
 */
final class PiiFirewall
{
    /**
     * The documented demo salt.
     *
     * Stated in the open deliberately. With no real recipients in this projection, a "secret" salt
     * would be security theatre — an unrotated constant with a warning label. A deployment that
     * acquires real recipients injects a sealed per-tenant secret through `$salt`, and acquiring
     * real recipients is not something this projection can do.
     */
    public const DEMO_SALT = 'p07a-demo-pseudonym-salt-v1';

    public const PSEUDONYM_VERSION = 'p07a-pseudonym-v1';

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * The deterministic, non-reversible pseudonym for a recipient.
     *
     * Same scope, channel, identifier and salt give the same pseudonym, so consent and cooldown
     * lookups line up across calls. Different recipients give different pseudonyms. The raw
     * identifier never leaves this function.
     */
    public static function computePseudonym(
        string $tenantId,
        string $workspaceId,
        string $channel,
        string $rawRecipientIdentifier,
        ?string $salt = null,
    ): string {
        $canonical = Digest::canonicalize([
            'v' => self::PSEUDONYM_VERSION,
            'salt' => $salt ?? self::DEMO_SALT,
            'tenantId' => $tenantId,
            'workspaceId' => $workspaceId,
            'channel' => $channel,
            'identifier' => self::normalizeIdentifier($channel, $rawRecipientIdentifier),
        ]);

        // base64url, not hex — see the class comment. A hex digest reads as a phone-like digit run
        // to the very detector that audits this firewall.
        $digest = rtrim(strtr(base64_encode(hash('sha256', $canonical, true)), '+/', '-_'), '=');

        return 'pseud:' . $digest;
    }

    /**
     * The firewall entrance.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, channel: string,
     *     raw_recipient_identifier: string, sealed_identity_locator_safe?: ?string, salt?: ?string
     * } $input
     */
    public function pseudonymize(array $input): Outcome
    {
        if (trim($input['raw_recipient_identifier']) === '') {
            return Outcome::refuse('EMPTY_IDENTIFIER', [], 'POLICY');
        }
        // A "safe" locator that actually embeds raw PII defeats the entire firewall — the raw
        // identifier would be persisted in the one column nobody scans. Fail closed.
        $locator = $input['sealed_identity_locator_safe'] ?? null;
        if ($locator !== null && self::containsRawPii($locator)) {
            return Outcome::refuse('UNSAFE_LOCATOR_CONTAINS_PII', [], 'POLICY', 'S5', 'BLOCK_RELEASE');
        }

        $workspace = $this->db->one(
            'SELECT tenant_id FROM workspaces WHERE workspace_id = ?',
            [$input['workspace_id']]
        );
        if ($workspace === null) {
            return Outcome::refuse('WORKSPACE_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $workspace['tenant_id'] !== $input['tenant_id']) {
            return Outcome::refuse('WORKSPACE_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }

        $pseudonym = self::computePseudonym(
            $input['tenant_id'],
            $input['workspace_id'],
            $input['channel'],
            $input['raw_recipient_identifier'],
            $input['salt'] ?? null,
        );

        // Idempotent: one mapping per recipient and channel. A second call returns the existing
        // mapping rather than creating a duplicate — which matters because the caller cannot tell
        // whether it has seen this recipient before without asking, and asking would mean holding
        // the raw identifier to compare.
        $existing = $this->db->one(
            'SELECT recipient_pseudonym_mapping_id FROM recipient_pseudonym_mappings
             WHERE tenant_id = ? AND workspace_id = ? AND recipient_pseudonym_ref = ? AND channel = ?',
            [$input['tenant_id'], $input['workspace_id'], $pseudonym, $input['channel']]
        );
        if ($existing !== null) {
            return Outcome::ok([
                'recipient_pseudonym_ref' => $pseudonym,
                'recipient_pseudonym_mapping_id' => (string) $existing['recipient_pseudonym_mapping_id'],
                'created' => false,
            ]);
        }

        $mappingId = Ids::new();
        $this->db->insert('recipient_pseudonym_mappings', [
            'recipient_pseudonym_mapping_id' => $mappingId,
            'schema_version' => '1',
            'tenant_id' => $input['tenant_id'],
            'workspace_id' => $input['workspace_id'],
            'recipient_pseudonym_ref' => $pseudonym,
            'channel' => $input['channel'],
            'pseudonymization_method' => 'SALTED_SHA256',
            'sealed_identity_locator_safe' => $locator,
            'retention_class' => 'ACTIVE_LINKABLE',
            'created_at' => $this->clock->now(),
        ]);

        return Outcome::ok([
            'recipient_pseudonym_ref' => $pseudonym,
            'recipient_pseudonym_mapping_id' => $mappingId,
            'created' => true,
        ]);
    }

    /**
     * @param array{tenant_id: string, workspace_id: string, recipient_pseudonym_ref: string, channel: string} $input
     * @return array<string, mixed>|null
     */
    public function mapping(array $input): ?array
    {
        return $this->db->one(
            'SELECT recipient_pseudonym_mapping_id, recipient_pseudonym_ref, channel, retention_class,
                    sealed_identity_locator_safe, created_at, retention_downgraded_at
             FROM recipient_pseudonym_mappings
             WHERE tenant_id = ? AND workspace_id = ? AND recipient_pseudonym_ref = ? AND channel = ?',
            [
                $input['tenant_id'],
                $input['workspace_id'],
                $input['recipient_pseudonym_ref'],
                $input['channel'],
            ]
        );
    }

    /**
     * GDPR erasure: a retention downgrade, never a deletion.
     *
     * @param array{tenant_id: string, workspace_id: string, recipient_pseudonym_ref: string, channel: string} $input
     */
    public function eraseUnderGdpr(array $input): Outcome
    {
        $mapping = $this->mapping($input);
        if ($mapping === null) {
            return Outcome::refuse('MAPPING_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $mapping['retention_class'] === 'ERASED_PSEUDONYMIZED') {
            // Refused rather than silently repeated, so a second erasure request is visibly a
            // second request. An erasure that quietly no-ops leaves the requester unable to tell
            // whether the first one worked.
            return Outcome::refuse('ALREADY_ERASED', [], 'POLICY');
        }

        $this->db->run(
            "UPDATE recipient_pseudonym_mappings
             SET retention_class = 'ERASED_PSEUDONYMIZED', sealed_identity_locator_safe = NULL,
                 retention_downgraded_at = ?
             WHERE recipient_pseudonym_mapping_id = ?",
            [$this->clock->now(), (string) $mapping['recipient_pseudonym_mapping_id']]
        );

        return Outcome::ok([
            'recipient_pseudonym_mapping_id' => (string) $mapping['recipient_pseudonym_mapping_id'],
        ]);
    }

    /**
     * The defence-in-depth raw-PII detector.
     *
     * The SAME detector the M08 privacy rules and the P04 audits use. One implementation, because
     * a firewall and the audit that checks it must not be able to disagree about what PII is.
     */
    public static function containsRawPii(string $text): bool
    {
        return PrivacyPatterns::containsIndividualIdentifier($text);
    }

    /**
     * The DEEP scan, over a structured payload.
     *
     * The per-recipient payload is what would actually reach a person under a live send, so it is
     * scanned whole: every string, at every depth, INCLUDING OBJECT KEYS. A raw email used as a
     * JSON key is still payload content, and a scan that only walked values would miss it.
     */
    public static function containsRawPiiDeep(mixed $value): bool
    {
        if (is_string($value)) {
            return self::containsRawPii($value);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key) && self::containsRawPii($key)) {
                    return true;
                }
                if (self::containsRawPiiDeep($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Email is case-insensitive; SMS and push tokens are taken verbatim, trimmed only. */
    private static function normalizeIdentifier(string $channel, string $rawIdentifier): string
    {
        $trimmed = trim($rawIdentifier);

        return $channel === 'EMAIL' ? mb_strtolower($trimmed) : $trimmed;
    }
}
