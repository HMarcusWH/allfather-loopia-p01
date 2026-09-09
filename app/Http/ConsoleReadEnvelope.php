<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http;

/**
 * The single browser-read envelope.
 *
 * It normalizes transport semantics only. Owner decisions, permissions and reason codes are passed
 * through rather than re-decided here.
 */
final class ConsoleReadEnvelope
{
    /** @param array<string, mixed> $ownerResult */
    public static function owner(string $source, array $ownerResult, string $dataKey, ?string $asOf = null): Response
    {
        $decision = (string) ($ownerResult['decision'] ?? 'DENIED');
        $reasons = array_values(array_map('strval', (array) ($ownerResult['reason_codes'] ?? [])));
        $requiredPermission = $ownerResult['required_permission'] ?? null;

        if ($decision !== 'VISIBLE') {
            return Response::json(self::payload(
                $source,
                $asOf,
                'DENIED',
                $requiredPermission === null ? null : (string) $requiredPermission,
                $reasons,
                'UNAVAILABLE',
                'UNKNOWN',
                $decision,
                $reasons,
                null,
            ), 403);
        }

        $data = $ownerResult[$dataKey] ?? [];
        $empty = is_array($data) && $data === [];

        return Response::json(self::payload(
            $source,
            $asOf,
            'VISIBLE',
            $requiredPermission === null ? null : (string) $requiredPermission,
            $reasons,
            $empty ? 'EMPTY' : 'READY',
            'CURRENT',
            $decision,
            $reasons,
            $data,
        ));
    }

    /** @param array<string, mixed> $read */
    public static function adapter(string $source, array $read): Response
    {
        $decision = (string) ($read['decision'] ?? 'UNAVAILABLE');
        if ($decision === 'NO_ACTIVE_WORKSPACE') {
            return self::noActiveWorkspace($source);
        }
        if ($decision === 'DENIED') {
            $reasons = array_values(array_map('strval', (array) ($read['reason_codes'] ?? [])));
            return Response::json(self::payload(
                $source,
                $read['as_of'] ?? null,
                'DENIED',
                isset($read['required_permission']) ? (string) $read['required_permission'] : null,
                $reasons,
                'UNAVAILABLE',
                'UNKNOWN',
                'DENIED',
                $reasons,
                null,
            ), 403);
        }
        if ($decision !== 'VISIBLE') {
            return self::unavailable($source);
        }

        $data = $read['data'] ?? null;
        $empty = $data === null || $data === [] || (is_array($data) && isset($data['rows']) && $data['rows'] === []);
        $reasons = array_values(array_map('strval', (array) ($read['reason_codes'] ?? ['GRANTED'])));

        return Response::json(self::payload(
            $source,
            isset($read['as_of']) ? (string) $read['as_of'] : null,
            'VISIBLE',
            isset($read['required_permission']) ? (string) $read['required_permission'] : null,
            $reasons,
            $empty ? 'EMPTY' : 'READY',
            'CURRENT',
            'VISIBLE',
            $reasons,
            $data,
        ));
    }

    public static function noActiveWorkspace(string $source): Response
    {
        return Response::json(self::payload(
            $source,
            null,
            'VISIBLE',
            null,
            ['NO_ACTIVE_WORKSPACE'],
            'UNAVAILABLE',
            'UNKNOWN',
            'NO_ACTIVE_WORKSPACE',
            ['NO_ACTIVE_WORKSPACE'],
            null,
        ), 409);
    }

    public static function unavailable(string $source): Response
    {
        return Response::json(self::payload(
            $source,
            null,
            'VISIBLE',
            null,
            ['READ_UNAVAILABLE'],
            'UNAVAILABLE',
            'ERROR',
            'UNAVAILABLE',
            ['READ_UNAVAILABLE'],
            null,
        ), 503);
    }

    /** @return array<string, mixed> */
    private static function payload(
        string $source,
        ?string $asOf,
        string $accessDecision,
        ?string $requiredPermission,
        array $accessReasons,
        string $projectionState,
        string $staleStatus,
        ?string $ownerState,
        array $reasons,
        mixed $data,
    ): array {
        return [
            'source' => $source,
            'as_of' => $asOf,
            'access' => [
                'decision' => $accessDecision,
                'required_permission' => $requiredPermission,
                'reason_codes' => $accessReasons,
            ],
            'projection' => [
                'state' => $projectionState,
                'stale_status' => $staleStatus,
            ],
            'owner_state' => $ownerState,
            'reason_codes' => $reasons,
            'data' => $data,
        ];
    }
}
