<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M01;

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\Database;

/**
 * Nominal, runtime-unforgeable proof that M06 performed a persisted dispatch act.
 *
 * The defect this design replaces: M01 accepting a caller-controlled boolean
 * (`callerIsM06DispatchService: true`) as its authority proof. Any caller could assert it.
 *
 * Authority here comes from OBJECT IDENTITY in a module-private WeakMap plus exact correspondence
 * with a persisted `tool_dispatch_records` row — neither of which a caller can synthesize. The
 * token class is `final` with a private constructor, so the only way to obtain one is through
 * `mint()`; and even a token obtained some other way carries no binding, because the binding lives
 * BESIDE the object rather than inside it. Nothing about an admission is reconstructable from its
 * string form: it has no string form.
 *
 * PHP's `\WeakMap` is the same primitive the upstream design uses, so this is a faithful port of
 * the mechanism rather than an approximation of it.
 */
final class DispatchAdmission
{
    /** @var \WeakMap<AdmissionToken, array<string, mixed>>|null */
    private static ?\WeakMap $bindings = null;

    /** @var \WeakMap<AdmissionToken, true>|null */
    private static ?\WeakMap $consumed = null;

    /**
     * The execution statuses that CLOSE a dispatch act.
     *
     * UNKNOWN and CANCELLED close one exactly as SUCCEEDED does. Omitting them would make an
     * already-closed dispatch look open, and a second execution would then be admitted against it.
     *
     * @var list<string>
     */
    public const TERMINAL_EXECUTION_STATUSES = [
        'SUCCEEDED', 'FAILED', 'REFUSED_POST_DISPATCH', 'UNKNOWN', 'CANCELLED',
    ];

    /**
     * Mint an admission. Called ONLY by the M06 gateway, and only after the dispatch row exists.
     *
     * @param array{
     *     tool_request_id: string,
     *     tool_authorization_decision_id: string,
     *     tool_dispatch_id: string,
     *     governed_tool_id: string,
     *     governed_tool_operation_id: string,
     *     runtime_host_binding_id: string,
     *     runtime_host_tool_ref: string,
     *     action_class: string,
     *     dispatched_input_digest: string,
     *     tenant_id: string,
     *     workspace_id: string,
     *     correlation_id: string,
     *     authority_snapshot_digest: string
     * } $binding
     */
    public static function mint(Database $db, array $binding): AdmissionToken
    {
        $token = AdmissionToken::create();
        // The exact handle the dispatch was authorized against. An admission minted on one
        // database can never authorize a dispatch on another, even with identical row contents.
        self::bindings()[$token] = $binding + ['db' => $db];

        return $token;
    }

    /**
     * STEP 1 — prove the admission is genuine and its dispatch act is still open.
     *
     * READ-ONLY and side-effect free BY CONSTRUCTION, because the caller still has refusals left to
     * perform: command agreement and authority-digest agreement. An irreversible act must not
     * precede a check that can still refuse — claiming here would permanently burn a dispatch whose
     * command never matched.
     *
     * @return array{ok: true, binding: array<string, mixed>}|array{ok: false, reason: string}
     */
    public static function verify(Database $db, mixed $admission): array
    {
        if (!$admission instanceof AdmissionToken) {
            // Covers structural forgery: any other value, however shaped, has no membership.
            return ['ok' => false, 'reason' => 'M06_DISPATCH_ADMISSION_ABSENT'];
        }
        $bindings = self::bindings();
        if (!isset($bindings[$admission])) {
            return ['ok' => false, 'reason' => 'M06_DISPATCH_ADMISSION_NOT_GOVERNED'];
        }
        if (isset(self::consumed()[$admission])) {
            return ['ok' => false, 'reason' => 'M06_DISPATCH_ADMISSION_ALREADY_CONSUMED'];
        }
        $bound = $bindings[$admission];
        if ($bound['db'] !== $db) {
            return ['ok' => false, 'reason' => 'M06_DISPATCH_ADMISSION_FOREIGN_DATABASE'];
        }

        $row = $db->one(
            'SELECT tool_dispatch_id, tool_request_id, tool_authorization_decision_id,
                    runtime_host_binding_id, action_class, exact_dispatched_input_digest, status
             FROM tool_dispatch_records WHERE tool_dispatch_id = ?',
            [$bound['tool_dispatch_id']]
        );
        if ($row === null) {
            return ['ok' => false, 'reason' => 'M06_DISPATCH_ADMISSION_DISPATCH_ROW_MISSING'];
        }
        if ((string) $row['tool_request_id'] !== $bound['tool_request_id']
            || (string) $row['tool_authorization_decision_id'] !== $bound['tool_authorization_decision_id']
            || (string) ($row['runtime_host_binding_id'] ?? '') !== $bound['runtime_host_binding_id']
            || (string) $row['action_class'] !== $bound['action_class']
            || (string) $row['exact_dispatched_input_digest'] !== $bound['dispatched_input_digest']
            || (string) $row['status'] !== 'DISPATCHED'
        ) {
            return ['ok' => false, 'reason' => 'M06_DISPATCH_ADMISSION_DISPATCH_ROW_MISMATCH'];
        }

        // One dispatch act may produce at most one terminal M01 execution. The append-only trail is
        // the authority, so a replayed admission — or a second admission minted for the same row —
        // is refused even across process boundaries.
        $placeholders = implode(', ', array_fill(0, count(self::TERMINAL_EXECUTION_STATUSES), '?'));
        $executed = (int) $db->scalar(
            "SELECT COUNT(*) FROM runtime_host_execution_refs
             WHERE tool_dispatch_id = ? AND status IN ({$placeholders})",
            [$bound['tool_dispatch_id'], ...self::TERMINAL_EXECUTION_STATUSES]
        );
        if ($executed > 0) {
            return ['ok' => false, 'reason' => 'M06_DISPATCH_ADMISSION_ALREADY_EXECUTED'];
        }

        unset($bound['db']);

        return ['ok' => true, 'binding' => $bound];
    }

