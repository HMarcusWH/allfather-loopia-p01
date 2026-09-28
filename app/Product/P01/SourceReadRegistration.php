<?php
declare(strict_types=1);
namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\{Clock,Digest,Ids,OperatorScope,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M01\AdapterConformance;
use Allfather\Loopia\Substrate\M02\AccessGuard;

/**
 * The shared, read-only runtime surface that `p01.source.read` dispatches through.
 *
 * Until now only test seeds created this binding, so a real workspace could not be set up without
 * hand-written SQL. Like ModelRegistration it registers DESCRIPTORS only: one global SAFE_READ_ONLY
 * binding that permits OBSERVE and nothing else, and the tool reference that maps the P01 tool onto
 * it. It grants no tool right, contract, mission or role, and it never opens a socket — whether a
 * live read can happen is decided by the admitted execution grant, not by this row existing.
 */
final class SourceReadRegistration
{
    public const HOST_KEY = 'loopia-monitor-source-read-v1';
    public const TOOL_REF = self::HOST_KEY . ':source.read';

    public function __construct(private readonly Database $db, private readonly Clock $clock) {}

    /** Read-only: the existing binding, validated, or null when none was ever registered. */
    public function existing(): ?Outcome
    {
        $row = $this->row();

        return $row === null ? null : $this->validate($row);
    }

    /** @return Outcome<array{binding_id:string,runtime_host_tool_ref:string,created:bool}> */
    public function ensureBinding(array $scope): Outcome
    {
        $scope = OperatorScope::normalize($scope);
        if ($scope === null) {
            return Outcome::refuse('SOURCE_READ_REGISTRATION_SCOPE_REQUIRED');
        }
        if ((new AccessGuard($this->db, $this->clock))->evaluate('OPERATOR', $scope['operator_id'], $scope['tenant_id'], $scope['workspace_id'], 'runtime_host_binding.administer', 'SOURCE_READ_DESCRIPTOR', null, null, true)['decision'] !== 'ALLOW') {
            return Outcome::refuse('SOURCE_READ_REGISTRATION_DENIED');
        }
        $existing = $this->existing();
        if ($existing !== null) {
            return $existing->isOk() ? Outcome::ok($existing->value() + ['created' => false]) : $existing;
        }

        try {
            return $this->db->transaction(function (Database $tx): Outcome {
                $host = Ids::new();
                $adapter = Ids::new();
                $binding = Ids::new();
                $now = $this->clock->now();
                $tx->insert('runtime_host_instances', ['runtime_host_instance_id' => $host, 'schema_version' => '1', 'host_family' => 'LOOPIA_BOUNDED_SOURCE_READ', 'host_name' => self::HOST_KEY, 'host_instance_key' => self::HOST_KEY, 'host_version' => '1.0.0', 'repository_reference_safe' => 'internal://monitor/source-read', 'licence_status_ref' => 'internal', 'integration_map_document_ref' => 'docs/MONITOR_WORKFLOW.md', 'environment_class' => 'TEST', 'declared_security_assumptions' => json_encode(['NO_EXTERNAL_EFFECT', 'SOURCE_TRANSPORT_INACTIVE_BY_DEFAULT']), 'status' => 'REGISTERED', 'created_at' => $now]);
                $identity = ['runtime_host_instance_id' => $host, 'adapter_family' => 'LOOPIA_BOUNDED_SOURCE_READ', 'adapter_name' => self::HOST_KEY, 'adapter_version' => '1.0.0', ...self::payloads()];
                $tx->insert('runtime_adapter_instances', ['runtime_adapter_instance_id' => $adapter, 'schema_version' => '1', ...$identity, 'identity_digest' => AdapterConformance::identityDigest($identity), 'status' => 'READY', 'created_at' => $now]);
                $tx->insert('runtime_host_bindings', ['binding_id' => $binding, 'schema_version' => '1', 'runtime_host_instance_id' => $host, 'runtime_adapter_instance_id' => $adapter, 'binding_type' => 'TOOL_SURFACE', 'runtime_host_reference' => self::HOST_KEY . ':tool', 'runtime_host_version' => '1.0.0', 'mapping_purpose' => 'Read one approved public competitor page; no external effect.', 'permitted_operation_classes' => '["OBSERVE"]', 'safety_classification' => 'SAFE_READ_ONLY', 'status' => 'ACTIVE', 'tenant_id' => null, 'workspace_id' => null, 'activation_evidence_refs' => '[]', 'activated_at' => $now, 'created_at' => $now]);
                $validated = $this->validate($this->row() ?? []);

                return $validated->isOk() ? Outcome::ok($validated->value() + ['created' => true]) : $validated;
            });
        } catch (\PDOException) {
            // Another workspace may have won the global descriptor race. Converge only when the
            // winner is the exact descriptor we would have created; otherwise surface a conflict.
            $raced = $this->existing();

            return $raced !== null && $raced->isOk()
                ? Outcome::ok($raced->value() + ['created' => false])
                : Outcome::refuse('SOURCE_READ_DESCRIPTOR_CONFLICT');
        }
    }

    /**
     * Map the registered P01 read tool onto the binding. Reuses an exact existing mapping; refuses a
     * different one rather than adding a second surface for the same tool.
     */
    public function bindTool(string $bindingId, string $governedToolId): Outcome
    {
        return $this->db->transaction(function (Database $tx) use ($bindingId, $governedToolId): Outcome {
            // Global tool mapping: serialize on the governed-tool row so two workspaces cannot both
            // observe "no ref" and append rival mappings. Nested callers keep this lock until their
            // outer transaction commits.
            $tool = $tx->one("SELECT tool_key,runtime_mapping_ref_id,status FROM governed_tools WHERE governed_tool_id=? FOR UPDATE", [$governedToolId]);
            if ($tool === null || $tool['tool_key'] !== 'p01.source.read' || $tool['runtime_mapping_ref_id'] !== $bindingId || $tool['status'] !== 'ACTIVE') {
                return Outcome::refuse('SOURCE_READ_TOOL_NOT_ON_BINDING');
            }
            $refs = $tx->all('SELECT * FROM runtime_host_tool_refs WHERE governed_tool_id=?', [$governedToolId]);
            if ($refs !== []) {
                $r = $refs[0];

                return count($refs) === 1 && $r['binding_id'] === $bindingId && $r['runtime_host_tool_ref'] === self::TOOL_REF && $r['tool_surface_type'] === 'READ' && $r['declared_operation_class'] === 'OBSERVE' && $r['mapping_status'] === 'ACTIVE'
                    ? Outcome::ok(['tool_ref_id' => $r['tool_ref_id'], 'created' => false])
                    : Outcome::refuse('SOURCE_READ_TOOL_REF_CONFLICT');
            }
            $id = Ids::new();
            $tx->insert('runtime_host_tool_refs', ['tool_ref_id' => $id, 'schema_version' => '1', 'binding_id' => $bindingId, 'governed_tool_id' => $governedToolId, 'runtime_host_tool_ref' => self::TOOL_REF, 'tool_surface_type' => 'READ', 'declared_operation_class' => 'OBSERVE', 'mapping_status' => 'ACTIVE', 'version' => '1.0.0', 'created_at' => $this->clock->now()]);

            return Outcome::ok(['tool_ref_id' => $id, 'created' => true]);
        });
    }

    private static function payloads(): array
    {
        return ['descriptor_identity' => Digest::canonicalize(['adapterId' => self::HOST_KEY, 'adapterVersion' => '1.0.0']), 'capability_manifest_identity' => Digest::canonicalize(['capabilities' => ['READ_MODEL']]), 'conformance_profile_identity' => Digest::canonicalize(['profile' => 'READ_ONLY', 'version' => '1'])];
    }

    private function row(): ?array
    {
        return $this->db->one(
            'SELECT b.binding_id,b.binding_type,b.runtime_host_reference,b.runtime_host_version,b.mapping_purpose,b.safety_classification,
                    b.status AS binding_status,b.tenant_id AS binding_tenant,b.workspace_id AS binding_workspace,
                    b.permitted_operation_classes,b.activation_evidence_refs,
                    a.*,a.status AS adapter_status,
                    h.host_family,h.host_name,h.host_instance_key,h.host_version,h.repository_reference_safe,
                    h.licence_status_ref,h.integration_map_document_ref,h.environment_class,h.declared_security_assumptions,
                    h.status AS host_status
             FROM runtime_host_instances h
             JOIN runtime_host_bindings b ON b.runtime_host_instance_id=h.runtime_host_instance_id
             JOIN runtime_adapter_instances a ON a.runtime_adapter_instance_id=b.runtime_adapter_instance_id
             WHERE h.host_family=? AND h.host_name=? AND h.host_instance_key=?',
            ['LOOPIA_BOUNDED_SOURCE_READ', self::HOST_KEY, self::HOST_KEY],
        );
    }

    private function validate(array $r): Outcome
    {
        $expected = [
            'binding_type' => 'TOOL_SURFACE', 'runtime_host_reference' => self::HOST_KEY . ':tool',
            'runtime_host_version' => '1.0.0', 'mapping_purpose' => 'Read one approved public competitor page; no external effect.',
            'safety_classification' => 'SAFE_READ_ONLY', 'binding_status' => 'ACTIVE',
            'adapter_family' => 'LOOPIA_BOUNDED_SOURCE_READ', 'adapter_name' => self::HOST_KEY,
            'adapter_version' => '1.0.0', 'adapter_status' => 'READY',
            'host_family' => 'LOOPIA_BOUNDED_SOURCE_READ', 'host_name' => self::HOST_KEY,
            'host_status' => 'REGISTERED', 'host_instance_key' => self::HOST_KEY, 'host_version' => '1.0.0',
            'repository_reference_safe' => 'internal://monitor/source-read', 'licence_status_ref' => 'internal',
            'integration_map_document_ref' => 'docs/MONITOR_WORKFLOW.md', 'environment_class' => 'TEST',
            ...self::payloads(),
        ];
        foreach ($expected as $k => $v) {
            if (($r[$k] ?? null) !== $v) {
                return Outcome::refuse('SOURCE_READ_DESCRIPTOR_CONFLICT');
            }
        }
        if ($r['binding_tenant'] !== null || $r['binding_workspace'] !== null
            || json_decode((string) $r['permitted_operation_classes'], true) !== ['OBSERVE']
            || json_decode((string) $r['activation_evidence_refs'], true) !== []
            || json_decode((string) $r['declared_security_assumptions'], true) !== ['NO_EXTERNAL_EFFECT', 'SOURCE_TRANSPORT_INACTIVE_BY_DEFAULT']
            || !AdapterConformance::verify($r)) {
            return Outcome::refuse('SOURCE_READ_DESCRIPTOR_CONFLICT');
        }

        return Outcome::ok(['binding_id' => $r['binding_id'], 'runtime_host_tool_ref' => self::TOOL_REF]);
    }
}
