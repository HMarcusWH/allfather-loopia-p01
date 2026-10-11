<?php

declare(strict_types=1);

/**
 * Explicitly scoped, replay-safe TEST business-record bootstrap.
 * The private input is NOT a runtime seed or an authority grant.
 *
 * plan|verify --env=... --manifest=/private/customer.json --manifest-sha256=<sha256>
 *     --sections=company,icp,competitors[,catalogue] [--catalogue-card-id=<reviewed id>]
 * apply: same flags + --plan-digest=<64 hex> --ack=CREATE_TEST_BUSINESS_RECORDS
 *
 * Never submits synthetic signals, approvals, model calls, M10 releases or P05 drafts.
 * Existing records are REUSED only after verifying the exact intended content.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require __DIR__ . '/../app/autoload.php';

use Allfather\Loopia\Core\{Clock,Digest,Outcome};
use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\Product\P01\Registry as CompetitorRegistry;
use Allfather\Loopia\Product\P02\EntityTwins;
use Allfather\Loopia\Product\P03\{Catalogue,Icp};

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'customer-bootstrap: '.$message.PHP_EOL); exit($code);
};
$command=$argv[1] ?? '';
if (!in_array($command,['plan','apply','verify'],true)) $fail('expected plan|apply|verify');
$args=BetaCli::parseArgs([$argv[0],...array_slice($argv,2)],
    ['env','manifest','manifest-sha256','sections','catalogue-card-id','plan-digest','ack'],$fail);
foreach (['env','manifest','manifest-sha256','sections'] as $needed) {
    if (!isset($args[$needed]) || in_array($args[$needed],['','1'],true)) $fail("--{$needed} required");
}
$root=dirname(__DIR__);
$input=realpath($args['manifest']); $web=realpath($root.'/public_html');
if ($input===false || !is_file($input) || filesize($input)>131072 || filesize($input)===0
    || ($web!==false && str_starts_with($input,$web.DIRECTORY_SEPARATOR))
    || !preg_match('/^[a-f0-9]{64}$/D',$args['manifest-sha256'])) $fail('invalid private input');
$bytes=file_get_contents($input);
if (!is_string($bytes) || !hash_equals($args['manifest-sha256'],hash('sha256',$bytes))) $fail('input hash mismatch',4);
try { $data=json_decode($bytes,true,24,JSON_THROW_ON_ERROR); }
catch (Throwable $e) { $fail('invalid JSON',4); }
if (!is_array($data) || ($data['schema']??null)!=='allfather-customer-bootstrap-v1'
    || !is_array($data['scope']??null) || !is_array($data['company']??null)
    || !is_array($data['icp']??null) || !is_array($data['offers']??null)
    || !is_array($data['competitors']??null)) $fail('invalid manifest schema',4);
$sections=array_values(array_unique(explode(',',$args['sections'])));
$allowed=['company','icp','competitors','catalogue'];
if ($sections===[] || count(array_diff($sections,$allowed)) || in_array('',$sections,true)) $fail('unknown sections');
if ($command==='apply' && (($args['ack']??'')!=='CREATE_TEST_BUSINESS_RECORDS'
    || !preg_match('/^[a-f0-9]{64}$/D',$args['plan-digest']??''))) $fail('apply acknowledgement/digest required');
if ($command!=='apply' && (isset($args['plan-digest']) || isset($args['ack']))) $fail('plan and verify must not receive write flags');
$config=BetaCli::configuration($root,['env'=>$args['env']],$fail);
if (!in_array($config['environment'],['testing','staging'],true)) $fail('TEST runtime required',4);
$db=BetaCli::connect($config['env'],$fail);
$scope=$data['scope'];
foreach (['tenant_id','workspace_id','operator_id','evidence_id','evidence_sha256','source_ref_id'] as $k) {
    if (!is_string($scope[$k]??null) || trim($scope[$k])==='') $fail("missing manifest scope {$k}");
}
if (!preg_match('/^[a-f0-9]{64}$/D',$scope['evidence_sha256'])) $fail('invalid evidence SHA256');
$tenant=$scope['tenant_id'];$workspace=$scope['workspace_id'];$operator=$scope['operator_id'];
$company=$data['company'];$icp=$data['icp'];$offers=$data['offers'];$competitors=$data['competitors'];
if (($company['entity_kind']??null)!=='OWN_COMPANY' || ($company['claim_ceiling']??null)!=='INTERNAL_DRAFT'
    || !is_string($company['display_name']??null) || !is_array($company['attributes']??null)
    || !is_array($company['fields']??null) || !is_string($company['uncertainty']??null)
    || trim($company['uncertainty'])==='') $fail('invalid own-company record');
if (!is_string($icp['display_name']??null) || !is_array($icp['industries']??null)
    || !is_array($icp['geographies']??null) || !is_array($icp['buyer_role_classes']??null)
    || trim((string)($icp['uncertainty_notes_safe']??''))==='') $fail('invalid primary ICP');
foreach ($company['fields'] as $field) {
    if (!is_array($field) || !is_string($field['field_key']??null)
        || !in_array($field['value_status']??null,['TENANT_PROVIDED','UNKNOWN','INFERRED_HYPOTHESIS'],true)
        || (!is_string($field['field_value_safe']??null) && ($field['field_value_safe']??null)!==null)) $fail('invalid twin field');
}
foreach ($offers as $offer) {
    if (!is_string($offer['key']??null) || !is_string($offer['name']??null)
        || !in_array($offer['kind']??null,['SERVICE','PRODUCT','BUNDLE','VALUE_PROPOSITION'],true)
        || !is_array($offer['attributes']??null) || $offer['attributes'] === []
        || !is_string($offer['value_proposition_safe']??null)) $fail('invalid offer');
}
foreach ($competitors as $competitor) {
    if (!is_string($competitor['name']??null) || !in_array($competitor['class']??null,
        ['DIRECT','INDIRECT','ADJACENT','MARKET_REFERENCE','OTHER'],true)) $fail('invalid competitor');
}
if (count($offers)!==count(array_unique(array_column($offers,'key')))
    || count($competitors)!==count(array_unique(array_column($competitors,'name')))) $fail('duplicate manifest items');

$plan=static function () use ($db,$scope,$tenant,$workspace,$operator,$company,$icp,$offers,$competitors,$sections,$args): array {
    $w=$db->one('SELECT t.tenant_key,t.tenant_type,t.status AS tenant_status,
        w.workspace_key,w.workspace_type,w.environment_class,w.status AS workspace_status,
        w.external_side_effects_allowed,w.active_v1_capability_ceiling,o.status AS operator_status
        FROM tenants t JOIN workspaces w ON w.tenant_id=t.tenant_id
        JOIN operators o ON o.tenant_id=t.tenant_id AND o.operator_id=?
        WHERE t.tenant_id=? AND w.workspace_id=?',[$operator,$tenant,$workspace]);
    if ($w===null || $w['tenant_type']!=='TEST' || $w['workspace_type']!=='TEST'
        || $w['environment_class']!=='TEST' || $w['tenant_status']!=='ACTIVE'
        || $w['workspace_status']!=='ACTIVE' || $w['operator_status']!=='ACTIVE'
        || (int)$w['external_side_effects_allowed']!==0
        || $w['active_v1_capability_ceiling']!=='RELEASE_INTELLIGENCE') throw new RuntimeException('TEST_SCOPE_INVALID');
    if (($scope['tenant_key']??null)!==$w['tenant_key'] || ($scope['workspace_key']??null)!==$w['workspace_key']) throw new RuntimeException('SCOPE_KEYS_CHANGED');
    $ev=$db->one('SELECT e.evidence_id,e.source_ref_id,e.content_hash,e.evidence_status,e.evidence_type,
        sr.source_type,sr.status AS source_status,sr.workspace_id AS source_workspace
        FROM evidence_objects e JOIN source_references sr ON sr.source_ref_id=e.source_ref_id
        WHERE e.tenant_id=? AND e.workspace_id=? AND e.evidence_id=? AND sr.tenant_id=?',
        [$tenant,$workspace,$scope['evidence_id'],$tenant]);
    if ($ev===null || $ev['content_hash']!==$scope['evidence_sha256']
        || $ev['source_ref_id']!==$scope['source_ref_id'] || $ev['source_workspace']!==$workspace
        || $ev['evidence_status']!=='CAPTURED' || $ev['evidence_type']!=='UPLOADED_SOURCE_CAPTURE'
        || $ev['source_type']!=='UPLOADED_ARTIFACT' || $ev['source_status']!=='ACTIVE_FOR_CAPTURE') throw new RuntimeException('EVIDENCE_SCOPE_OR_HASH_MISMATCH');
    $classBySection=['company'=>'ENTITY_TWIN_CURATOR_VALKYRIE','icp'=>'LEAD_QUALIFIER_VALKYRIE',
        'competitors'=>'COMPETITION_MONITOR_VALKYRIE','catalogue'=>'LEAD_QUALIFIER_VALKYRIE'];
    foreach (array_unique(array_map(static fn($section)=>$classBySection[$section],$sections)) as $roleClass) {
        $registrations=$db->all('SELECT v.valkyrie_id,v.status,v.active_capability_contract_id,
            c.status AS contract_status,c.role_class AS contract_role
            FROM valkyries v LEFT JOIN capability_contracts c
                ON c.capability_contract_id=v.active_capability_contract_id
            WHERE v.tenant_id=? AND v.workspace_id=? AND v.canonical_role_class=?',
            [$tenant,$workspace,$roleClass]);
        if (count($registrations)!==1 || $registrations[0]['status']!=='ACTIVE'
            || $registrations[0]['contract_status']!=='ACTIVE'
            || $registrations[0]['contract_role']!==$roleClass) {
            throw new RuntimeException('MISSING_OR_INVALID_PRODUCT_REGISTRATION:'.$roleClass);
        }
    }
    $required=['tenant.administer'];
    if (in_array('company',$sections,true)) $required[]='entity_twin.propose_state_change';
    if (in_array('icp',$sections,true)||in_array('catalogue',$sections,true)) $required[]='customer_catalogue.upload';
    if (in_array('competitors',$sections,true)) $required[]='company_signal.review';
    $perms=array_column($db->all("SELECT DISTINCT rp.permission_key FROM workspace_memberships m
        JOIN roles r ON r.role_id=m.role_id AND r.status='ACTIVE' AND r.role_version=1
        JOIN role_permissions rp ON rp.role_id=r.role_id
        WHERE m.tenant_id=? AND m.workspace_id=? AND m.principal_id=?
        AND m.principal_type='OPERATOR' AND m.status='ACTIVE' AND m.valid_from<=?
        AND (m.valid_until IS NULL OR m.valid_until>?)", [$tenant,$workspace,$operator,gmdate('Y-m-d\TH:i:s.000\Z'),gmdate('Y-m-d\TH:i:s.000\Z')]), 'permission_key');
    $missing=array_values(array_diff(array_unique($required),$perms));
    $result=['company'=>[],'icp'=>[],'catalogue'=>[],'competitors'=>[]];
    if (in_array('company',$sections,true)) {
        $twins=$db->all("SELECT entity_twin_id,display_name,legal_name,domain,status FROM entity_twins
            WHERE tenant_id=? AND workspace_id=? AND entity_kind='OWN_COMPANY' AND status<>'RETIRED'",[$tenant,$workspace]);
        if ($twins===[]) $result['company']=['action'=>'CREATE'];
        elseif (count($twins)!==1 || $twins[0]['display_name']!==$company['display_name']
            || $twins[0]['legal_name']!==($company['attributes']['legal_name']??null)
            || $twins[0]['domain']!==($company['attributes']['domain']??null)
            || $twins[0]['status']!=='ACTIVE') $result['company']=['action'=>'BLOCK','reason'=>'EXISTING_TWIN_CONFLICT'];
        else {
            $id=$twins[0]['entity_twin_id'];
            $versions=$db->all('SELECT entity_twin_version_id,claim_ceiling FROM entity_twin_versions
                WHERE tenant_id=? AND workspace_id=? AND entity_twin_id=? ORDER BY version_sequence DESC',[$tenant,$workspace,$id]);
            if ($versions===[]) $result['company']=['action'=>'APPEND_VERSION','entity_twin_id'=>$id];
            else {
                $last=$versions[0];
                $saved=$db->all('SELECT field_key,field_value_safe,value_status FROM entity_twin_state_summaries
                    WHERE tenant_id=? AND entity_twin_version_id=? ORDER BY field_key',[$tenant,$last['entity_twin_version_id']]);
                $wanted=$company['fields'];usort($wanted,static fn($a,$b)=>strcmp($a['field_key'],$b['field_key']));
                $link=$db->one('SELECT 1 AS ok FROM entity_twin_source_links WHERE tenant_id=?
                    AND entity_twin_version_id=? AND evidence_id=?',[$tenant,$last['entity_twin_version_id'],$scope['evidence_id']]);
                $result['company']=($saved===$wanted && $last['claim_ceiling']==='INTERNAL_DRAFT' && $link!==null)
                    ? ['action'=>'REUSE','entity_twin_id'=>$id] : ['action'=>'BLOCK','reason'=>'TWIN_VERSION_CONFLICT'];
            }
        }
    }
    if (in_array('icp',$sections,true)) {
        $profiles=$db->all("SELECT icp_profile_id,display_name FROM icp_profiles WHERE tenant_id=? AND workspace_id=? AND status='ACTIVE'",[$tenant,$workspace]);
        if ($profiles===[]) $result['icp']=['action'=>'CREATE'];
        elseif (count($profiles)!==1 || $profiles[0]['display_name']!==$icp['display_name']) $result['icp']=['action'=>'BLOCK','reason'=>'MULTIPLE_OR_DIFFERENT_ACTIVE_ICP'];
        else {
            $p=$profiles[0];$version=$db->one('SELECT * FROM icp_profile_versions WHERE tenant_id=? AND icp_profile_id=? ORDER BY version_number DESC LIMIT 1',[$tenant,$p['icp_profile_id']]);
            $match=$version!==null && (json_decode($version['industries_json'],true)===array_values($icp['industries']))
                && (json_decode($version['geographies_json'],true)===array_values($icp['geographies']))
                && (json_decode($version['buyer_role_classes_json'],true)===array_values($icp['buyer_role_classes']))
                && (json_decode($version['excluded_segments_json'],true)===array_values($icp['excluded_segments']))
                && $version['company_size_band']===($icp['company_size_band']??null)
                && $version['uncertainty_notes_safe']===$icp['uncertainty_notes_safe'];
            $result['icp']=$match?['action'=>'REUSE','icp_profile_id'=>$p['icp_profile_id']]
                : ['action'=>'BLOCK','reason'=>'ICP_VERSION_CONFLICT'];
        }
    }
    if (in_array('competitors',$sections,true)) {
        foreach ($competitors as $item) {
            $rows=$db->all('SELECT competitor_id,competitor_class,status FROM competitors
                WHERE tenant_id=? AND workspace_id=? AND display_name=?',[$tenant,$workspace,$item['name']]);
            $result['competitors'][$item['name']]=$rows===[] ? ['action'=>'CREATE_DRAFT','class'=>$item['class']]
                : (count($rows)===1 && $rows[0]['competitor_class']===$item['class']
                    && in_array($rows[0]['status'],['DRAFT','ACTIVE','PAUSED'],true)
                    ? ['action'=>'REUSE','competitor_id'=>$rows[0]['competitor_id']]
                    : ['action'=>'BLOCK','reason'=>'COMPETITOR_CONFLICT']);
        }
    }
    if (in_array('catalogue',$sections,true)) {
        $cardId=$args['catalogue-card-id']??null;
        $card=$cardId===null?null:$db->one('SELECT c.source_ref_id,c.data_ownership,c.review_status,
            r.status AS source_status FROM source_license_cards c
            JOIN source_references r ON r.source_ref_id=c.source_ref_id
            WHERE c.source_license_card_id=? AND c.tenant_id=? AND r.tenant_id=?',[$cardId,$tenant,$tenant]);
        if ($card===null || $card['data_ownership']!=='TENANT_OWNED'
            || $card['review_status']!=='approved' || $card['source_status']!=='ACTIVE_FOR_CAPTURE') {
            $result['catalogue']=['action'=>'BLOCK','reason'=>'APPROVED_TENANT_OWNED_CARD_REQUIRED'];
        } else {
            $catalogues=$db->all('SELECT customer_product_catalogue_id,display_name,source_license_card_id,source_ref_id,catalogue_status
                FROM customer_product_catalogues WHERE tenant_id=? AND workspace_id=? AND catalogue_key=?',
                [$tenant,$workspace,'plus46-offers-v1']);
            if ($catalogues===[]) $result['catalogue']=['action'=>'CREATE','card_id'=>$cardId,'source_ref_id'=>$card['source_ref_id']];
            elseif (count($catalogues)!==1 || $catalogues[0]['source_license_card_id']!==$cardId
                || $catalogues[0]['source_ref_id']!==$card['source_ref_id'] || $catalogues[0]['catalogue_status']!=='ACTIVE') {
                $result['catalogue']=['action'=>'BLOCK','reason'=>'CATALOGUE_CONFLICT'];
            } else {
                $catId=$catalogues[0]['customer_product_catalogue_id'];$products=[];
                foreach ($offers as $offer) {
                    $rows=$db->all('SELECT customer_product_id,display_name,product_offer_kind,attributes_json,value_proposition_safe,product_status
                        FROM customer_products WHERE tenant_id=? AND customer_product_catalogue_id=? AND product_key=?',[$tenant,$catId,$offer['key']]);
                    $products[$offer['key']]=$rows===[]?['action'=>'CREATE']
                        :(count($rows)===1 && $rows[0]['display_name']===$offer['name']
                            && $rows[0]['product_offer_kind']===$offer['kind']
                            && $rows[0]['value_proposition_safe']===$offer['value_proposition_safe']
                            && json_decode($rows[0]['attributes_json'],true)===array_values($offer['attributes'])
                            && $rows[0]['product_status']==='ACTIVE'
                            ? ['action'=>'REUSE'] : ['action'=>'BLOCK','reason'=>'PRODUCT_CONFLICT']);
                }
                $result['catalogue']=['action'=>'REUSE','catalogue_id'=>$catId,'products'=>$products];
            }
        }
    }
    $blocked=[];foreach ($result as $name=>$value) {
        if (($value['action']??null)==='BLOCK') $blocked[]=$name.':'.$value['reason'];
        if ($name==='competitors') foreach ($value as $candidate=>$state)
            if (($state['action']??'')==='BLOCK') $blocked[]='competitor:'.$candidate;
        if ($name==='catalogue' && isset($value['products'])) foreach ($value['products'] as $key=>$state)
            if ($state['action']==='BLOCK') $blocked[]='product:'.$key;
    }
    foreach ($missing as $key) $blocked[]='permission:'.$key;
    $digest=Digest::canonicalDigest(['scope'=>$scope,'sections'=>$sections,'intended'=>$result,
        'missing_permissions'=>$missing,'manifest_hash'=>hash('sha256',file_get_contents($args['manifest']))]);
    return ['sections'=>$sections,'intended'=>$result,'blocked_by'=>$blocked,'plan_digest'=>$digest,
        'required_permissions'=>$required,'missing_permissions'=>$missing];
};
try { $before=$plan(); } catch(Throwable $e) { $fail('plan refused: '.$e->getMessage(),4); }
if ($command!=='apply') BetaCli::emit(['status'=>$command==='plan'?'DRY_RUN_OK':'INSPECTED',...$before,'writes_performed'=>0]);
if ($before['blocked_by']!==[]) $fail('plan blocked: '.implode(',',$before['blocked_by']),5);
if (!hash_equals($before['plan_digest'],$args['plan-digest'])) $fail('plan digest changed',5);
try {
$out=$db->transaction(static function () use ($db,$plan,$args,$tenant,$workspace,$operator,$scope,$company,$icp,$offers,$competitors,$sections): Outcome {
    $w=$db->one('SELECT workspace_id FROM workspaces WHERE tenant_id=? AND workspace_id=? FOR UPDATE',[$tenant,$workspace]);
    if ($w===null) return Outcome::refuse('WORKSPACE_GONE');
    $review=$plan();
    if ($review['blocked_by']!==[] || !hash_equals($review['plan_digest'],$args['plan-digest'])) return Outcome::refuse('PLAN_STALE');
    $clock=new Clock();$created=[];$actions=$review['intended'];
    if (in_array('company',$sections,true)) {
        $twins=new EntityTwins($db,$clock);$action=$actions['company']['action'];
        if ($action==='CREATE') {
            $res=$twins->registerTwin($tenant,$workspace,'OWN_COMPANY',$company['display_name'],
                $company['attributes'],$operator);
            if (!$res->isOk()) return $res;
            $twinId=$res->value()['entity_twin_id'];
        } else $twinId=$actions['company']['entity_twin_id']??null;
        if (in_array($action,['CREATE','APPEND_VERSION'],true)) {
            $res=$twins->appendVersion(['tenant_id'=>$tenant,'workspace_id'=>$workspace,
                'entity_twin_id'=>$twinId,'claim_ceiling'=>'INTERNAL_DRAFT',
                'fields'=>$company['fields'],'uncertainty'=>['summary_safe'=>$company['uncertainty']],
                'source_links'=>[['evidence_id'=>$scope['evidence_id'],
                    'source_ref_id'=>$scope['source_ref_id'],'source_basis'=>'TENANT_PROVIDED_MANUAL',
                    'allowed_use'=>'INTERNAL_ONLY','license_basis_safe'=>'Customer-provided profile; internal TEST only']]]);
            if (!$res->isOk()) return $res;
            $created[]='company_version';
        }
    }
    if (in_array('icp',$sections,true) && $actions['icp']['action']==='CREATE') {
        $res=(new Icp($db,$clock))->declareVersion($tenant,$workspace,$icp['display_name'],
            ['industries'=>$icp['industries'],'geographies'=>$icp['geographies'],
             'company_size_band'=>$icp['company_size_band']??null,
             'buyer_role_classes'=>$icp['buyer_role_classes'],
             'excluded_segments'=>$icp['excluded_segments'],
             'uncertainty_notes_safe'=>$icp['uncertainty_notes_safe']]);
        if (!$res->isOk()) return $res;
        $created[]='icp';
    }
    if (in_array('competitors',$sections,true)) {
        $registry=new CompetitorRegistry($db,$clock);
        foreach ($competitors as $candidate) {
            if ($actions['competitors'][$candidate['name']]['action']!=='CREATE_DRAFT') continue;
            $res=$registry->registerCompetitor($tenant,$workspace,$candidate['name'],
                $candidate['class'],'OPERATOR',$operator,null,'DRAFT');
            if (!$res->isOk()) return $res;
            $created[]='competitor_draft';
        }
    }
    if (in_array('catalogue',$sections,true)) {
        $catalogue=new Catalogue($db,$clock);$record=$actions['catalogue'];
        if ($record['action']==='CREATE') {
            $res=$catalogue->registerCatalogue($tenant,$workspace,'plus46-offers-v1','Plus 46 Studio — Customer Offers',
                $record['source_ref_id'],$record['card_id']);
            if (!$res->isOk()) return $res;
            $catId=$res->value()['customer_product_catalogue_id'];$created[]='catalogue';
        } else $catId=$record['catalogue_id'];
        foreach ($offers as $offer) {
            if ($record['action']==='REUSE' && $record['products'][$offer['key']]['action']!=='CREATE') continue;
            $res=$catalogue->addProduct($catId,$offer['key'],$offer['name'],$offer['kind'],
                $offer['attributes'],$offer['value_proposition_safe'],'ACTIVE');
            if (!$res->isOk()) return $res;
            $created[]='offer';
        }
    }
    return Outcome::ok(['created'=>$created]);
});
} catch (Throwable $e) { $fail('atomic write failed (rolled back)',5); }
if (!$out->isOk()) $fail('atomic write refused: '.$out->reasonCode(),5);
try { $after=$plan(); } catch(Throwable $e) { $fail('postwrite verification failed',6); }
BetaCli::emit(['status'=>'APPLIED','created'=>$out->value()['created'],
    'postwrite'=>$after,'wrote_releases'=>false,'wrote_model_outputs'=>false,
    'external_effects_authorized'=>false]);
