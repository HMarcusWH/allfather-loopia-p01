<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Ops\{
    BuildFreezeInspector,BuildFirstRegistry,HistorySchemaPlan,
};
use Allfather\Loopia\Tests\Assert;

$root=dirname(__DIR__,2);
$r=BuildFreezeInspector::inspect($root);
Assert::true($r->isOk(),'RT02 all required source/owner/schema files and 29 packages present: '.
    ($r->isOk()?'OK':$r->reasonCode()));
if(!$r->isOk())return;
$v=$r->value();
Assert::true(BuildFreezeInspector::verify($root,$v)->isOk(),
    'independent recomposition of current source freeze inventory');
Assert::same(29,$v['package_count'],'all planned 29 work packages built inert');
Assert::same(64,$v['contract_carriers'],'64 generic envelope tests are preserved');
Assert::same(14,$v['primary_typed_carriers'],'14 distinct primary candidate schemas');
Assert::same('NOT_A_LIVE_PRODUCER_CONSUMER_ATTESTATION',
    $v['generic_carrier_producer_wiring'],
    'generic fixtures do not impersonate native owner integrations');
Assert::same(12,$v['schema_families'],'all 260–350 families bound');
Assert::same(38,$v['candidate_tables'],'physical table count is source-reconciled');
Assert::same(76,$v['candidate_append_only_triggers'],
    'all candidate append-only rules included');
Assert::same(BuildFirstRegistry::GATES,$v['unadopted_gates'],
    'U01–U10 never auto-adopt on source merge');
Assert::same(false,$v['host_migrations_or_recovery_qualified'],
    'source archive cannot claim independent restore');
Assert::same(false,$v['provider_source_or_paid_model_authorized'],
    'no default source/model permission');
Assert::same(false,$v['real_human_release_approved'],
    'no fake human approval');
Assert::same(false,$v['customer_visibility_authorized'],
    'source freeze has no customer-facing release');
Assert::same('V01_V11_NOT_RUN',$v['verification_campaign'],
    'deferred V01–V11 campaign not inferred from GitHub CI');
Assert::same('NOT_INDEPENDENTLY_ATTESTED',$v['third_party_license_audit'],
    'license and dependency lockfiles are not legal certification');
Assert::same('proprietary',$v['composer_license_declared'],
    'PHP source licensing declaration preserved');
Assert::true(count($v['checked_source_hashes'])>=40,
    'critical PHP, frontend, package, runbook, schema and fixture paths pinned');
Assert::true(preg_match('/^[a-f0-9]{64}$/D',$v['inventory_digest'])===1,
    'source-only audit receipt content is hashed');
$identical=BuildFreezeInspector::inspect($root);
Assert::same($v['inventory_digest'],$identical->value()['inventory_digest'],
    'identical source produces the same inventory digest');
$forged=$v;
$forged['customer_visibility_authorized']=true;
$copy=$forged;unset($copy['inventory_digest']);
$forged['inventory_digest']=Digest::canonicalDigest($copy);
Assert::same('RT02_FREEZE_SOURCE_RECEIPT_FORGED_OR_STALE',
    BuildFreezeInspector::verify($root,$forged)->reasonCode(),
    'self-rehashing authorization cannot replace live owner grants');
$forged=$v;
$forged['package_count']=28;
Assert::same('RT02_FREEZE_SOURCE_RECEIPT_FORGED_OR_STALE',
    BuildFreezeInspector::verify($root,$forged)->reasonCode(),
    'dropping a required work package refuses');
$schema=HistorySchemaPlan::inspect($root);
Assert::same($schema->value()['plan_digest'],$v['schema_plan_digest'],
    'full source freeze preserves RT01 physical SQL identity');