    /**
     * STEP 2 and the LAST pre-callback authority act: take the irreversible durable claim and burn
     * the admission token. Every refusable check must already have passed when this is called.
     *
     * The preceding existing-row check is read-then-act: between that SELECT and the callback, a
     * concurrent process holding its own admission for the same dispatch row would see the same
     * empty result and run the adapter too. The terminal execution row is written only AFTER the
     * callback returns, so it cannot be the exclusion. This claim is a single INSERT whose primary
     * key conflict is resolved inside the database, before either caller proceeds: exactly one
     * INSERT succeeds, and the loser refuses without executing anything.
     *
     * @return array{ok: true}|array{ok: false, reason: string}
     */
    public static function claimExecution(
        Database $db,
        mixed $admission,
        string $toolDispatchId,
        string $claimedAt,
    ): array {
        $claim = self::claim($db, $toolDispatchId, $claimedAt);
        if ($claim !== 'CLAIMED') {
            return [
                'ok' => false,
                'reason' => $claim === 'ALREADY_CLAIMED'
                    ? 'M06_DISPATCH_ADMISSION_EXECUTION_ALREADY_CLAIMED'
                    : 'M06_DISPATCH_ADMISSION_EXECUTION_CLAIM_UNAVAILABLE',
            ];
        }
        if ($admission instanceof AdmissionToken) {
            self::consumed()[$admission] = true;
        }

        return ['ok' => true];
    }

    /**
     * CLAIMED exactly once per dispatch act, for the whole life of the database.
     *
     * A key conflict is the governed answer "someone else already holds this execution". Any other
     * failure — a missing table, a dropped connection — means the exclusion cannot be ESTABLISHED,
     * and an exclusion that cannot be established must not be assumed: it refuses too, rather than
     * throwing out of a boundary that owes its caller a governed outcome.
     */
    private static function claim(Database $db, string $toolDispatchId, string $claimedAt): string
    {
        try {
            $db->insert('governed_execution_claims', [
                'tool_dispatch_id' => $toolDispatchId,
                'schema_version' => '1',
                'claim_id' => Ids::new(),
                'claimed_at' => $claimedAt,
            ]);

            return 'CLAIMED';
        } catch (\PDOException $e) {
            // SQLSTATE 23000 is an integrity violation, which for this single-column primary key
            // means the claim is already held. Anything else means the exclusion could not be
            // established, and an exclusion that cannot be established is not assumed.
            return $e->getCode() === '23000' ? 'ALREADY_CLAIMED' : 'UNAVAILABLE';
        }
    }

    /** @return \WeakMap<AdmissionToken, array<string, mixed>> */
    private static function bindings(): \WeakMap
    {
        return self::$bindings ??= new \WeakMap();
    }

    /** @return \WeakMap<AdmissionToken, true> */
    private static function consumed(): \WeakMap
    {
        return self::$consumed ??= new \WeakMap();
    }
}
