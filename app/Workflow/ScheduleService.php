<?php
declare(strict_types=1);
namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Digest,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P01\Capture;
use Allfather\Loopia\Substrate\M02\AccessGuard;

/**
 * Recurring observation schedules (completion plan P07): immutable versions, owner admission,
 * append-only lifecycle, and the delegation check every scheduled act re-reads.
 *
 * A SCHEDULE IS DELEGATION, NOT AUTHORITY OF ITS OWN. It lets the scheduler's SERVICE principal start
 * runs of one frozen NATIVE mission configuration on a cadence, under one SCHEDULED_OBSERVATION
 * execution grant that an owner has already admitted (capture and compare only, zero model attempts —
 * ExecutionGrantStore::CEILINGS). The schedule narrows that grant further: a cadence no faster than
 * hourly, a window inside the grant's, and an occurrence cap no larger than the grant's capture
 * budget. New occurrences are new RUNS of the same mission; they never refill a mission's model
 * budget, which stays with the MANUAL_MONITOR grant a human uses for the model stages.
 *
 * Proposing writes a definition and nothing else. Admission is an event that cites a reviewed owner
 * decision listing this version's exact digest under `authorizes.monitor_schedules`; suspension and
 * revocation are further events. The definition row never changes, and a changed cadence, window,
 * grant or budget is a successor version with its own digest and its own admission.
 *
 * Observation mode is OBSERVE_AND_QUEUE and nothing else: an occurrence captures, compares, and queues
 * the run at the model-request inspection boundary for a human. Unattended model inference would need
 * a distinct owner-approved disclosure and inspection policy; no field here can express one.
 */
final class ScheduleService
{
    public const MIN_CADENCE_SECONDS = 3600;
    public const MAX_CADENCE_SECONDS = 2678400;
    public const MAX_OCCURRENCES = 500;
    public const CATCH_UP_POLICY = 'LATEST_ONLY';
    public const OBSERVATION_MODE = 'OBSERVE_AND_QUEUE';
    /** What the scheduler's service principal must hold in the workspace — and its delegator too. */
    public const SERVICE_PERMISSIONS = ['mission.read', 'mission.transition', 'evidence.read', 'tool.invoke'];
    private const INPUT_KEYS = ['monitor_schedule_id', 'mission_id', 'execution_grant_id', 'service_identity_id', 'cadence_seconds', 'max_occurrences', 'valid_from', 'valid_until'];
    /** Statuses that still occupy a monitored source; REVOKED and EXPIRED release it. */
    private const LIVE_STATUSES = ['PROPOSED', 'ADMITTED', 'SUSPENDED', 'NOT_YET_VALID'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly ExecutionGrantStore $grants,
        private readonly OwnerDecisionRegistry $decisions,
        private readonly MissionConfiguration $configuration,
    ) {}

    /** The idempotency key of the one run an occurrence may start. */
    public static function runKey(string $occurrenceId): string
    {
        return 'schedule-occurrence:' . $occurrenceId;
    }

    /** Validate and normalize a definition, and report the digest an owner decision must list. */
    public function plan(array $scope, array $input): Outcome
    {
        if (!$this->may($scope, ['tenant.administer', 'mission.activate'])) {
            return Outcome::refuse('SCHEDULE_ADMINISTRATION_DENIED');
        }
        $normalized = $this->normalize($scope, $input);
        if (!$normalized->isOk()) {
            return $normalized;
        }
        $d = $normalized->value();

        return Outcome::ok(['definition' => $d, 'schedule_digest' => self::digest($scope['tenant_id'], $scope['workspace_id'], $d), 'grants_execution' => false]);
    }

