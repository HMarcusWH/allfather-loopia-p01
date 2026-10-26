<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M17;

use Allfather\Loopia\Core\Outcome;

/**
 * LI-07B read-only P01 owner-view bridge; no source rights or retained bytes.
 * The injected read MUST be the existing ObservationHistory::listSnapshots owner.
 * This is NOT registered as a customer/operator route or an InputSet producer.
 */
final class P01HistoryAdapter
{
    public function __construct(private readonly \Closure $ownerRead) {}

    /** @param array<string,mixed> $actor */
    public function inspect(array $actor, string $competitorId, int $limit=20): Outcome
    {
        if (!Vocabulary::id($competitorId) || $limit<1 || $limit>50
            || !isset($actor['tenant_id'],$actor['workspace_id'],$actor['actor_type'],$actor['actor_id'])) {
            return Outcome::refuse('M17_P01_READ_SELECTOR_INVALID');
        }
        try {
            // Reuses the M02, entitlement and M05 checks owned by ObservationHistory.
            $read=($this->ownerRead)($actor,$competitorId,$limit);
            if (!is_array($read) || ($read['decision']??null)!=='VISIBLE'
                || ($read['required_permission']??null)!=='evidence.read'
                || ($read['data']['read_only']??null)!==true
                || ($read['data']['source_rights']??null)!=='CURRENT_ELIGIBILITY_CHECKED'
                || !is_array($read['data']['rows']??null)
                || !array_is_list($read['data']['rows'])
                || count($read['data']['rows'])>$limit) {
                return Outcome::refuse('M17_P01_OWNER_READ_UNAVAILABLE');
            }
            $projection=[];
            foreach ($read['data']['rows'] as $row) {
                if (!is_array($row) || !Vocabulary::id($row['source_snapshot_id']??null)
                    || !Vocabulary::utc(self::seconds($row['captured_at']??null))
                    || !is_array($row['comparisons']??null)) {
                    return Outcome::refuse('M17_P01_OWNER_READ_INVALID');
                }
                $ids=[];
                foreach($row['comparisons'] as $diff){
                    if(!is_array($diff)||!Vocabulary::id($diff['source_diff_id']??null)) {
                        return Outcome::refuse('M17_P01_OWNER_READ_INVALID');
                    }
                    $ids[]=$diff['source_diff_id'];
                }
                $projection[]=[
                    'source_snapshot_id'=>$row['source_snapshot_id'],
                    'captured_at'=>$row['captured_at'],
                    'coverage_state'=>$row['coverage_state']??'UNKNOWN',
                    'comparison_ref_ids'=>$ids,
                    'payload_sha256'=>null, // Owner-safe history explicitly does not expose capture bytes/digest.
                ];
            }
            return Outcome::ok([
                'status'=>'OWNER_READ_METADATA_ONLY','rows'=>$projection,
                'input_set_frozen'=>false,'historical_reuse_admitted'=>false,
                'current_source_rights_checked_by_owner'=>true,
                'historical_use_rights_checked'=>false,
            ]);
        } catch (\Throwable) {
            return Outcome::refuse('M17_P01_OWNER_READ_UNAVAILABLE');
        }
    }

    private static function seconds(mixed $at): mixed
    {
        return is_string($at) && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{3})?Z$/D',$at)===1
            ? substr($at,0,19).'Z' : null;
    }
}
