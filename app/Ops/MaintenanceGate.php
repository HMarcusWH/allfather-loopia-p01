<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ops;

use Allfather\Loopia\Core\{Clock,Ids,Outcome};

/**
 * Deployment-wide quiescence for backup/restore windows.
 *
 * Runtime requests hold a shared flock lease for their whole process lifetime. Entering maintenance
 * takes the exclusive lock first, so every in-flight request/tick has drained before the marker is
 * published. The exclusive lock is then released; the marker makes all NEW runtime leases refuse.
 *
 * This is operational quiescence only. It does not revoke grants, retract M10 decisions, change a
 * mission, or authorize restore/deployment. The marker contains identifiers/reason codes only.
 */
final class MaintenanceGate
{
    public const STATE_VERSION = 1;
    public const REASONS = ['BACKUP', 'RESTORE_REHEARSAL', 'OPERATOR_MAINTENANCE'];
    public const OPERATION_KINDS = ['AUTHORITY_STAND_DOWN', 'BACKUP_EXPORT'];
    private const MARKER = 'storage/maintenance.json';
    private const OPERATION_MARKER = 'storage/maintenance-operation.json';
    private const LOCK = 'storage/maintenance.lock';

    public function __construct(
        private readonly string $root,
        private readonly ?Clock $clock = null,
    ) {}

    /** @return array<string,mixed> */
    public function status(): array
    {
        $path = $this->markerPath();
        // file_exists() is false for a dangling symlink; check links first or a malicious/broken
        // marker symlink would be interpreted as "maintenance off".
        if (is_link($path)) {
            return ['active' => true, 'state' => 'INVALID', 'reason_code' => 'MAINTENANCE_STATE_INVALID'];
        }
        if (!file_exists($path)) {
            return ['active' => false, 'state' => 'OPEN'];
        }
        if (!is_file($path)) {
            return ['active' => true, 'state' => 'INVALID', 'reason_code' => 'MAINTENANCE_STATE_INVALID'];
        }

        $raw = @file_get_contents($path);
        if ($raw === false || strlen($raw) > 8192) {
            return ['active' => true, 'state' => 'INVALID', 'reason_code' => 'MAINTENANCE_STATE_INVALID'];
        }
        try {
            $value = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['active' => true, 'state' => 'INVALID', 'reason_code' => 'MAINTENANCE_STATE_INVALID'];
        }
        $keys = is_array($value) ? array_keys($value) : [];
        sort($keys, SORT_STRING);
        $expected = ['entered_at','environment','maintenance_id','operator_id','reason','state_version'];
        sort($expected, SORT_STRING);
        if (!is_array($value) || $keys !== $expected
            || ($value['state_version'] ?? null) !== self::STATE_VERSION
            || preg_match('/^[a-f0-9-]{36}$/D', (string) ($value['maintenance_id'] ?? '')) !== 1
            || preg_match('/^[a-f0-9-]{36}$/D', (string) ($value['operator_id'] ?? '')) !== 1
            || !in_array($value['reason'] ?? null, self::REASONS, true)
            || !in_array($value['environment'] ?? null, ['development','testing','staging','production'], true)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', (string) ($value['entered_at'] ?? '')) !== 1
        ) {
            return ['active' => true, 'state' => 'INVALID', 'reason_code' => 'MAINTENANCE_STATE_INVALID'];
        }

        return [
            'active' => true,
            'state' => 'QUIESCED',
            'maintenance_id' => $value['maintenance_id'],
            'operator_id' => $value['operator_id'],
            'environment' => $value['environment'],
            'reason' => $value['reason'],
            'entered_at' => $value['entered_at'],
        ];
    }