    public function propose(array $scope, array $input): Outcome
    {
        $plan = $this->plan($scope, $input);
        if (!$plan->isOk()) {
            return $plan;
        }
        ['definition' => $d, 'schedule_digest' => $digest] = $plan->value();
        $existing = $this->db->one('SELECT monitor_schedule_version_id FROM monitor_schedules WHERE schedule_digest=?', [$digest]);
        if ($existing !== null) {
            return Outcome::ok(['monitor_schedule_version_id' => $existing['monitor_schedule_version_id'], 'schedule_digest' => $digest, 'status' => $this->status($existing['monitor_schedule_version_id']), 'grants_execution' => false]);
        }
        $id = Ids::new();
        try {
            $this->db->insert('monitor_schedules', [
                'monitor_schedule_version_id' => $id, 'monitor_schedule_id' => $d['monitor_schedule_id'], 'schedule_version' => $d['schedule_version'],
                'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'mission_id' => $d['mission_id'],
                'definition_digest' => $d['definition_digest'], 'execution_grant_id' => $d['execution_grant_id'],
                'service_identity_id' => $d['service_identity_id'], 'delegating_operator_id' => $d['delegating_operator_id'],
                'monitored_source_id' => $d['monitored_source_id'], 'cadence_seconds' => $d['cadence_seconds'],
                'catch_up_policy' => $d['catch_up_policy'], 'observation_mode' => $d['observation_mode'],
                'max_occurrences' => $d['max_occurrences'], 'valid_from' => $d['valid_from'], 'valid_until' => $d['valid_until'],
                'definition_json' => json_encode($d, JSON_THROW_ON_ERROR), 'schedule_digest' => $digest,
                'proposed_by_operator_id' => $scope['operator_id'], 'created_at' => $this->clock->now(),
            ]);
        } catch (\PDOException $error) {
            return Database::classify($error, 'SCHEDULE_ALREADY_PROPOSED');
        }

        return Outcome::ok(['monitor_schedule_version_id' => $id, 'monitor_schedule_id' => $d['monitor_schedule_id'], 'schedule_version' => $d['schedule_version'], 'schedule_digest' => $digest, 'status' => 'PROPOSED', 'grants_execution' => false]);
    }

    /** Admission is an owner decision listing this version's exact digest — never a flag an operator sets. */
    public function admit(array $scope, string $versionId, string $decisionRef, string $decisionSha256): Outcome
    {
        if (!$this->may($scope, ['tenant.administer', 'mission.activate'])) {
            return Outcome::refuse('SCHEDULE_ADMINISTRATION_DENIED');
        }
        $version = $this->find($scope['tenant_id'], $scope['workspace_id'], $versionId);
        if ($version === null) {
            return Outcome::refuse('SCHEDULE_NOT_FOUND');
        }
        $decision = $this->decisions->resolve($decisionRef, $decisionSha256);
        if (!$decision->isOk()) {
            return $decision;
        }
        if (!OwnerDecisionRegistry::authorizesSchedule($decision->value(), $version['tenant_id'], $version['workspace_id'], $version['schedule_digest'])) {
            return Outcome::refuse('OWNER_DECISION_DOES_NOT_AUTHORIZE_SCHEDULE');
        }

        try {
            return $this->db->transaction(function (Database $tx) use ($scope, $version, $decisionRef, $decisionSha256): Outcome {
                // Every version of the series, locked in a fixed order, so two admissions of
                // different versions cannot both see "no other version is admitted".
                $tx->all('SELECT monitor_schedule_version_id FROM monitor_schedules WHERE monitor_schedule_id=? ORDER BY schedule_version FOR UPDATE', [$version['monitor_schedule_id']]);
                $status = $this->status($version['monitor_schedule_version_id']);
                if (!in_array($status, ['PROPOSED', 'SUSPENDED'], true)) {
                    return Outcome::refuse('SCHEDULE_TRANSITION_INVALID', ['status' => $status]);
                }
                foreach ($this->db->all('SELECT monitor_schedule_version_id FROM monitor_schedules WHERE monitor_schedule_id=? AND monitor_schedule_version_id<>?', [$version['monitor_schedule_id'], $version['monitor_schedule_version_id']]) as $other) {
                    if (in_array($this->status($other['monitor_schedule_version_id']), ['ADMITTED', 'NOT_YET_VALID'], true)) {
                        return Outcome::refuse('SCHEDULE_OTHER_VERSION_ADMITTED');
                    }
                }
                if ($this->grants->status($version['execution_grant_id']) !== 'ADMITTED') {
                    return Outcome::refuse('SCHEDULE_GRANT_NOT_ADMITTED');
                }
                // What was reviewed must still be true now: grant binding, frozen configuration,
                // delegator and service authority, source exclusivity.
                $input = array_intersect_key($version['definition'], array_flip(self::INPUT_KEYS));
                $still = $this->normalize($scope, $input, $version['monitor_schedule_version_id']);
                if (!$still->isOk()) {
                    return $still;
                }
                if ($still->value() !== $version['definition']) {
                    return Outcome::refuse('SCHEDULE_DEFINITION_NO_LONGER_CURRENT');
                }

                return $this->appendEvent($tx, $version['monitor_schedule_version_id'], 'ADMITTED', 'OWNER_DECISION_ADMITTED', $decisionRef, $decisionSha256, $scope['operator_id']);
            });
        } catch (\PDOException $error) {
            return Database::classify($error, 'SCHEDULE_TRANSITION_RACED');
        }
    }

