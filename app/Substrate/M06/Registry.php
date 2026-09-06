<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M06;

use Allfather\Loopia\Core\Absent;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\Database;

/**
 * The M06 governed-tool registry.
 *
 * A governed tool is the AFB-named surface; its runtime mapping points at the M01 binding that
 * actually dispatches. The registry is deliberately independent of those bindings — a tool may be
 * described before any host can run it, and describing one grants nothing.
 */
final class Registry
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array{
     *     tool_key: string,
     *     display_name: string,
     *     purpose_summary: string,
     *     tool_class: string,
     *     risk_level: string,
     *     maximum_action_class: string,
     *     runtime_mapping_ref_id?: ?string,
     *     evidence_capture_required_by_default?: bool,
     *     approval_required?: bool,
     *     frozen_artifact_required?: bool,
     *     descriptor_version?: string
     * } $input
     */
    public function registerTool(array $input): string
    {
        $toolId = Ids::new();
        $this->db->insert('governed_tools', [
            'governed_tool_id' => $toolId,
            'schema_version' => '1',
            'tool_key' => $input['tool_key'],
            'display_name' => $input['display_name'],
            'purpose_summary' => $input['purpose_summary'],
            'tool_class' => $input['tool_class'],
            'risk_level' => $input['risk_level'],
            'maximum_action_class' => $input['maximum_action_class'],
            'runtime_mapping_ref_id' => $input['runtime_mapping_ref_id'] ?? null,
            'evidence_capture_required_by_default' => ($input['evidence_capture_required_by_default'] ?? false) ? 1 : 0,
            'approval_required' => ($input['approval_required'] ?? false) ? 1 : 0,
            'frozen_artifact_required' => ($input['frozen_artifact_required'] ?? false) ? 1 : 0,
            // A tool declared FORBIDDEN_IN_V1 is registered as FORBIDDEN, not ACTIVE. The
            // prohibition is part of the descriptor's identity, so it survives every later read
            // rather than depending on a caller remembering to check the class.
            'status' => $input['tool_class'] === 'FORBIDDEN_IN_V1' ? 'FORBIDDEN' : 'ACTIVE',
            'descriptor_version' => $input['descriptor_version'] ?? '1.0.0',
            'created_at' => $this->clock->now(),
        ]);

        return $toolId;
    }

    /**
     * @param array{
     *     governed_tool_id: string,
     *     operation_key: string,
     *     operation_summary?: ?string,
     *     operation_class: string,
     *     classified_action_class: string,
     *     requires_evidence_capture?: bool,
     *     requires_approval?: bool
     * } $input
     */
    public function registerOperation(array $input): string
    {
        $operationId = Ids::new();
        $this->db->insert('governed_tool_operations', [
            'governed_tool_operation_id' => $operationId,
            'schema_version' => '1',
            'governed_tool_id' => $input['governed_tool_id'],
            'operation_key' => $input['operation_key'],
            'operation_summary' => $input['operation_summary'] ?? null,
            'operation_class' => $input['operation_class'],
            'classified_action_class' => $input['classified_action_class'],
            'requires_mission' => 1,
            'requires_valkyrie_contract' => 1,
            'requires_evidence_capture' => ($input['requires_evidence_capture'] ?? false) ? 1 : 0,
            'requires_approval' => ($input['requires_approval'] ?? false) ? 1 : 0,
            'requires_frozen_artifact' => 0,
            'status' => 'ACTIVE',
            'created_at' => $this->clock->now(),
        ]);

        return $operationId;
    }

    /**
     * The canonical digest over the dispatch-determining inputs.
     *
     * M06 uses it for idempotency: an identical key with an identical digest replays the prior
     * decision; the same key with a DIFFERENT digest is `TOOL_IDEMPOTENCY_CONFLICT`, never a
     * silent second dispatch under a key the caller believed was already spent.
     *
     * @param array{
     *     governed_tool_id: string,
     *     governed_tool_operation_id: string,
     *     mission_id?: ?string,
     *     valkyrie_id?: ?string,
     *     normalized_input_ref_safe?: ?string
     * } $input
     */
    public static function inputDigest(array $input): string
    {
        // `Absent` rather than null for the optional fields. Upstream canonicalization OMITS an
        // undefined property and ENCODES null as `null`, so collapsing the two here would give a
        // different digest for the same request — and idempotency is exactly a digest comparison.
        return Digest::canonicalDigest([
            'governedToolId' => $input['governed_tool_id'],
            'governedToolOperationId' => $input['governed_tool_operation_id'],
            'missionId' => $input['mission_id'] ?? Absent::value(),
            'valkyrieId' => $input['valkyrie_id'] ?? Absent::value(),
            'normalizedInputRefSafe' => $input['normalized_input_ref_safe'] ?? Absent::value(),
        ]);
    }
}
