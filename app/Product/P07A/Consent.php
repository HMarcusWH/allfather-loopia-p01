<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P07A;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;

/**
 * P07-A consent and lawful basis.
 *
 * NOTHING IN P07-A SENDS ANYTHING. This is the safety foundation a live send would have to cross,
 * built and proved before any such send exists. That ordering is the point: the consent law is
 * written and tested while there is no channel to be tempted by.
 *
 * THREE BEHAVIOURS, and each is doing work the others cannot:
 *
 *   Consent is APPEND-ONLY. A withdrawal is a NEW ROW, never an edit, and the storage triggers
 *   refuse both UPDATE and DELETE. This is what makes consent history reconstructable: what
 *   somebody consented to on a given day is a fact about that day, and an edit would destroy it.
 *
 *   The EFFECTIVE consent is the LATEST row. A later withdrawal supersedes an earlier opt-in by
 *   RECENCY, not by mutation — which is the only way an append-only ledger can express a change of
 *   mind.
 *
 *   The pre-send check FAILS CLOSED in five directions: no record at all, WITHDRAWN, OPTED_OUT,
 *   a NO_BASIS state, and a NONE lawful basis. Only an OPTED_IN recipient with a real recorded
 *   basis is permitted. Silence is not consent, and the code says so by treating an absent record
 *   as a block rather than as an unknown to resolve later.
 *
 * THE RECIPIENT IS A PSEUDONYM THROUGHOUT. No raw email, phone or name enters this path — the
 * column is `recipient_pseudonym_ref`, and the firewall that fills it is the only place a raw
 * identifier is ever seen.
 */
final class Consent
{
    /** @var list<string> */
    public const CHANNELS = ['EMAIL', 'SMS', 'PUSH'];

    /** @var list<string> */
    public const CONSENT_STATES = ['OPTED_IN', 'OPTED_OUT', 'WITHDRAWN', 'NO_BASIS'];

    /** @var list<string> */
    public const LAWFUL_BASES = ['CONSENT', 'LEGITIMATE_INTEREST', 'CONTRACT', 'LEGAL_OBLIGATION', 'NONE'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Record a consent state. Appends; never edits.
     *
     * `recorded_at` is the real-world time the state took effect, and it defaults to now. A caller
     * backdating a withdrawal below the opt-in it supersedes would produce a registry whose latest
     * row is the opt-in — which is why the recency rule is stated in the open rather than hidden in
     * an ORDER BY.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, recipient_pseudonym_ref: string,
     *     channel: string, consent_state: string, lawful_basis: string,
     *     basis_reference_safe?: ?string, recorded_at?: ?string
     * } $input
     */
    public function record(array $input): Outcome
    {
        // Scope integrity BEFORE any write, so a foreign workspace produces a named refusal rather
        // than an opaque foreign-key failure.
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

        $entryId = Ids::new();
        $this->db->insert('consent_registry', [
            'consent_registry_entry_id' => $entryId,
            'schema_version' => '1',
            'tenant_id' => $input['tenant_id'],
            'workspace_id' => $input['workspace_id'],
            'recipient_pseudonym_ref' => $input['recipient_pseudonym_ref'],
            'channel' => $input['channel'],
            'consent_state' => $input['consent_state'],
            'lawful_basis' => $input['lawful_basis'],
            'basis_reference_safe' => $input['basis_reference_safe'] ?? null,
            'recorded_at' => $input['recorded_at'] ?? $this->clock->now(),
        ]);

        return Outcome::ok(['consent_registry_entry_id' => $entryId]);
    }

    /**
     * The EFFECTIVE consent: the latest row for this recipient and channel.
     *
     * The id tiebreak makes "latest wins" deterministic when two rows share a `recorded_at`.
     * Without it, two rows recorded in the same instant would resolve by whatever order the engine
     * happened to return — and a withdrawal could lose to the opt-in it was meant to supersede.
     *
     * @param array{tenant_id: string, workspace_id: string, recipient_pseudonym_ref: string, channel: string} $input
     * @return array<string, mixed>|null null when no consent has EVER been recorded
     */
    public function effective(array $input): ?array
    {
        return $this->db->one(
            'SELECT consent_registry_entry_id, recipient_pseudonym_ref, channel, consent_state,
                    lawful_basis, basis_reference_safe, recorded_at
             FROM consent_registry
             WHERE tenant_id = ? AND workspace_id = ? AND recipient_pseudonym_ref = ? AND channel = ?
             ORDER BY recorded_at DESC, consent_registry_entry_id DESC LIMIT 1',
            [
                $input['tenant_id'],
                $input['workspace_id'],
                $input['recipient_pseudonym_ref'],
                $input['channel'],
            ]
        );
    }

    /**
     * The pre-send cross-check.
     *
     * @param array{tenant_id: string, workspace_id: string, recipient_pseudonym_ref: string, channel: string} $input
     */
    public function evaluateForSend(array $input): Outcome
    {
        $effective = $this->effective($input);
        if ($effective === null) {
            // SILENCE IS NOT CONSENT. An absent record is a block, not an unknown to resolve later.
            return Outcome::refuse('NO_CONSENT_ON_RECORD', [], 'POLICY', 'S5', 'BLOCK_RELEASE');
        }

        $state = (string) $effective['consent_state'];
        $basis = (string) $effective['lawful_basis'];

        // Each state gets its OWN reason code. A withdrawal and an opt-out are different acts by
        // the recipient, and collapsing them would lose which one they performed.
        if ($state === 'WITHDRAWN') {
            return Outcome::refuse('CONSENT_WITHDRAWN', [], 'POLICY', 'S5', 'BLOCK_RELEASE');
        }
        if ($state === 'OPTED_OUT') {
            return Outcome::refuse('CONSENT_OPTED_OUT', [], 'POLICY', 'S5', 'BLOCK_RELEASE');
        }
        // Two different ways of having no basis: the state says so, or the basis itself is NONE.
        if ($state === 'NO_BASIS' || $basis === 'NONE') {
            return Outcome::refuse('NO_LAWFUL_BASIS', [
                'consent_state' => $state,
                'lawful_basis' => $basis,
            ], 'POLICY', 'S5', 'BLOCK_RELEASE');
        }

        return Outcome::ok([
            'decision' => 'PERMITTED',
            'consent_registry_entry_id' => (string) $effective['consent_registry_entry_id'],
            'consent_state' => $state,
            'lawful_basis' => $basis,
        ]);
    }
}
