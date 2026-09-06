<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P02;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M08\Arbiter;

/**
 * The M08 verdict on an entity-twin state change.
 *
 * This module does not judge. It routes the submitted proposal to the certified arbiter over the
 * `P02_TWIN_STATE_ADMISSION` policy set — fifteen rules that DERIVE every fact from the twin tables
 * rather than reading a caller's declaration — and then does exactly what the verdict says:
 *
 *   ALLOW_INFORMATIONAL_RELEASE   → VERDICT_ISSUED, bound to the verdict. Release becomes possible.
 *   BLOCK / QUARANTINE            → BLOCKED. The change is over; it is not a draft to revise.
 *   HOLD / REQUIRE_*              → still PENDING_VERDICT. Those verdicts are asking for something.
 *
 * ONLY the allow verdict advances, and it advances to VERDICT_ISSUED rather than to anything
 * released. Whether a released snapshot exists is {@see TwinRelease}'s question and M10's authority.
 *
 * BLOCK IS TERMINAL BY DESIGN. Several of the fifteen rules block content that was never available
 * to release — individual profiling, employee scoring, an asserted buyer intent. Leaving those
 * pending would invite the caller to try again with softer wording, which is precisely the move the
 * rules exist to refuse.
 *
 * NO EXTERNAL EFFECT. An ENTITY_TWIN_STATE_CHANGE never routes to a dispatch, in this module or
 * anywhere downstream of it.
 */
final class TwinAdmission
{
    /**
     * Verdicts that TERMINATE the state change.
     *
     * @var list<string>
     */
    private const TERMINATING_VERDICTS = ['BLOCK', 'QUARANTINE'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Arbiter $arbiter,
        private readonly TwinStateChange $stateChange,
    ) {
    }

    /**
     * @param array{
     *     tenant_id: string, workspace_id: string, entity_twin_state_change_proposal_id: string,
     *     arbiter_policy_set_id: string, correlation_id?: ?string
     * } $input
     */
    public function admit(array $input): Outcome
    {
        $change = $this->db->one(
            'SELECT c.tenant_id, c.workspace_id, c.change_status, c.proposal_id, pe.proposal_type
             FROM entity_twin_state_change_proposals c
             LEFT JOIN proposal_envelopes pe ON pe.proposal_id = c.proposal_id
             WHERE c.entity_twin_state_change_proposal_id = ?',
            [$input['entity_twin_state_change_proposal_id']]
        );
        if ($change === null) {
            return Outcome::refuse('STATE_CHANGE_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $change['tenant_id'] !== $input['tenant_id']
            || (string) $change['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('STATE_CHANGE_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if ((string) $change['change_status'] !== 'PENDING_VERDICT' || $change['proposal_id'] === null) {
            return Outcome::refuse('STATE_CHANGE_NOT_PENDING_VERDICT', [
                'change_status' => (string) $change['change_status'],
            ], 'POLICY');
        }
        // The binding must point at a proposal of the RIGHT TYPE. A state change bound to some other
        // proposal would be judged by rules written for something else.
        if ((string) $change['proposal_type'] !== 'ENTITY_TWIN_STATE_CHANGE') {
            return Outcome::refuse('NOT_A_TWIN_STATE_CHANGE_PROPOSAL', [
                'proposal_type' => (string) $change['proposal_type'],
            ], 'POLICY', 'S4');
        }

        return $this->db->transaction(function (Database $tx) use ($input, $change): Outcome {
            $decision = $this->arbiter->evaluateProposal(
                (string) $change['proposal_id'],
                $input['arbiter_policy_set_id'],
                $input['correlation_id'] ?? null,
            );
            if ($decision->isRefusal()) {
                return Outcome::refuse('EVALUATION_REJECTED', [
                    'detail' => $decision->reasonCode(),
                ], 'POLICY');
            }

            $value = $decision->value();
            $verdict = (string) $value['verdict'];
            $now = $this->clock->now();

            if ($verdict === 'ALLOW_INFORMATIONAL_RELEASE') {
                $tx->run(
                    "UPDATE entity_twin_state_change_proposals
                     SET change_status = 'VERDICT_ISSUED', arbiter_verdict_id = ?, updated_at = ?
                     WHERE entity_twin_state_change_proposal_id = ?",
                    [
                        (string) $value['arbiter_verdict_id'], $now,
                        $input['entity_twin_state_change_proposal_id'],
                    ]
                );
                $this->stateChange->appendTransition(
                    $tx,
                    $input['entity_twin_state_change_proposal_id'],
                    'PENDING_VERDICT',
                    'VERDICT_ISSUED',
                    'ADMITTED_BY_VERDICT',
                    $now,
                );

                return Outcome::ok([
                    'outcome' => 'ADMITTED',
                    'verdict' => $verdict,
                    'arbiter_verdict_id' => (string) $value['arbiter_verdict_id'],
                    'permitted_next_stage' => (string) $value['permitted_next_stage'],
                ]);
            }

            $terminates = in_array($verdict, self::TERMINATING_VERDICTS, true);
            if ($terminates) {
                $tx->run(
                    "UPDATE entity_twin_state_change_proposals
                     SET change_status = 'BLOCKED', updated_at = ?
                     WHERE entity_twin_state_change_proposal_id = ?",
                    [$now, $input['entity_twin_state_change_proposal_id']]
                );
                $this->stateChange->appendTransition(
                    $tx,
                    $input['entity_twin_state_change_proposal_id'],
                    'PENDING_VERDICT',
                    'BLOCKED',
                    $verdict,
                    $now,
                );
            }

            // A refusal is still a RECORDED outcome — the verdict exists, and the caller is told
            // whether the change is over or merely waiting.
            return Outcome::refuse('TWIN_STATE_CHANGE_NOT_ADMITTED', [
                'verdict' => $verdict,
                'arbiter_verdict_id' => (string) $value['arbiter_verdict_id'],
                'reason_codes' => array_values($value['reason_codes']),
                'state_change_blocked' => $terminates,
            ], 'POLICY', (string) $value['severity']);
        });
    }
}