    /** Stand-down: stops new occurrences. It never deletes an occurrence or rewrites a run. */
    public function suspend(array $scope, string $versionId, string $reasonCode): Outcome
    {
        return $this->standDownOne($scope, $versionId, 'SUSPENDED', ['ADMITTED', 'NOT_YET_VALID'], $reasonCode);
    }

    public function revoke(array $scope, string $versionId, string $reasonCode): Outcome
    {
        return $this->standDownOne($scope, $versionId, 'REVOKED', ['PROPOSED', 'ADMITTED', 'SUSPENDED', 'NOT_YET_VALID', 'EXPIRED'], $reasonCode);
    }

    /**
     * Suspend (or revoke) every live schedule version in one workspace. Idempotent.
     *
     * @return Outcome<array{schedules: list<array{monitor_schedule_version_id: string, status: string}>}>
     */
    public function standDown(array $scope, string $mode, string $reasonCode): Outcome
    {
        if (!in_array($mode, ['suspend', 'revoke'], true)) {
            return Outcome::refuse('STAND_DOWN_MODE_INVALID');
        }
        $done = [];
        foreach ($this->db->all('SELECT monitor_schedule_version_id FROM monitor_schedules WHERE tenant_id=? AND workspace_id=? ORDER BY created_at, monitor_schedule_version_id', [$scope['tenant_id'], $scope['workspace_id']]) as $row) {
            $id = (string) $row['monitor_schedule_version_id'];
            $applicable = $mode === 'suspend' ? ['ADMITTED', 'NOT_YET_VALID'] : ['PROPOSED', 'ADMITTED', 'SUSPENDED', 'NOT_YET_VALID'];
            if (in_array($this->status($id), $applicable, true)) {
                $out = $mode === 'suspend' ? $this->suspend($scope, $id, $reasonCode) : $this->revoke($scope, $id, $reasonCode);
                if (!$out->isOk()) {
                    return $out;
                }
            }
            $done[] = ['monitor_schedule_version_id' => $id, 'status' => $this->status($id)];
        }

        return Outcome::ok(['schedules' => $done]);
    }

