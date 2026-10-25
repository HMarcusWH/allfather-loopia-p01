<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http\ReadAdapters;

use Allfather\Loopia\Core\Ids;

/**
 * LI-16 OFFLINE projection adapter over P01 ObservationHistory::listSnapshots.
 *
 * NOT registered in an HTTP route. U01/U02/U09 owner contracts still gate an
 * operator/browser history surface. The supplied callable MUST delegate to the
 * existing owner read (including both M02 permissions, entitlement, current
 * M05/source-card rights and scoped cursor checks), rather than querying DB.
 *
 * The caller's session is the only source of tenant/workspace/actor identity.
 * No URLs, raw evidence, provider text, cost, model candidates or rights-changing
 * fields are transferred to this closed metadata contract.
 */
final class CompetitorHistoryAdapter
{
    public const DEFAULT_PAGE_SIZE = 20;
    public const MAX_PAGE_SIZE = 50;
    private const STATES = [
        'BASELINE', 'STRUCTURALLY_UNCHANGED', 'STRUCTURAL_CHANGE_RECORDED',
        'MULTIPLE_COMPARISONS', 'COMPARISON_UNAVAILABLE',
    ];
    private const SIGNIFICANCE = ['NONE', 'MINOR', 'MATERIAL', 'UNKNOWN'];

    /**
     * @param \Closure(array<string,string>,string,int,?string):array<string,mixed> $historyRead
     */
    public function __construct(private readonly \Closure $historyRead) {}

