<?php

declare(strict_types=1);

use Allfather\Loopia\Product\P01\SourceOriginGroups;
use Allfather\Loopia\Tests\Assert;

$scope=['tenant_id'=>'tenant-01','workspace_id'=>'workspace-01'];
$cap=static fn(string $id,string $source,string $body):array=>[
    'capture_id'=>$id,'source_ref_id'=>$source,
    'payload_sha256'=>hash('sha256',$body),
    'tenant_id'=>'tenant-01','workspace_id'=>'workspace-01',
];
$empty=SourceOriginGroups::inspect($scope,[]);
Assert::true($empty->isOk(),'empty but valid known scope is representable');
Assert::same(0,$empty->value()['capture_count'],'no captures means no captures');
Assert::same(null,$empty->value()['independent_corroboration_count'],'zero captures is not certified corroboration');

$captures=[$cap('capture-01','source-1','Same release'),
    $cap('capture-02','source-2','Same release'),
    $cap('capture-03','source-2','Paraphrased release')];
$report=SourceOriginGroups::inspect($scope,$captures);
Assert::true($report->isOk(),'synthetic repeated captures can be grouped');
if (!$report->isOk()) return;
$r=$report->value();
Assert::same(3,$r['capture_count'],'all three captures kept');
Assert::same(2,$r['distinct_exact_payload_count'],'two byte payloads, NOT events');
Assert::same(1,$r['exact_byte_repeat_count'],'one exact-byte repeat');
Assert::same(2,$r['distinct_source_ref_count'],'two source refs, NOT independent origins');
Assert::same(null,$r['independent_corroboration_count'],'cross-domain byte copy is never independent support');
Assert::same(false,$r['independent_sources_certified'],'no independence minted by SHA');
Assert::same('UNKNOWN',$r['event_identity'],'two differently worded pages may still share one underlying event');
Assert::same('NOT_ASSESSED',$r['business_materiality'],'no signal materiality');
Assert::same(false,$r['release_authorized'],'no customer output');
Assert::same('EXACT_BYTES_ONLY_NOT_COMMON_ORIGIN',$r['exact_payload_groups'][0]['interpretation'],'identity groups only prove bytes');

$reordered=SourceOriginGroups::inspect($scope,[$captures[2],$captures[0],$captures[1]]);
Assert::true($reordered->isOk(),'reordered set accepted');
Assert::same($r['input_fingerprint'],$reordered->value()['input_fingerprint'],'group identity is ordering independent');
Assert::same($r['exact_payload_groups'],$reordered->value()['exact_payload_groups'],'group members are deterministically ordered');

$oneSource=SourceOriginGroups::inspect($scope,[$cap('1','one','A'),$cap('2','one','B')]);
Assert::same(2,$oneSource->value()['distinct_exact_payload_count'],'same domain with new bytes is two payloads only');
Assert::same(null,$oneSource->value()['independent_corroboration_count'],'a second capture is not an independent witness');

$duplicate=$captures; $duplicate[]=$captures[0];
Assert::same('LI04_ORIGIN_DUPLICATE_CAPTURE',SourceOriginGroups::inspect($scope,$duplicate)->reasonCode(),'reused capture ID denied');
$foreign=$captures; $foreign[0]['tenant_id']='tenant-other';
Assert::same('LI04_ORIGIN_SOURCE_SCOPE_OR_ID_INVALID',SourceOriginGroups::inspect($scope,$foreign)->reasonCode(),'foreign tenant rejected before grouping');
$unknown=$captures; $unknown[0]['event_id']='independent';
Assert::same('LI04_ORIGIN_SOURCE_SCOPE_OR_ID_INVALID',SourceOriginGroups::inspect($scope,$unknown)->reasonCode(),'unreviewed event independence assertions cannot be smuggled in');
$hash=$captures; $hash[0]['payload_sha256']='bad';
Assert::same('LI04_ORIGIN_DIGEST_INVALID',SourceOriginGroups::inspect($scope,$hash)->reasonCode(),'untyped capture digest denied');
Assert::same('LI04_ORIGIN_SET_UNBOUNDED',SourceOriginGroups::inspect($scope,array_fill(0,501,$captures[0]))->reasonCode(),'capture set bounded');
Assert::same('LI04_ORIGIN_SCOPE_INVALID',SourceOriginGroups::inspect(['tenant_id'=>'tenant-01','workspace_id'=>'workspace-01','global'=>true],[])->reasonCode(),'global/cross-tenant scope unsupported');