    /**
     * The operator's read of this workspace's schedules (P09 administration page). Read-only and gated
     * by mission.read: identifiers, digests, bounds, computed status and counts — never a decision's
     * bytes. It lists the SCHEDULED_OBSERVATION grants a new version could name, with their status,
     * because a schedule can only be proposed under one; it does not say any of them is admitted.
     *
     * @return Outcome<array{schedules: list<array<string, mixed>>, grants: list<array<string, mixed>>}>
     */
    public function overview(array $scope): Outcome
    {
        if (!$this->may($scope, ['mission.read'])) {
            return Outcome::refuse('SCHEDULE_READ_DENIED');
        }
        $schedules = [];
        foreach ($this->db->all(
            'SELECT s.*, (SELECT COUNT(*) FROM monitor_schedule_occurrences o WHERE o.monitor_schedule_version_id = s.monitor_schedule_version_id) AS occurrences
             FROM monitor_schedules s WHERE s.tenant_id=? AND s.workspace_id=? ORDER BY s.created_at DESC, s.monitor_schedule_version_id LIMIT 100',
            [$scope['tenant_id'], $scope['workspace_id']]
        ) as $row) {
            $id = (string) $row['monitor_schedule_version_id'];
            $current = $this->current($id);
            $schedules[] = [
                'monitor_schedule_version_id' => $id, 'monitor_schedule_id' => (string) $row['monitor_schedule_id'],
                'schedule_version' => (int) $row['schedule_version'], 'schedule_digest' => (string) $row['schedule_digest'],
                'mission_id' => (string) $row['mission_id'], 'execution_grant_id' => (string) $row['execution_grant_id'],
                'service_identity_id' => (string) $row['service_identity_id'], 'delegating_operator_id' => (string) $row['delegating_operator_id'],
                'cadence_seconds' => (int) $row['cadence_seconds'], 'max_occurrences' => (int) $row['max_occurrences'],
                'occurrences' => (int) $row['occurrences'], 'valid_from' => (string) $row['valid_from'], 'valid_until' => (string) $row['valid_until'],
                'status' => $this->status($id), 'usable_now' => $current->isOk(), 'not_usable_reason' => $current->isOk() ? null : $current->reasonCode(),
                'observation_mode' => (string) $row['observation_mode'], 'created_at' => (string) $row['created_at'],
            ];
        }
        $grants = array_map(fn (array $g): array => [
            'execution_grant_id' => (string) $g['execution_grant_id'], 'mission_id' => (string) $g['mission_id'],
            'grant_digest' => (string) $g['grant_digest'], 'valid_from' => (string) $g['valid_from'], 'valid_until' => (string) $g['valid_until'],
            'status' => $this->grants->status((string) $g['execution_grant_id']),
        ], $this->db->all(
            "SELECT execution_grant_id, mission_id, grant_digest, valid_from, valid_until FROM monitor_execution_grants
             WHERE tenant_id=? AND workspace_id=? AND purpose='SCHEDULED_OBSERVATION' ORDER BY created_at DESC, execution_grant_id LIMIT 50",
            [$scope['tenant_id'], $scope['workspace_id']]
        ));

        return Outcome::ok(['schedules' => $schedules, 'grants' => $grants, 'grants_execution' => false]);
    }

    public function find(string $tenantId, string $workspaceId, string $versionId): ?array
    {
        $row = $this->findById($versionId);

        return $row !== null && $row['tenant_id'] === $tenantId && $row['workspace_id'] === $workspaceId ? $row : null;
    }

    public function findById(string $versionId): ?array
    {
        $row = $this->db->one('SELECT * FROM monitor_schedules WHERE monitor_schedule_version_id=?', [$versionId]);
        if ($row === null) {
            return null;
        }
        $row['definition'] = json_decode($row['definition_json'], true, 16, JSON_THROW_ON_ERROR);
        unset($row['definition_json']);

        return $row;
    }

    /** PROPOSED, ADMITTED, SUSPENDED, REVOKED, EXPIRED or NOT_YET_VALID — computed, never stored. */
    public function status(string $versionId): string
    {
        $row = $this->db->one('SELECT valid_from, valid_until FROM monitor_schedules WHERE monitor_schedule_version_id=?', [$versionId]);
        if ($row === null) {
            return 'NOT_FOUND';
        }
        $last = $this->db->one('SELECT event_kind FROM monitor_schedule_events WHERE monitor_schedule_version_id=? ORDER BY sequence_no DESC LIMIT 1', [$versionId]);
        $kind = $last['event_kind'] ?? 'PROPOSED';
        if ($kind === 'REVOKED' || $kind === 'SUSPENDED') {
            return $kind;
        }
        $now = $this->clock->now();
        if ($now >= $row['valid_until']) {
            return 'EXPIRED';
        }
        if ($kind === 'ADMITTED' && $now < $row['valid_from']) {
            return 'NOT_YET_VALID';
        }

        return $kind;
    }

