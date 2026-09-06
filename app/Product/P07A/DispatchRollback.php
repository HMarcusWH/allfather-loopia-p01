<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P07A;

/**
 * Internal control-flow signal used to roll the P07-A dispatch unit back.
 *
 * `Database::transaction()` COMMITS whatever the callback returns, so a refusal RETURNED after the
 * authorization has been consumed would leave the consume durable with no outcome recorded — the
 * stranded CONSUMED state upstream's `db.exec("ROLLBACK"); return REJECTED` exists to prevent.
 * Throwing rolls the whole unit back; `SyntheticDelivery::dispatch()` catches this and converts it
 * to the governed refusal.
 *
 * It carries a reason code and nothing else. It is not a capability, and it is deliberately NOT
 * used for the consume's own EXPIRED / REVOKED transitions, which are legitimate persisted status
 * changes that upstream commits.
 */
final class DispatchRollback extends \RuntimeException
{
    /** @param array<string, mixed> $detail */
    public function __construct(public readonly string $reasonCode, public readonly array $detail = [])
    {
        parent::__construct($reasonCode);
    }
}
