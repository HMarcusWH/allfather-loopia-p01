<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;

/**
 * OB-02: exact historical P01 competitor/source/capture scope.
 *
 * A source belongs to ONE native P01 competitor in ONE tenant/workspace.
 * Legacy P01 capture rows have no question identity: an explicit UNKNOWN is
 * safer than inventing a UUID or inferring P02 twin identity. This class
 * verifies owner-returned metadata, but is NOT a source-rights authority.
 */
final class CompetitorScope
{
    public const VERSION = 'ob02.competitor-source-scope.v1';
    public const QUESTION_UNKNOWN = 'NOT_RECORDED_IN_LEGACY_P01';
    private const HEX = '/^[a-f0-9]{64}$/D';

    /**
     * @param array<string,mixed> $actor Existing ObservationHistory M02 actor.
     * @param array<string,mixed> $nativeRow Obtained via scoped SQL over current native tables.
     * @param array<string,mixed> $historyRow Returned by ObservationHistory::listSnapshots.
     */
    public static function bind(array $actor, string $competitorId, array $nativeRow, array $historyRow): Outcome
    {
        foreach (['tenant_id','workspace_id','actor_type','actor_id'] as $key) {
            if (!is_string($actor[$key] ?? null) || $actor[$key] === '') {
                return Outcome::refuse('OB02_ACTOR_SCOPE_MISSING');
            }
        }
        foreach (['tenant_id','workspace_id','competitor_id','monitored_source_id',
            'source_ref_id','source_license_card_id','source_snapshot_id','evidence_id'] as $key) {
            if (!is_string($nativeRow[$key] ?? null)
                || !Ids::isValid($nativeRow[$key])) {
                return Outcome::refuse('OB02_NATIVE_IDENTITY_INVALID');
            }
        }
        if (!Ids::isValid($competitorId)
            || $nativeRow['competitor_id'] !== $competitorId
            || $nativeRow['tenant_id'] !== $actor['tenant_id']
            || $nativeRow['workspace_id'] !== $actor['workspace_id']
            || $nativeRow['monitored_source_id'] !== ($historyRow['monitored_source_id'] ?? null)
            || $nativeRow['source_snapshot_id'] !== ($historyRow['source_snapshot_id'] ?? null)
            || $nativeRow['captured_at'] !== ($historyRow['captured_at'] ?? null)
            || $nativeRow['snapshot_sequence'] != ($historyRow['snapshot_sequence'] ?? null)) {
            return Outcome::refuse('OB02_NATIVE_LINEAGE_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if ($nativeRow['evidence_source_ref_id'] !== $nativeRow['source_ref_id']
            || !in_array($nativeRow['evidence_status'], ['CAPTURED','ASSESSED'], true)) {
            return Outcome::refuse('OB02_M05_EVIDENCE_NOT_CURRENT');
        }
        if (!is_int($nativeRow['snapshot_sequence']) && !ctype_digit((string) $nativeRow['snapshot_sequence'])) {
            return Outcome::refuse('OB02_SNAPSHOT_SEQUENCE_INVALID');
        }
        if ((int) $nativeRow['snapshot_sequence'] < 1
            || !is_string($nativeRow['content_hash'])
            || preg_match(self::HEX, $nativeRow['content_hash']) !== 1
            || !is_string($nativeRow['captured_at'])
            || !Clock::isCanonical($nativeRow['captured_at'])) {
            return Outcome::refuse('OB02_SNAPSHOT_PROVENANCE_INVALID');
        }
        if (array_key_exists('question_id', $historyRow) && $historyRow['question_id'] !== null) {
            // U02 has NOT ratified a P01 historical question identity mapping.
            return Outcome::refuse('OB02_QUESTION_IDENTITY_OWNER_PROOF_MISSING');
        }
        return Outcome::ok([
            'schema_version' => self::VERSION,
            'tenant_id' => $nativeRow['tenant_id'],
            'workspace_id' => $nativeRow['workspace_id'],
            'competitor_id' => $competitorId,
            'question_id' => null,
            'question_identity' => self::QUESTION_UNKNOWN,
            'monitored_source_id' => $nativeRow['monitored_source_id'],
            'source_ref_id' => $nativeRow['source_ref_id'],
            'source_license_card_id' => $nativeRow['source_license_card_id'],
            'source_snapshot_id' => $nativeRow['source_snapshot_id'],
            'evidence_id' => $nativeRow['evidence_id'],
            'snapshot_sequence' => (int) $nativeRow['snapshot_sequence'],
            'captured_at' => $nativeRow['captured_at'],
            'p01_canonical_content_hash' => $nativeRow['content_hash'],
            'source_rights_readback' => 'CURRENT_OWNER_FILTERED_READ_ONLY',
            'source_bytes_read_here' => false,
            'source_use_for_new_model_authorized' => false,
            'u01_u02_adopted' => false,
            'customer_release_authorized' => false,
        ]);
    }
}