    /**
     * Is this schedule version usable right now? ADMITTED, its owner decision still on disk with the
     * same bytes and still listing this digest, and its SCHEDULED_OBSERVATION grant still ADMITTED.
     */
    public function current(string $versionId): Outcome
    {
        $version = $this->findById($versionId);
        if ($version === null) {
            return Outcome::refuse('SCHEDULE_NOT_FOUND');
        }
        $status = $this->status($versionId);
        if ($status !== 'ADMITTED') {
            return Outcome::refuse('SCHEDULE_' . $status);
        }
        $admission = $this->db->one("SELECT owner_decision_ref, owner_decision_sha256 FROM monitor_schedule_events WHERE monitor_schedule_version_id=? AND event_kind='ADMITTED' ORDER BY sequence_no DESC LIMIT 1", [$versionId]);
        $decision = $admission === null ? null : $this->decisions->resolve((string) $admission['owner_decision_ref'], (string) $admission['owner_decision_sha256']);
        if ($decision === null || !$decision->isOk()) {
            return $decision === null ? Outcome::refuse('SCHEDULE_NOT_ADMITTED') : $decision;
        }
        if (!OwnerDecisionRegistry::authorizesSchedule($decision->value(), $version['tenant_id'], $version['workspace_id'], $version['schedule_digest'])) {
            return Outcome::refuse('OWNER_DECISION_DOES_NOT_AUTHORIZE_SCHEDULE');
        }
        if ($this->grants->status($version['execution_grant_id']) !== 'ADMITTED') {
            return Outcome::refuse('SCHEDULE_GRANT_NOT_ADMITTED');
        }

        return Outcome::ok($version);
    }

    /**
     * The delegation a SERVICE act rests on, re-read at every call: the occurrence the principal
     * names, in this scope, whose schedule version is current, naming exactly this service identity
     * and this delegating operator.
     */
    public function delegation(string $tenantId, string $workspaceId, MonitorPrincipal $principal): Outcome
    {
        if (!$principal->isService() || $principal->occurrenceId === null) {
            return Outcome::refuse('MONITOR_SERVICE_PRINCIPAL_INVALID');
        }
        $occurrence = $this->db->one('SELECT * FROM monitor_schedule_occurrences WHERE monitor_schedule_occurrence_id=? AND tenant_id=? AND workspace_id=?', [$principal->occurrenceId, $tenantId, $workspaceId]);
        if ($occurrence === null) {
            return Outcome::refuse('SCHEDULE_OCCURRENCE_NOT_FOUND');
        }
        $current = $this->current((string) $occurrence['monitor_schedule_version_id']);
        if (!$current->isOk()) {
            return $current;
        }
        $v = $current->value();
        if ($v['service_identity_id'] !== $principal->actorId || $v['delegating_operator_id'] !== $principal->delegatingOperatorId) {
            return Outcome::refuse('SCHEDULE_PRINCIPAL_MISMATCH');
        }

        return Outcome::ok($v + ['occurrence' => $occurrence]);
    }

    /** Versions whose latest event is ADMITTED, oldest first — the dispatcher's bounded candidate set. */
    public function admittedCandidates(int $limit): array
    {
        $limit = max(1, min(200, $limit));

        return array_column($this->db->all(
            "SELECT s.monitor_schedule_version_id FROM monitor_schedules s
             JOIN monitor_schedule_events e ON e.monitor_schedule_version_id=s.monitor_schedule_version_id
             WHERE e.event_kind='ADMITTED'
               AND e.sequence_no=(SELECT MAX(e2.sequence_no) FROM monitor_schedule_events e2 WHERE e2.monitor_schedule_version_id=s.monitor_schedule_version_id)
               AND s.valid_until > ?
             ORDER BY s.created_at, s.monitor_schedule_version_id LIMIT {$limit}",
            [$this->clock->now()]
        ), 'monitor_schedule_version_id');
    }