    /** @return array<string,mixed> */
    public function operationStatus(): array
    {
        $path = $this->operationMarkerPath();
        if (is_link($path)) {
            return ['active' => true, 'state' => 'INVALID', 'reason_code' => 'MAINTENANCE_OPERATION_STATE_INVALID'];
        }
        if (!file_exists($path)) {
            return ['active' => false, 'state' => 'OPEN'];
        }
        if (!is_file($path)) {
            return ['active' => true, 'state' => 'INVALID', 'reason_code' => 'MAINTENANCE_OPERATION_STATE_INVALID'];
        }

        $raw = @file_get_contents($path);
        if ($raw === false || strlen($raw) > 8192) {
            return ['active' => true, 'state' => 'INVALID', 'reason_code' => 'MAINTENANCE_OPERATION_STATE_INVALID'];
        }
        try {
            $value = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['active' => true, 'state' => 'INVALID', 'reason_code' => 'MAINTENANCE_OPERATION_STATE_INVALID'];
        }
        $keys = is_array($value) ? array_keys($value) : [];
        sort($keys, SORT_STRING);
        $expected = ['kind','maintenance_id','operation_id','operator_id','started_at','state_version'];
        sort($expected, SORT_STRING);
        if (!is_array($value) || $keys !== $expected
            || ($value['state_version'] ?? null) !== self::STATE_VERSION
            || preg_match('/^[a-f0-9-]{36}$/D', (string) ($value['maintenance_id'] ?? '')) !== 1
            || preg_match('/^[a-f0-9-]{36}$/D', (string) ($value['operation_id'] ?? '')) !== 1
            || preg_match('/^[a-f0-9-]{36}$/D', (string) ($value['operator_id'] ?? '')) !== 1
            || !in_array($value['kind'] ?? null, self::OPERATION_KINDS, true)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', (string) ($value['started_at'] ?? '')) !== 1
        ) {
            return ['active' => true, 'state' => 'INVALID', 'reason_code' => 'MAINTENANCE_OPERATION_STATE_INVALID'];
        }

        return [
            'active' => true,
            'state' => 'ACTIVE',
            'operation_id' => $value['operation_id'],
            'maintenance_id' => $value['maintenance_id'],
            'operator_id' => $value['operator_id'],
            'kind' => $value['kind'],
            'started_at' => $value['started_at'],
        ];
    }

    /**
     * Acquire the runtime side of the barrier. The returned lease must remain referenced until the
     * request/tick finishes; its destructor releases the shared flock.
     */
    public function acquireRuntimeLease(): Outcome
    {
        $handle = $this->lockHandle();
        if ($handle === null) {
            return Outcome::failure('MAINTENANCE_LOCK_UNAVAILABLE');
        }
        if (!@flock($handle, LOCK_SH | LOCK_NB)) {
            fclose($handle);

            return Outcome::refuse('MAINTENANCE_QUIESCING');
        }

        // Marker is checked AFTER the shared lock. If enter() won the race, we either failed to take
        // the lock while it wrote the marker, or see the marker after it released the exclusive lock.
        $state = $this->status();
        if (($state['active'] ?? false) === true) {
            @flock($handle, LOCK_UN);
            fclose($handle);

            return Outcome::refuse(
                ($state['state'] ?? '') === 'INVALID' ? 'MAINTENANCE_STATE_INVALID' : 'MAINTENANCE_ACTIVE',
                array_intersect_key($state, array_flip(['maintenance_id','environment','reason','entered_at'])),
            );
        }
        $operation = $this->operationStatus();
        if (($operation['active'] ?? false) === true) {
            @flock($handle, LOCK_UN);
            fclose($handle);

            return Outcome::refuse(
                ($operation['state'] ?? '') === 'INVALID'
                    ? 'MAINTENANCE_OPERATION_STATE_INVALID'
                    : 'MAINTENANCE_OPERATION_ORPHANED',
                array_intersect_key($operation, array_flip(['operation_id','maintenance_id','kind','started_at'])),
            );
        }

        return Outcome::ok(['lease' => new MaintenanceLease($handle)]);
    }

    /** Enter maintenance only after every shared runtime lease has drained. */
    public function enter(string $operatorId, string $environment, string $reason, int $waitSeconds = 30): Outcome
    {
        if (preg_match('/^[a-f0-9-]{36}$/D', $operatorId) !== 1
            || !in_array($environment, ['development','testing','staging','production'], true)
            || !in_array($reason, self::REASONS, true)
            || $waitSeconds < 0 || $waitSeconds > 300) {
            return Outcome::refuse('MAINTENANCE_REQUEST_INVALID');
        }

        $handle = $this->lockHandle();
        if ($handle === null) {
            return Outcome::failure('MAINTENANCE_LOCK_UNAVAILABLE');
        }
        $deadline = microtime(true) + $waitSeconds;
        do {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                try {
                    $existing = $this->status();
                    if (($existing['active'] ?? false) === true) {
                        return Outcome::refuse(
                            ($existing['state'] ?? '') === 'INVALID' ? 'MAINTENANCE_STATE_INVALID' : 'MAINTENANCE_ALREADY_ACTIVE',
                            array_intersect_key($existing, array_flip(['maintenance_id','environment','reason','entered_at'])),
                        );
                    }
                    $operation = $this->operationStatus();
                    if (($operation['active'] ?? false) === true) {
                        return Outcome::refuse(
                            ($operation['state'] ?? '') === 'INVALID'
                                ? 'MAINTENANCE_OPERATION_STATE_INVALID'
                                : 'MAINTENANCE_OPERATION_ORPHANED',
                        );
                    }

                    $state = [
                        'state_version' => self::STATE_VERSION,
                        'maintenance_id' => Ids::new(),
                        'operator_id' => $operatorId,
                        'environment' => $environment,
                        'reason' => $reason,
                        'entered_at' => ($this->clock ?? new Clock())->now(),
                    ];
                    $written = $this->writeMarker($state);
                    if (!$written) {
                        return Outcome::failure('MAINTENANCE_STATE_WRITE_FAILED');
                    }

                    return Outcome::ok($state + ['active' => true, 'state' => 'QUIESCED']);
                } finally {
                    @flock($handle, LOCK_UN);
                    fclose($handle);
                }
            }
            usleep(50_000);
        } while (microtime(true) <= $deadline);

