<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M10;

/**
 * Internal control-flow signal used to roll the M10 release unit back.
 *
 * `Database::transaction()` COMMITS whatever the callback returns, so a refusal RETURNED from
 * inside the release transaction would leave the already-appended
 * `INTERNAL_INFORMATIONAL_RELEASE` record durable while the proposal stayed SUBMITTED — the exact
 * split state upstream's `db.exec("ROLLBACK"); return REJECTED` exists to prevent. Throwing rolls
 * the whole unit back; `appendInternalReleaseDecisionRecord` catches this and converts it to the
 * governed refusal.
 *
 * It carries a reason code and nothing else. It is not a capability: catching or constructing it
 * grants no caller the ability to mint a release record.
 */
final class ReleaseRollback extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