    public static function digest(string $tenantId, string $workspaceId, array $definition): string
    {
        return Digest::canonicalDigest(['tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'schedule' => $definition]);
    }

    /**
     * A new series is named by its first version's reviewed input, not by a fresh random id, so the
     * digest `plan()` shows is exactly the digest `propose()` records (and an operator or a decision
     * can be handed it before anything is stored). Proposing the same first version again is
     * idempotent. The id is UUIDv4-shaped and opaque: it reveals nothing without the whole input.
     */
    private static function newSeriesId(string $tenantId, string $workspaceId, array $in): string
    {
        $bytes = substr(hash('sha256', Digest::canonicalDigest(['kind' => 'monitor-schedule-series', 'tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'first_version' => $in]), true), 0, 16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** @param ?string $self the version being re-validated at admission, excluded from the series/source checks */
    private function normalize(array $scope, array $in, ?string $self = null): Outcome
    {
        if (array_diff(array_keys($in), self::INPUT_KEYS) !== [] || array_diff(self::INPUT_KEYS, array_keys($in)) !== []) {
            return Outcome::refuse('SCHEDULE_DEFINITION_INVALID');
        }
        $uuid = static fn (mixed $v): bool => is_string($v) && Ids::isValid($v);
        foreach (['mission_id', 'execution_grant_id', 'service_identity_id'] as $key) {
            if (!$uuid($in[$key])) {
                return Outcome::refuse('SCHEDULE_DEFINITION_INVALID');
            }
        }
        if ($in['monitor_schedule_id'] !== null && !$uuid($in['monitor_schedule_id'])) {
            return Outcome::refuse('SCHEDULE_DEFINITION_INVALID');
        }
        if (!is_int($in['cadence_seconds']) || $in['cadence_seconds'] < self::MIN_CADENCE_SECONDS || $in['cadence_seconds'] > self::MAX_CADENCE_SECONDS) {
            return Outcome::refuse('SCHEDULE_CADENCE_OUT_OF_BOUNDS');
        }
        if (!is_int($in['max_occurrences']) || $in['max_occurrences'] < 1 || $in['max_occurrences'] > self::MAX_OCCURRENCES) {
            return Outcome::refuse('SCHEDULE_BUDGET_INVALID');
        }
        $from = is_string($in['valid_from']) ? Clock::parse($in['valid_from']) : null;
        $until = is_string($in['valid_until']) ? Clock::parse($in['valid_until']) : null;
        if ($from === null || $until === null || $until <= $from) {
            return Outcome::refuse('SCHEDULE_WINDOW_INVALID');
        }

        // The parent grant: SCHEDULED_OBSERVATION, this mission and frozen configuration, not dead.
        $grant = $this->grants->find($scope['tenant_id'], $scope['workspace_id'], $in['execution_grant_id']);
        if ($grant === null || $grant['purpose'] !== 'SCHEDULED_OBSERVATION' || $grant['mission_id'] !== $in['mission_id']) {
            return Outcome::refuse('SCHEDULE_GRANT_MISMATCH');
        }
        if (in_array($this->grants->status($grant['execution_grant_id']), ['REVOKED', 'EXPIRED', 'NOT_FOUND'], true)) {
            return Outcome::refuse('SCHEDULE_GRANT_NOT_LIVE');
        }
        $g = $grant['definition'];
        if ($in['valid_from'] < $g['valid_from'] || $in['valid_until'] > $g['valid_until']) {
            return Outcome::refuse('SCHEDULE_WINDOW_OUTSIDE_GRANT');
        }
        if ($in['max_occurrences'] > $g['limits']['max_source_captures']) {
            return Outcome::refuse('SCHEDULE_BUDGET_EXCEEDS_GRANT');
        }
        $config = $this->db->one('SELECT definition_json, definition_digest, execution_mode FROM monitor_mission_configs WHERE mission_id=? AND tenant_id=? AND workspace_id=?', [$in['mission_id'], $scope['tenant_id'], $scope['workspace_id']]);
        if ($config === null || $config['execution_mode'] !== 'NATIVE' || !hash_equals((string) $config['definition_digest'], (string) $grant['definition_digest'])) {
            return Outcome::refuse('SCHEDULE_CONFIGURATION_MISMATCH');
        }
        $frozen = json_decode($config['definition_json'], true, 64, JSON_THROW_ON_ERROR);

        // The delegator IS the grant's principal, and must be the operator the frozen configuration
        // names, holding current authority — proved by the same validation a manual run uses.
        $delegator = $grant['principal_operator_id'];
        $delegatorScope = ['tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'operator_id' => $delegator];
        $valid = $this->configuration->validate($delegatorScope, $frozen, 'NATIVE');
        if (!$valid->isOk()) {
            return Outcome::refuse('SCHEDULE_CONFIGURATION_NOT_CURRENT', ['detail' => $valid->reasonCode()]);
        }
        // The source's own cadence floor (the card's caching window or rate-limit floor, compiled by
        // the same SourcePolicy capture uses) binds the schedule. A cadence below it would only ever
        // produce SOURCE_FETCH_CADENCE_TOO_SOON at capture time; it is refused here instead. The floor
        // is part of the definition, so a changed source policy makes admission re-review it.
        $sourcePolicy = Capture::currentPolicy($this->db, $this->clock, [
            'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
            'monitored_source_id' => $frozen['monitored_source_id'], 'capture_url' => $frozen['capture_url'],
        ]);
        if (!$sourcePolicy->isOk()) {
            return Outcome::refuse('SCHEDULE_SOURCE_POLICY_NOT_CURRENT', ['detail' => $sourcePolicy->reasonCode()]);
        }
        $sourceFloorMs = (int) $sourcePolicy->value()['policy']['minimum_fetch_interval_ms'];
        if ($in['cadence_seconds'] * 1000 < $sourceFloorMs) {
            return Outcome::refuse('SCHEDULE_CADENCE_BELOW_SOURCE_FLOOR', ['minimum_fetch_interval_ms' => $sourceFloorMs]);
        }
        $service = $this->db->one('SELECT tenant_id, service_class, status FROM service_identities WHERE service_identity_id=?', [$in['service_identity_id']]);
        if ($service === null || $service['tenant_id'] !== $scope['tenant_id'] || $service['service_class'] !== MonitorPrincipal::SERVICE_CLASS || $service['status'] !== 'ACTIVE') {
            return Outcome::refuse('SCHEDULE_SERVICE_IDENTITY_INVALID');
        }
        $probe = MonitorPrincipal::service($in['service_identity_id'], $delegator, Ids::new());
        $authorized = $probe->authorize($this->db, $this->clock, $scope['tenant_id'], $scope['workspace_id'], self::SERVICE_PERMISSIONS, 'MONITOR_SCHEDULE');
        if (!$authorized->isOk()) {
            return Outcome::refuse('SCHEDULE_PRINCIPAL_NOT_AUTHORIZED', ['detail' => $authorized->reasonCode()]);
        }

        // Series and version. A successor keeps the mission; everything else may change.
        if ($in['monitor_schedule_id'] === null) {
            if ($self !== null) {
                return Outcome::refuse('SCHEDULE_DEFINITION_INVALID');
            }
            $seriesId = self::newSeriesId($scope['tenant_id'], $scope['workspace_id'], $in);
            $version = 1;
        } else {
            $seriesId = $in['monitor_schedule_id'];
            $series = $this->db->all('SELECT monitor_schedule_version_id, schedule_version, mission_id, tenant_id, workspace_id FROM monitor_schedules WHERE monitor_schedule_id=? ORDER BY schedule_version', [$seriesId]);
            if ($series === [] || $series[0]['tenant_id'] !== $scope['tenant_id'] || $series[0]['workspace_id'] !== $scope['workspace_id'] || $series[0]['mission_id'] !== $in['mission_id']) {
                return Outcome::refuse('SCHEDULE_SERIES_MISMATCH');
            }
            if ($self !== null) {
                $mine = array_values(array_filter($series, static fn (array $r): bool => $r['monitor_schedule_version_id'] === $self));
                $version = $mine === [] ? -1 : (int) $mine[0]['schedule_version'];
            } else {
                $version = (int) end($series)['schedule_version'] + 1;
            }
        }

        // Two series against one source would race each other's before/after baselines. The source
        // lock serializes captures; this refuses the conflicting delegation before it exists.
        $monitoredSource = $frozen['monitored_source_id'];
        foreach ($this->db->all('SELECT monitor_schedule_version_id, monitor_schedule_id FROM monitor_schedules WHERE tenant_id=? AND monitored_source_id=? AND monitor_schedule_id<>?', [$scope['tenant_id'], $monitoredSource, $seriesId]) as $other) {
            if (in_array($this->status((string) $other['monitor_schedule_version_id']), self::LIVE_STATUSES, true)) {
                return Outcome::refuse('SCHEDULE_SOURCE_ALREADY_SCHEDULED');
            }
        }

        return Outcome::ok([
            'monitor_schedule_id' => $seriesId,
            'schedule_version' => $version,
            'mission_id' => $in['mission_id'],
            'definition_digest' => (string) $config['definition_digest'],
            'execution_grant_id' => $in['execution_grant_id'],
            'grant_digest' => (string) $grant['grant_digest'],
            'service_identity_id' => $in['service_identity_id'],
            'delegating_operator_id' => $delegator,
            'monitored_source_id' => $monitoredSource,
            'cadence_seconds' => $in['cadence_seconds'],
            'source_minimum_fetch_interval_ms' => $sourceFloorMs,
            'catch_up_policy' => self::CATCH_UP_POLICY,
            'observation_mode' => self::OBSERVATION_MODE,
            'max_occurrences' => $in['max_occurrences'],
            'valid_from' => $in['valid_from'],
            'valid_until' => $in['valid_until'],
        ]);
    }

    private function standDownOne(array $scope, string $versionId, string $kind, array $from, string $reasonCode): Outcome
    {
        if (!$this->may($scope, ['tenant.administer']) && !$this->may($scope, ['mission.transition'])) {
            return Outcome::refuse('SCHEDULE_STAND_DOWN_DENIED');
        }
        if (preg_match('/^[A-Z][A-Z0-9_]{2,95}$/D', $reasonCode) !== 1) {
            return Outcome::refuse('SCHEDULE_REASON_REQUIRED');
        }
        if ($this->find($scope['tenant_id'], $scope['workspace_id'], $versionId) === null) {
            return Outcome::refuse('SCHEDULE_NOT_FOUND');
        }
        try {
            return $this->db->transaction(function (Database $tx) use ($scope, $versionId, $kind, $from, $reasonCode): Outcome {
                $tx->one('SELECT monitor_schedule_version_id FROM monitor_schedules WHERE monitor_schedule_version_id=? FOR UPDATE', [$versionId]);
                $status = $this->status($versionId);
                if ($status === $kind) {
                    return Outcome::ok(['monitor_schedule_version_id' => $versionId, 'status' => $status, 'unchanged' => true]);
                }
                if (!in_array($status, $from, true)) {
                    return Outcome::refuse('SCHEDULE_TRANSITION_INVALID', ['status' => $status]);
                }

                return $this->appendEvent($tx, $versionId, $kind, $reasonCode, null, null, $scope['operator_id']);
            });
        } catch (\PDOException $error) {
            return Database::classify($error, 'SCHEDULE_TRANSITION_RACED');
        }
    }

    private function appendEvent(Database $tx, string $versionId, string $kind, string $reason, ?string $ref, ?string $sha, string $actor): Outcome
    {
        $sequence = (int) $tx->scalar('SELECT COALESCE(MAX(sequence_no), 0) FROM monitor_schedule_events WHERE monitor_schedule_version_id=?', [$versionId]) + 1;
        $tx->insert('monitor_schedule_events', [
            'monitor_schedule_event_id' => Ids::new(), 'monitor_schedule_version_id' => $versionId, 'sequence_no' => $sequence,
            'event_kind' => $kind, 'reason_code' => $reason, 'owner_decision_ref' => $ref, 'owner_decision_sha256' => $sha,
            'actor_operator_id' => $actor, 'recorded_at' => $this->clock->now(),
        ]);

        return Outcome::ok(['monitor_schedule_version_id' => $versionId, 'status' => $this->status($versionId), 'grants_execution' => $kind === 'ADMITTED']);
    }

    private function may(array $scope, array $permissions): bool
    {
        foreach (['tenant_id', 'workspace_id', 'operator_id'] as $key) {
            if (!is_string($scope[$key] ?? null) || $scope[$key] === '') {
                return false;
            }
        }
        $guard = new AccessGuard($this->db, $this->clock);
        foreach ($permissions as $permission) {
            if ($guard->evaluate('OPERATOR', $scope['operator_id'], $scope['tenant_id'], $scope['workspace_id'], $permission, 'MONITOR_SCHEDULE', null, null, true)['decision'] !== 'ALLOW') {
                return false;
            }
        }

        return true;
    }
}