        fclose($handle);

        return Outcome::refuse('MAINTENANCE_DRAIN_TIMEOUT');
    }

    /** Leave only the exact maintenance window the operator inspected. */
    public function leave(string $maintenanceId, string $operatorId): Outcome
    {
        if (preg_match('/^[a-f0-9-]{36}$/D', $maintenanceId) !== 1
            || preg_match('/^[a-f0-9-]{36}$/D', $operatorId) !== 1) {
            return Outcome::refuse('MAINTENANCE_ID_INVALID');
        }
        $handle = $this->lockHandle();
        if ($handle === null) {
            return Outcome::failure('MAINTENANCE_LOCK_UNAVAILABLE');
        }
        if (!@flock($handle, LOCK_EX)) {
            fclose($handle);

            return Outcome::failure('MAINTENANCE_LOCK_UNAVAILABLE');
        }
        try {
            $state = $this->status();
            if (($state['active'] ?? false) !== true) {
                return Outcome::refuse('MAINTENANCE_NOT_ACTIVE');
            }
            if (($state['state'] ?? '') === 'INVALID') {
                return Outcome::refuse('MAINTENANCE_STATE_INVALID');
            }
            if (!hash_equals((string) $state['maintenance_id'], $maintenanceId)
                || !hash_equals((string) $state['operator_id'], $operatorId)) {
                return Outcome::refuse('MAINTENANCE_CHANGED_RELOAD_REQUIRED');
            }
            $operation = $this->operationStatus();
            if (($operation['active'] ?? false) === true) {
                return Outcome::refuse(
                    ($operation['state'] ?? '') === 'INVALID'
                        ? 'MAINTENANCE_OPERATION_STATE_INVALID'
                        : 'MAINTENANCE_OPERATION_ACTIVE',
                    array_intersect_key($operation, array_flip(['operation_id','kind','started_at'])),
                );
            }
            if (!@unlink($this->markerPath())) {
                return Outcome::failure('MAINTENANCE_STATE_REMOVE_FAILED');
            }

            return Outcome::ok(['maintenance_id' => $maintenanceId, 'active' => false, 'state' => 'OPEN']);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Begin one maintenance-scoped operation under the same exclusive lock used by leave(). */
    public function beginOperation(string $maintenanceId, string $operatorId, string $kind): Outcome
    {
        if (preg_match('/^[a-f0-9-]{36}$/D', $maintenanceId) !== 1
            || preg_match('/^[a-f0-9-]{36}$/D', $operatorId) !== 1
            || !in_array($kind, self::OPERATION_KINDS, true)) {
            return Outcome::refuse('MAINTENANCE_OPERATION_REQUEST_INVALID');
        }
        $handle = $this->lockHandle();
        if ($handle === null || !@flock($handle, LOCK_EX)) {
            if (is_resource($handle)) fclose($handle);
            return Outcome::failure('MAINTENANCE_LOCK_UNAVAILABLE');
        }
        try {
            $state = $this->status();
            if (($state['active'] ?? false) !== true || ($state['state'] ?? '') !== 'QUIESCED') {
                return Outcome::refuse('MAINTENANCE_NOT_ACTIVE');
            }
            if (!hash_equals((string) $state['maintenance_id'], $maintenanceId)
                || !hash_equals((string) $state['operator_id'], $operatorId)
                || ($state['reason'] ?? null) !== 'BACKUP') {
                return Outcome::refuse('MAINTENANCE_CHANGED_RELOAD_REQUIRED');
            }
            $existing = $this->operationStatus();
            if (($existing['active'] ?? false) === true) {
                return Outcome::refuse(
                    ($existing['state'] ?? '') === 'INVALID'
                        ? 'MAINTENANCE_OPERATION_STATE_INVALID'
                        : 'MAINTENANCE_OPERATION_ACTIVE',
                );
            }
            $operation = [
                'state_version' => self::STATE_VERSION,
                'operation_id' => Ids::new(),
                'maintenance_id' => $maintenanceId,
                'operator_id' => $operatorId,
                'kind' => $kind,
                'started_at' => ($this->clock ?? new Clock())->now(),
            ];
            if (!$this->writeOperationMarker($operation)) {
                return Outcome::failure('MAINTENANCE_OPERATION_STATE_WRITE_FAILED');
            }

            return Outcome::ok($operation + ['active' => true, 'state' => 'ACTIVE']);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** End only the exact operation/maintenance/operator tuple that was inspected. */
    public function endOperation(string $operationId, string $operatorId): Outcome
    {
        if (preg_match('/^[a-f0-9-]{36}$/D', $operationId) !== 1
            || preg_match('/^[a-f0-9-]{36}$/D', $operatorId) !== 1) {
            return Outcome::refuse('MAINTENANCE_OPERATION_ID_INVALID');
        }
        $handle = $this->lockHandle();
        if ($handle === null || !@flock($handle, LOCK_EX)) {
            if (is_resource($handle)) fclose($handle);
            return Outcome::failure('MAINTENANCE_LOCK_UNAVAILABLE');
        }
        try {
            $operation = $this->operationStatus();
            if (($operation['active'] ?? false) !== true) {
                return Outcome::refuse('MAINTENANCE_OPERATION_NOT_ACTIVE');
            }
            if (($operation['state'] ?? '') === 'INVALID') {
                return Outcome::refuse('MAINTENANCE_OPERATION_STATE_INVALID');
            }
            if (!hash_equals((string) $operation['operation_id'], $operationId)
                || !hash_equals((string) $operation['operator_id'], $operatorId)) {
                return Outcome::refuse('MAINTENANCE_OPERATION_CHANGED_RELOAD_REQUIRED');
            }
            $maintenance = $this->status();
            if (($maintenance['active'] ?? false) !== true || ($maintenance['state'] ?? '') !== 'QUIESCED'
                || !hash_equals((string) ($maintenance['maintenance_id'] ?? ''), (string) $operation['maintenance_id'])
                || !hash_equals((string) ($maintenance['operator_id'] ?? ''), $operatorId)) {
                return Outcome::refuse('MAINTENANCE_OPERATION_CHANGED_RELOAD_REQUIRED');
            }
            if (!@unlink($this->operationMarkerPath())) {
                return Outcome::failure('MAINTENANCE_OPERATION_STATE_REMOVE_FAILED');
            }

            return Outcome::ok(['operation_id' => $operationId, 'active' => false, 'state' => 'OPEN']);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function lockHandle(): mixed
    {
        $storage = $this->storageRoot();
        if ($storage === null) {
            return null;
        }
        $path = $this->lockPath();
        if (is_link($path)) {
            return null;
        }
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            return null;
        }
        @chmod($path, 0600);

        return $handle;
    }

    /** @param array<string,mixed> $state */
    private function writeMarker(array $state): bool
    {
        $storage = $this->storageRoot();
        if ($storage === null || is_link($this->markerPath())) {
            return false;
        }
        $temp = $storage . DIRECTORY_SEPARATOR . '.maintenance-' . bin2hex(random_bytes(8));
        $bytes = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
        if (@file_put_contents($temp, $bytes, LOCK_EX) !== strlen($bytes)) {
            @unlink($temp);
            return false;
        }
        @chmod($temp, 0600);
        if (!@rename($temp, $this->markerPath())) {
            @unlink($temp);
            return false;
        }

        return true;
    }

    /** @param array<string,mixed> $state */
    private function writeOperationMarker(array $state): bool
    {
        $storage = $this->storageRoot();
        if ($storage === null || is_link($this->operationMarkerPath())) {
            return false;
        }
        $temp = $storage . DIRECTORY_SEPARATOR . '.maintenance-operation-' . bin2hex(random_bytes(8));
        $bytes = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
        if (@file_put_contents($temp, $bytes, LOCK_EX) !== strlen($bytes)) {
            @unlink($temp);
            return false;
        }
        @chmod($temp, 0600);
        if (!@rename($temp, $this->operationMarkerPath())) {
            @unlink($temp);
            return false;
        }

        return true;
    }

    private function storageRoot(): ?string
    {
        $root = realpath($this->root);
        if ($root === false || is_link($this->root)) {
            return null;
        }
        $storage = $root . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($storage) && !@mkdir($storage, 0750, true) && !is_dir($storage)) {
            return null;
        }
        if (is_link($storage)) {
            return null;
        }

        return $storage;
    }

    private function markerPath(): string
    {
        return rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::MARKER);
    }

    private function operationMarkerPath(): string
    {
        return rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::OPERATION_MARKER);
    }

    private function lockPath(): string
    {
        return rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::LOCK);
    }
}

/** Lifetime object for one shared runtime flock. */
final class MaintenanceLease
{
    private mixed $handle;

    public function __construct(mixed $handle)
    {
        $this->handle = $handle;
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            @flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
