<?php

declare(strict_types=1);

namespace Allfather\Loopia\Core;

/**
 * The discriminated outcome of any protected operation.
 *
 * Ported semantics: `packages/allfather-substrate-core/src/error-model.ts` (ERR-DEC-001).
 *
 * The load-bearing distinction is between a GOVERNED REFUSAL — correct control behavior, e.g.
 * denying an unauthorized request — and a SYSTEM FAILURE. They must never collapse into a generic
 * "failed", because a refusal is a SUCCESS of the control plane while a failure is a FAULT in it.
 *
 * Upstream models this as a TypeScript discriminated union. PHP has no union types over shapes,
 * so the discriminant is an explicit `kind` and the accessors fail loudly when read on the wrong
 * variant — that is deliberate: silently returning null for `reasonCode()` on an OK outcome is how
 * a refusal becomes invisible.
 *
 * `reasonCodes` is the conformance surface and is preserved verbatim into audit records. The UI
 * may render human-readable text; the record carries the canonical code.
 */
final class Outcome implements \JsonSerializable
{
    public const OK = 'OK';
    public const GOVERNED_REFUSAL = 'GOVERNED_REFUSAL';
    public const SYSTEM_FAILURE = 'SYSTEM_FAILURE';

    /**
     * @param list<string>         $reasonCodes
     * @param array<string, mixed> $detail
     */
    private function __construct(
        public readonly string $kind,
        public readonly mixed $value = null,
        public readonly ?string $category = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $severity = null,
        public readonly array $reasonCodes = [],
        public readonly ?string $responseMode = null,
        public readonly array $detail = [],
    ) {
    }

    public static function ok(mixed $value = null): self
    {
        return new self(self::OK, $value);
    }

    /**
     * The ordinary governed refusal: a named reason code, fail-closed.
     *
     * @param array<string, mixed> $detail
     */
    public static function refuse(
        string $reasonCode,
        array $detail = [],
        string $category = 'POLICY',
        string $severity = 'S2',
        string $responseMode = 'DENY_AND_LOG',
    ): self {
        return new self(
            kind: self::GOVERNED_REFUSAL,
            category: $category,
            errorCode: $reasonCode,
            severity: $severity,
            reasonCodes: [$reasonCode],
            responseMode: $responseMode,
            detail: $detail,
        );
    }

    /**
     * A refusal carrying several reason codes (an arbiter verdict, a multi-rule failure).
     *
     * @param list<string>         $reasonCodes
     * @param array<string, mixed> $detail
     */
    public static function refuseAll(
        array $reasonCodes,
        array $detail = [],
        string $category = 'POLICY',
        string $severity = 'S2',
        string $responseMode = 'DENY_AND_LOG',
    ): self {
        if ($reasonCodes === []) {
            // An empty refusal is indistinguishable from "no reason given", which is exactly the
            // generic-exception failure mode this type exists to prevent.
            throw new \InvalidArgumentException('Outcome::refuseAll requires at least one reason code');
        }

        return new self(
            kind: self::GOVERNED_REFUSAL,
            category: $category,
            errorCode: $reasonCodes[0],
            severity: $severity,
            reasonCodes: array_values($reasonCodes),
            responseMode: $responseMode,
            detail: $detail,
        );
    }

    /**
     * Fail-closed posture (ERR-DEC-002): when a required precondition is unresolved, deny —
     * never proceed on absence.
     *
     * @param list<string> $reasonCodes
     */
    public static function failClosed(string $precondition, array $reasonCodes = []): self
    {
        Vocabulary::assertMemberOrThrow($precondition, Vocabulary::FAIL_CLOSED_PRECONDITION);

        return new self(
            kind: self::GOVERNED_REFUSAL,
            category: Vocabulary::FAIL_CLOSED_CATEGORY[$precondition],
            errorCode: "FAIL_CLOSED_{$precondition}",
            severity: 'S2',
            reasonCodes: array_values($reasonCodes),
            responseMode: 'DENY_AND_LOG',
        );
    }

    /**
     * A fault in the control plane, not a decision by it.
     *
     * @param array<string, mixed> $detail
     */
    public static function failure(
        string $errorCode,
        array $detail = [],
        string $category = 'INTERNAL',
        string $severity = 'S3',
        string $responseMode = 'OPEN_INCIDENT',
    ): self {
        return new self(
            kind: self::SYSTEM_FAILURE,
            category: $category,
            errorCode: $errorCode,
            severity: $severity,
            reasonCodes: [$errorCode],
            responseMode: $responseMode,
            detail: $detail,
        );
    }

    public function isOk(): bool
    {
        return $this->kind === self::OK;
    }

    public function isRefusal(): bool
    {
        return $this->kind === self::GOVERNED_REFUSAL;
    }

    public function isFailure(): bool
    {
        return $this->kind === self::SYSTEM_FAILURE;
    }

    /** The primary reason code. Reading this on an OK outcome is a programming error. */
    public function reasonCode(): string
    {
        if ($this->kind === self::OK) {
            throw new \LogicException('Outcome::reasonCode() read on an OK outcome');
        }

        return (string) $this->errorCode;
    }

    /** The value. Reading this on a non-OK outcome is a programming error. */
    public function value(): mixed
    {
        if ($this->kind !== self::OK) {
            throw new \LogicException(
                'Outcome::value() read on a ' . $this->kind . ' outcome (' . (string) $this->errorCode . ')'
            );
        }

        return $this->value;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        if ($this->kind === self::OK) {
            return ['kind' => self::OK, 'value' => $this->value];
        }

        return array_filter([
            'kind' => $this->kind,
            'category' => $this->category,
            'error_code' => $this->errorCode,
            'severity' => $this->severity,
            'reason_codes' => $this->reasonCodes,
            'response_mode' => $this->responseMode,
            'detail' => $this->detail === [] ? null : $this->detail,
        ], static fn ($v) => $v !== null);
    }
}
