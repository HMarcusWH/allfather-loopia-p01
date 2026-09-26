<?php
declare(strict_types=1);
namespace Allfather\Loopia\Product\P01;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Substrate\M05\SourceAdmission;

/** Canonical PR367: admitted owner upload context stays distinct from observed competitor evidence. */
final class CompanyContext
{
    public static function read(Database $db, string $id, string $tenantId, string $workspaceId): ?array
    {
        $row = $db->one('SELECT e.evidence_id,e.source_ref_id,e.evidence_type,e.evidence_status,e.permitted_use_scope,e.sensitivity_class,e.admitting_source_status,
            sr.tenant_id,sr.workspace_id,sr.source_type,sr.status,sr.lawful_or_permitted_use_scope,sr.content_sensitivity_class
            FROM evidence_objects e JOIN source_references sr ON sr.source_ref_id=e.source_ref_id
            WHERE e.evidence_id=? AND e.tenant_id=? AND e.workspace_id=?',[$id,$tenantId,$workspaceId]);
        if ($row === null || $row['workspace_id'] !== $workspaceId || $row['evidence_type'] !== 'UPLOADED_SOURCE_CAPTURE'
            || $row['source_type'] !== 'UPLOADED_ARTIFACT' || $row['admitting_source_status'] !== 'ACTIVE_FOR_CAPTURE'
            || !in_array($row['sensitivity_class'],['PUBLIC_OR_NON_SENSITIVE','INTERNAL_DEMO','RESTRICTED_REVIEW'],true)
            || !in_array($row['evidence_status'],['CAPTURED','ASSESSED'],true)
            || trim((string)$row['lawful_or_permitted_use_scope']) === ''
            || !SourceAdmission::evaluate($row,$tenantId,$workspaceId,(string)$row['permitted_use_scope'],(string)$row['sensitivity_class'])['admitted']) { return null; }
        return $row;
    }

    /** Build separate M16 contributions; the upload does not inherit the public source's licence. */
    public static function contributions(Database $db, string $tenantId, string $workspaceId, string $primarySourceRef, array $evidenceIds): Outcome
    {
        $primary = $db->one('SELECT slc.*,sr.status AS source_status,sr.workspace_id AS source_workspace,sr.content_sensitivity_class
            FROM source_license_cards slc JOIN source_references sr ON sr.source_ref_id=slc.source_ref_id
            WHERE slc.source_ref_id=? AND slc.tenant_id=? AND sr.tenant_id=?',[$primarySourceRef,$tenantId,$tenantId]);
        if ($primary === null || ($primary['source_workspace'] !== null && $primary['source_workspace'] !== $workspaceId)) {
            return Outcome::refuse('ARTIFACT_SOURCE_LINEAGE_MISSING',[],'PROVENANCE');
        }
        $contexts=[]; $primaryEvidence=[]; $usable=true;
        $sensitivity=(string)$primary['content_sensitivity_class'];
        $order=['PUBLIC_OR_NON_SENSITIVE','INTERNAL_DEMO','RESTRICTED_REVIEW','PROHIBITED_IN_V1'];
        foreach (array_values(array_unique($evidenceIds)) as $id) {
            $e = $db->one('SELECT source_ref_id,evidence_status,sensitivity_class FROM evidence_objects WHERE evidence_id=? AND tenant_id=? AND workspace_id=?',[$id,$tenantId,$workspaceId]);
            if ($e === null) { return Outcome::refuse('EVIDENCE_SCOPE_MISMATCH',[],'SCOPE'); }
            if ($e['source_ref_id'] !== $primarySourceRef) {
                $context=self::read($db,$id,$tenantId,$workspaceId);
                if ($context === null) {return Outcome::refuse('CONTEXT_EVIDENCE_NOT_ADMISSIBLE',[],'EVIDENCE');}
                $contexts[]=['source_license_card_id'=>null,'source_ref_id'=>$context['source_ref_id'],'evidence_ids'=>[$id],
                    // Existing M16 first-party lattice top, not a new external licence assertion.
                    'license_ceiling'=>'OPEN_CC0','customer_output_allowed'=>false,'derived_summary_allowed'=>true,
                    'excerpt_customer_visible'=>false,'sensitivity_class'=>$context['sensitivity_class'],'retention_class'=>'standard',
                    'citation_policy'=>['OPERATOR_ONLY'=>'FULL_CITATION','TENANT_INTERNAL'=>'FULL_CITATION'],
                    'data_ownership'=>'TENANT_OWNED','card_state'=>null,'evidence_usable'=>true];
                continue;
            }
            $primaryEvidence[]=$id;
            if (in_array($e['evidence_status'],['QUARANTINED','REVOKED_FOR_USE'],true)) {$usable=false;}
            $rank=array_search($e['sensitivity_class'],$order,true);$current=array_search($sensitivity,$order,true);
            $sensitivity=$order[max($rank===false?2:$rank,$current===false?2:$current)];
        }
        $contribution=['source_license_card_id'=>$primary['source_license_card_id'],'source_ref_id'=>$primarySourceRef,'evidence_ids'=>$primaryEvidence,
            'license_ceiling'=>$primary['license_class'],'customer_output_allowed'=>(int)$primary['customer_output_allowed']===1,
            'derived_summary_allowed'=>(int)$primary['derived_summary_allowed']===1,'excerpt_customer_visible'=>(int)($primary['raw_excerpt_customer_visible']??0)===1,
            'sensitivity_class'=>$sensitivity,'retention_class'=>$primary['retention_class'],
            'citation_policy'=>['OPERATOR_ONLY'=>'FULL_CITATION','TENANT_INTERNAL'=>'FULL_CITATION'],
            'data_ownership'=>$primary['data_ownership'],'card_state'=>['approved'=>$primary['review_status']==='approved' && $primary['source_status']==='ACTIVE_FOR_CAPTURE','review_expires_at'=>$primary['review_expires_at']],
            'evidence_usable'=>$usable];
        return Outcome::ok(['contributions'=>[$contribution,...$contexts]]);
    }
}