    /**
     * No GET route is wired to this method. Future HTTP activation is a separate
     * owner-reviewed change, NOT an implication of adding this class.
     *
     * @param array<string,mixed>|null $session
     * @return array<string,mixed>
     */
    public function history(?array $session, string $competitorId, int $pageSize = self::DEFAULT_PAGE_SIZE, ?string $beforeSnapshotId = null): array
    {
        if ($session === null
            || !self::id($session['tenant_id'] ?? null)
            || !self::id($session['active_workspace_id'] ?? null)
            || !self::id($session['operator_id'] ?? null)) {
            return ['decision'=>'NO_ACTIVE_WORKSPACE', 'reason_codes'=>['NO_ACTIVE_WORKSPACE']];
        }
        if (!Ids::isValid($competitorId)
            || $pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE
            || ($beforeSnapshotId !== null && !Ids::isValid($beforeSnapshotId))) {
            return ['decision'=>'NOT_FOUND', 'required_permission'=>'evidence.read', 'reason_codes'=>['HISTORY_SELECTOR_INVALID']];
        }

        $scope = [
            'tenant_id'=>(string) $session['tenant_id'],
            'workspace_id'=>(string) $session['active_workspace_id'],
            'actor_type'=>'OPERATOR',
            'actor_id'=>(string) $session['operator_id'],
        ];
        try {
            // The owner rechecks source rights on each page. No result or
            // authorization is cached across cursor navigation.
            $result = ($this->historyRead)($scope, $competitorId, $pageSize, $beforeSnapshotId);
        } catch (\Throwable) {
            return self::unavailable();
        }
        if (!is_array($result)) {
            return self::unavailable();
        }
        $decision = $result['decision'] ?? null;
        if ($decision === 'DENIED') {
            return [
                'decision'=>'DENIED', 'required_permission'=>'evidence.read',
                'reason_codes'=>self::reasonCodes($result, 'HISTORY_ACCESS_DENIED'),
            ];
        }
        if ($decision === 'NOT_FOUND') {
            return [
                'decision'=>'NOT_FOUND', 'required_permission'=>'evidence.read',
                'reason_codes'=>self::reasonCodes($result, 'HISTORY_NOT_FOUND'),
            ];
        }
        if ($decision !== 'VISIBLE' || ($result['required_permission'] ?? null) !== 'evidence.read') {
            return self::unavailable();
        }

        $d = $result['data'] ?? null;
        if (!is_array($d)
            || ($d['read_only'] ?? null) !== true
            || ($d['source_rights'] ?? null) !== 'CURRENT_ELIGIBILITY_CHECKED'
            || ($d['business_materiality_available'] ?? null) !== false
            || ($d['page_size'] ?? null) !== $pageSize
            || !is_array($d['competitor'] ?? null)
            || ($d['competitor']['competitor_id'] ?? null) !== $competitorId
            || !is_string($d['competitor']['display_name'] ?? null)
            || !mb_check_encoding($d['competitor']['display_name'], 'UTF-8')
            || mb_strlen($d['competitor']['display_name']) > 160
            || !in_array($d['competitor']['status'] ?? null, ['ACTIVE','PAUSED'], true)
            || !is_array($d['rows'] ?? null) || !array_is_list($d['rows'])
            || count($d['rows']) > $pageSize) {
            return self::unavailable();
        }
        $next = $d['next_cursor'] ?? null;
        if ($next !== null && (!self::id($next) || $d['rows'] === [])) {
            return self::unavailable();
        }

        $safeRows = [];
        foreach ($d['rows'] as $row) {
            if (!is_array($row)
                || !self::id($row['source_snapshot_id'] ?? null)
                || !self::id($row['monitored_source_id'] ?? null)
                || !self::sourceKind($row['source_kind'] ?? null)
                || !is_int($row['snapshot_sequence'] ?? null)
                || $row['snapshot_sequence'] < 1
                || !self::timestamp($row['captured_at'] ?? null)
                || !in_array($row['coverage_state'] ?? null, self::STATES, true)
                || ($row['business_materiality'] ?? null) !== 'NOT_ASSESSED'
                || ($row['observation_assessment'] ?? null) !== 'NOT_IMPLEMENTED'
                || ($row['release_status'] ?? null) !== 'NOT_INFERRED'
                || !is_array($row['comparisons'] ?? null)
                || !array_is_list($row['comparisons'])
                || count($row['comparisons']) > 16) {
                return self::unavailable();
            }
            $comparisons = [];
            foreach ($row['comparisons'] as $cmp) {
                if (!is_array($cmp)
                    || !self::id($cmp['source_diff_id'] ?? null)
                    || !in_array($cmp['structural_significance'] ?? null, self::SIGNIFICANCE, true)
                    || !self::timestamp($cmp['detected_at'] ?? null)) {
                    return self::unavailable();
                }
                $comparisons[] = [
                    'source_diff_id'=>$cmp['source_diff_id'],
                    'structural_significance'=>$cmp['structural_significance'],
                    'detected_at'=>$cmp['detected_at'],
                ];
            }
            $safeRows[] = [
                'source_snapshot_id'=>$row['source_snapshot_id'],
                'monitored_source_id'=>$row['monitored_source_id'],
                'source_kind'=>$row['source_kind'],
                'snapshot_sequence'=>$row['snapshot_sequence'],
                'captured_at'=>$row['captured_at'],
                'coverage_state'=>$row['coverage_state'],
                'comparisons'=>$comparisons,
                'business_materiality'=>'NOT_ASSESSED',
                'observation_assessment'=>'NOT_IMPLEMENTED',
                'release_status'=>'NOT_INFERRED',
            ];
        }
        if ($next !== null && $next !== $safeRows[count($safeRows)-1]['source_snapshot_id']) {
            return self::unavailable();
        }

        return [
            'decision'=>'VISIBLE', 'required_permission'=>'evidence.read',
            'reason_codes'=>['GRANTED'],
            'data'=>[
                'competitor'=>[
                    'competitor_id'=>$competitorId,
                    'display_name'=>$d['competitor']['display_name'],
                    'status'=>$d['competitor']['status'],
                ],
                'rows'=>$safeRows,
                'next_cursor'=>$next,
                'page_size'=>$pageSize,
                'source_rights'=>'CURRENT_ELIGIBILITY_CHECKED',
                'business_materiality_available'=>false,
                'read_only'=>true,
                'projection_version'=>'li16.competitor-history-metadata.v1',
            ],
        ];
    }

    private static function id(mixed $value): bool
    {
        return is_string($value) && Ids::isValid($value);
    }

    private static function sourceKind(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $value) === 1;
    }

    private static function timestamp(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 48
            && preg_match('/^\d{4}-\d{2}-\d{2}[0-9TtZz:.+\- ]*$/D', $value) === 1;
    }

    /** @param array<string,mixed> $owner @return list<string> */
    private static function reasonCodes(array $owner, string $fallback): array
    {
        $codes = $owner['reason_codes'] ?? null;
        if (!is_array($codes) || !array_is_list($codes) || $codes === []) {
            return [$fallback];
        }
        $valid = [];
        foreach (array_slice($codes, 0, 3) as $code) {
            if (!is_string($code) || preg_match('/^[A-Z][A-Z0-9_]{0,79}$/D', $code) !== 1) {
                return [$fallback]; // never forward a raw error, source or model string
            }
            $valid[] = $code;
        }
        return $valid;
    }

    /** @return array<string,mixed> */
    private static function unavailable(): array
    {
        return ['decision'=>'UNAVAILABLE', 'reason_codes'=>['HISTORY_METADATA_UNAVAILABLE']];
    }
}
