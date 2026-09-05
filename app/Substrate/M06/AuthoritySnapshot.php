<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M06;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Db\Database;

/**
 * The governed descriptor surface that authorized a dispatch.
 *
 * M06 reads it when it mints the dispatch admission; M01 RE-READS it from the live rows at dispatch
 * time and again after the callback, and refuses on any difference. Every field here is one a
 * descriptor edit between authorization and execution could change, so the digest is authority —
 * not decoration.
 */
final class AuthoritySnapshot
{
    /**
     * The digest M06 binds into an admission when the descriptor surface cannot be read at all.
     *
     * It can never equal a real snapshot digest, so an unreadable descriptor fails closed at M01
     * rather than being skipped.
     */
    public const UNREADABLE = 'authority-snapshot:unreadable';

    /**
     * A null runtime mapping is a REAL descriptor state, so it projects to a stable token rather
     * than being dropped: removing the mapping must change the digest. The token begins with an
     * escaped NUL, which the governed-identifier law forbids in any real id, so it cannot collide
     * with one.
     */
    private const NULL_MAPPING = "\u{0000}NULL";

    /** @return array<string, string|int>|null */
    public static function read(Database $db, string $governedToolId, string $governedToolOperationId): ?array
    {
        $row = $db->one(
            'SELECT t.governed_tool_id, t.status AS tool_status, t.tool_class, t.risk_level,
                    t.maximum_action_class, t.runtime_mapping_ref_id, t.descriptor_version,
                    t.approval_required, t.frozen_artifact_required,
                    t.evidence_capture_required_by_default,
                    o.governed_tool_operation_id, o.operation_key,
                    o.governed_tool_id AS operation_owner_id, o.status AS operation_status,
                    o.operation_class, o.classified_action_class, o.requires_mission,
                    o.requires_valkyrie_contract, o.requires_evidence_capture, o.requires_approval,
                    o.requires_frozen_artifact
             FROM governed_tools t
             JOIN governed_tool_operations o ON o.governed_tool_operation_id = ?
             WHERE t.governed_tool_id = ?',
            [$governedToolOperationId, $governedToolId]
        );
        if ($row === null) {
            return null;
        }

        return [
            'governedToolId' => (string) $row['governed_tool_id'],
            'toolStatus' => (string) $row['tool_status'],
            'toolClass' => (string) $row['tool_class'],
            'riskLevel' => (string) $row['risk_level'],
            'maximumActionClass' => (string) $row['maximum_action_class'],
            'runtimeMappingRefId' => $row['runtime_mapping_ref_id'] === null
                ? self::NULL_MAPPING
                : (string) $row['runtime_mapping_ref_id'],
            'descriptorVersion' => (string) $row['descriptor_version'],
            'approvalRequired' => (int) $row['approval_required'],
            'frozenArtifactRequired' => (int) $row['frozen_artifact_required'],
            'evidenceCaptureRequiredByDefault' => (int) $row['evidence_capture_required_by_default'],
            'governedToolOperationId' => (string) $row['governed_tool_operation_id'],
            'operationKey' => (string) $row['operation_key'],
            'operationOwnerId' => (string) $row['operation_owner_id'],
            'operationStatus' => (string) $row['operation_status'],
            'operationClass' => (string) $row['operation_class'],
            'classifiedActionClass' => (string) $row['classified_action_class'],
            'requiresMission' => (int) $row['requires_mission'],
            'requiresValkyrieContract' => (int) $row['requires_valkyrie_contract'],
            'requiresEvidenceCapture' => (int) $row['requires_evidence_capture'],
            'requiresApproval' => (int) $row['requires_approval'],
            'requiresFrozenArtifact' => (int) $row['requires_frozen_artifact'],
        ];
    }

    /** @param array<string, string|int> $snapshot */
    public static function digest(array $snapshot): string
    {
        return Digest::canonicalDigest($snapshot);
    }

    public static function digestFor(Database $db, string $governedToolId, string $governedToolOperationId): string
    {
        $snapshot = self::read($db, $governedToolId, $governedToolOperationId);

        return $snapshot === null ? self::UNREADABLE : self::digest($snapshot);
    }
}
