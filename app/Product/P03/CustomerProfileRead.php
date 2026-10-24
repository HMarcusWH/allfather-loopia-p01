<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P03;

use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Substrate\M02\AccessGuard;

/**
 * Existing customer declarations, not a scored prospect or released intelligence.
 * M02 and ACTIVE tenant subscription must independently grant customer_catalogue.read.
 * Never returns raw M05 evidence, captured content, or cross-tenant catalogues.
 */
final class CustomerProfileRead
{
    public function __construct(
        private readonly Database $db,
        private readonly AccessGuard $access,
        private readonly Entitlements $entitlements,
        private readonly Clock $clock,
    ) {}

    /** @param array{tenant_id:string,workspace_id:string,actor_type:string,actor_id:string} $scope */
    private function permission(array $scope): ?array
    {
        $permission = 'customer_catalogue.read';
        $gate = $this->access->evaluate(
            $scope['actor_type'], $scope['actor_id'], $scope['tenant_id'],
            $scope['workspace_id'], $permission, 'customer_catalogue',
        );
        if ($gate['decision'] !== 'ALLOW') {
            return ['decision'=>'DENIED','required_permission'=>$permission,
                'reason_codes'=>$gate['reason_codes'],'rows'=>[]];
        }
        if (!$this->entitlements->tenantTierGrants($scope['tenant_id'], $permission)) {
            return ['decision'=>'DENIED','required_permission'=>$permission,
                'reason_codes'=>['TIER_DOES_NOT_GRANT_PERMISSION'],'rows'=>[]];
        }
        return null;
    }

    /**
     * Read each ACTIVE profile's latest append-only version. Buyer roles are
     * role CLASSES, never named individuals or generated lead hypotheses.
     * @param array{tenant_id:string,workspace_id:string,actor_type:string,actor_id:string} $scope
     * @return array<string,mixed>
     */
    public function listDeclaredIcp(array $scope): array
    {
        if (($denied=$this->permission($scope)) !== null) return $denied;
        $rows = $this->db->all(
            "SELECT p.icp_profile_id,p.display_name,v.icp_profile_version_id,v.version_number,
                    v.industries_json,v.geographies_json,v.company_size_band,
                    v.buyer_role_classes_json,v.excluded_segments_json,
                    v.uncertainty_notes_safe,v.created_at
             FROM icp_profiles p
             JOIN icp_profile_versions v ON v.icp_profile_id=p.icp_profile_id
                 AND v.tenant_id=p.tenant_id
             WHERE p.tenant_id=? AND p.workspace_id=? AND p.status='ACTIVE'
               AND v.version_number=(
                 SELECT MAX(v2.version_number) FROM icp_profile_versions v2
                 WHERE v2.icp_profile_id=p.icp_profile_id AND v2.tenant_id=p.tenant_id
               )
             ORDER BY p.display_name,p.icp_profile_id",
            [$scope['tenant_id'],$scope['workspace_id']],
        );
        foreach ($rows as &$row) {
            foreach (['industries','geographies','buyer_role_classes','excluded_segments'] as $key) {
                $decoded=json_decode((string)$row[$key.'_json'],true,512,JSON_THROW_ON_ERROR);
                $row[$key]=is_array($decoded)&&array_is_list($decoded)
                    ?array_values(array_filter($decoded,'is_string')):[];
                unset($row[$key.'_json']);
            }
            $row['version_number']=(int)$row['version_number'];
            $row['declaration_status']='CUSTOMER_DECLARED_UNVERIFIED';
        }
        unset($row);
        return ['decision'=>'VISIBLE','required_permission'=>'customer_catalogue.read',
            'reason_codes'=>['GRANTED'],'rows'=>$rows];
    }

    /**
     * Only this workspace's ACTIVE catalogue and ACTIVE products, with current
     * provenance review status shown separately from the customer declaration.
     * @param array{tenant_id:string,workspace_id:string,actor_type:string,actor_id:string} $scope
     * @return array<string,mixed>
     */
    public function listCustomerOffers(array $scope): array
    {
        if (($denied=$this->permission($scope)) !== null) return $denied;
        $catalogues=$this->db->all(
            "SELECT c.customer_product_catalogue_id,c.display_name,c.catalogue_key,
                    lc.review_status,lc.review_expires_at,lc.data_ownership,
                    sr.status AS reference_status
             FROM customer_product_catalogues c
             JOIN source_license_cards lc ON lc.source_license_card_id=c.source_license_card_id
               AND lc.tenant_id=c.tenant_id AND lc.source_ref_id=c.source_ref_id
             JOIN source_references sr ON sr.source_ref_id=c.source_ref_id
               AND sr.tenant_id=c.tenant_id
             WHERE c.tenant_id=? AND c.workspace_id=? AND c.catalogue_status='ACTIVE'
             ORDER BY c.catalogue_key,c.customer_product_catalogue_id",
            [$scope['tenant_id'],$scope['workspace_id']],
        );
        $now=$this->clock->now();
        $rows=[];
        foreach ($catalogues as $catalogue) {
            $cardCurrent=$catalogue['review_status']==='approved'
                && $catalogue['data_ownership']==='TENANT_OWNED'
                && $catalogue['reference_status']==='ACTIVE_FOR_CAPTURE'
                && ($catalogue['review_expires_at']===null || $catalogue['review_expires_at']>$now);
            $products=$this->db->all(
                "SELECT product_key,display_name,product_offer_kind,value_proposition_safe,
                        attributes_json,product_status
                 FROM customer_products WHERE tenant_id=? AND customer_product_catalogue_id=?
                   AND product_status='ACTIVE' ORDER BY product_key",
                [$scope['tenant_id'],$catalogue['customer_product_catalogue_id']],
            );
            foreach ($products as $product) {
                $decoded=json_decode((string)$product['attributes_json'],true,512,JSON_THROW_ON_ERROR);
                $rows[]=[
                    'catalogue'=>$catalogue['display_name'],
                    'product_key'=>$product['product_key'],
                    'display_name'=>$product['display_name'],
                    'product_offer_kind'=>$product['product_offer_kind'],
                    'value_proposition_safe'=>$product['value_proposition_safe'],
                    'attributes'=>is_array($decoded)&&array_is_list($decoded)
                        ?array_values(array_filter($decoded,'is_string')):[],
                    'product_status'=>'ACTIVE',
                    'provenance_status'=>$cardCurrent?'CURRENT_APPROVED_TENANT_SOURCE':'REVIEW_REQUIRED',
                    'declaration_status'=>'CUSTOMER_PROVIDED_UNVERIFIED',
                ];
            }
        }
        return ['decision'=>'VISIBLE','required_permission'=>'customer_catalogue.read',
            'reason_codes'=>['GRANTED'],'rows'=>$rows];
    }
}
