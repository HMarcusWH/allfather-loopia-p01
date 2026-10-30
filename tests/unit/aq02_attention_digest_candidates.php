<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/support/An03MockFinding.php';
require_once dirname(__DIR__).'/support/Aq02FixtureAttentionOwner.php';
require_once dirname(__DIR__).'/support/Aq02MockAttention.php';

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Product\P04\{AttentionPolicy,InternalAttention,InternalDigest};
use Allfather\Loopia\Tests\{Assert,Aq02MockAttention,Aq02FixtureAttentionOwner};

if(!defined('ALLFATHER_AQ02_SYNTHETIC_TEST')){
    define('ALLFATHER_AQ02_SYNTHETIC_TEST',true);
}
$input=Aq02MockAttention::input();
Assert::true($input->isOk(),'AN03 finding + AN04 outcome compose typed fixture attention context');
if(!$input->isOk())return;
$p=$input->value();
Assert::true(AttentionPolicy::verify($p['policy'])->isOk(),
    'P04 internal policy candidate verifies as non-authoritative');
Assert::same(false,$p['policy']['policy_adopted_by_owner'],
    'configuration is not U09 owner adoption');
$owner=new Aq02FixtureAttentionOwner();
$r=InternalAttention::composeFixture($p,$owner);
Assert::true($r->isOk(),'synthetic P01 M10 read port runs positive composition');
if(!$r->isOk())return;
$a=$r->value();
Assert::true(InternalAttention::verify($a)->isOk(),
    'attention metadata digest is valid');
Assert::same(['PREPARE'],$owner->stages,
    'initial attention checks owner read once');
Assert::same('ATTENTION_CANDIDATE_INTERNAL_ONLY',$a['status'],
    'no action permitted even when fixture's M10 state is CURRENT');
Assert::same('SALES',$a['target_org_role'],'explicit P04 role preserved');
Assert::same(false,$a['customer_visibility_authorized'],
    'internal attention candidate cannot publish to a customer');
Assert::same(false,$a['external_delivery_authorized'],
    'no SMS/email/CRM/webhook from attention');
Assert::same(null,$a['suppression_reason'],
    'positive candidate does not fabricate a suppression reason');

foreach([
    'STALE'=>'SUPPRESSED_STALE_M10_RELIANCE',
    'REVOKED'=>'SUPPRESSED_SOURCE_RIGHTS_HOLD',
    'UNKNOWN_RIGHTS'=>'SUPPRESSED_SOURCE_RIGHTS_HOLD',
    'ROLE_DENIED'=>'SUPPRESSED_ROLE_OR_REDACTION',
    'REDACTION_INVALID'=>'SUPPRESSED_ROLE_OR_REDACTION',
] as $case=>$status){
    $test=InternalAttention::composeFixture($p,new Aq02FixtureAttentionOwner($case));
    Assert::true($test->isOk(),'fixture owner returns bounded current-state answer: '.$case);
    if($test->isOk()){
        Assert::same($status,$test->value()['status'],
            'bad current rights/reliance/role fail closed');
        Assert::same($status,$test->value()['suppression_reason'],
            'every suppressed event retains its reason');
    }
}

$duplicate=$p;
$duplicate['at_utc']='2026-10-30T12:01:00Z';
$duplicate['existing_dedup_keys']=[$a['attention_dedup_key']];
$d=InternalAttention::composeFixture($duplicate,new Aq02FixtureAttentionOwner());
Assert::true($d->isOk(),'later identical event gets an explicit suppression');
if(!$d->isOk())return;
Assert::same('SUPPRESSED_DUPLICATE_COOLDOWN',$d->value()['status'],
    'same event may not repeatedly interrupt the reviewer');
$human=$p;
$human['at_utc']='2026-10-30T12:02:00Z';
$human['operator_choice']='SUPPRESS';
$h=InternalAttention::composeFixture($human,new Aq02FixtureAttentionOwner());
Assert::true($h->isOk(),'operator suppression retained as an event');
if(!$h->isOk())return;
Assert::same('SUPPRESSED_BY_HUMAN_CANDIDATE',$h->value()['status'],
    'suppressed human choice remains candidate, not an acknowledged real human act');
$defer=$p;
$defer['at_utc']='2026-10-30T12:03:00Z';
$defer['operator_choice']='DEFER';
$defer['defer_until_utc']='2026-10-31T00:00:00Z';
$def=InternalAttention::composeFixture($defer,new Aq02FixtureAttentionOwner());
Assert::true($def->isOk(),'bounded human-defer candidate can be recorded');
if($def->isOk()){
    Assert::same('DEFERRED_BY_HUMAN_CANDIDATE',$def->value()['status'],
        'human defer is not swallowed or silently converted to release');
}
$bad=$defer;$bad['defer_until_utc']='2026-10-29T00:00:00Z';
Assert::same('AQ02_HUMAN_DEFER_WINDOW_INVALID',
    InternalAttention::composeFixture($bad,new Aq02FixtureAttentionOwner())->reasonCode(),
    'retroactive defer refuses');

$bad=$p;$bad['released_output']['scope']['workspace_id']='other_workspace';
Assert::same('AQ02_ATTENTION_SCOPE_POLICY_OR_CLOCK_HOLD',
    InternalAttention::composeFixture($bad,new Aq02FixtureAttentionOwner())->reasonCode(),
    'foreign workspace never passes to owner adapter');
$bad=$p;$bad['released_output']=$p['native_release_proposal'];
Assert::same('AQ02_ATTENTION_INPUT_OR_SOURCE_INVALID',
    InternalAttention::composeFixture($bad,new Aq02FixtureAttentionOwner())->reasonCode(),
    'AN03 candidate must not masquerade as an M10-released P01 briefing');

$bad=$a;$bad['sent']=true;
$unsigned=$bad;unset($unsigned['attention_digest']);
$bad['attention_digest']=Digest::canonicalDigest($unsigned);
Assert::same('AQ02_ATTENTION_FORGED_AUTHORITY',
    InternalAttention::verify($bad)->reasonCode(),
    'rehashing sent=true cannot grant outbound delivery');
$bad=$p['policy'];$bad['policy_adopted_by_owner']=true;
$unsigned=$bad;unset($unsigned['policy_digest']);
$bad['policy_digest']=Digest::canonicalDigest($unsigned);
Assert::same('AQ02_POLICY_REHASH_OR_GRANT_FORGED',
    AttentionPolicy::verify($bad)->reasonCode(),
    'caller cannot mint U09 by rehashing a policy');

$bundle=InternalDigest::freeze([
    'policy'=>$p['policy'],
    'attention_candidates'=>[$a,$d->value(),$h->value()],
    'period_start_utc'=>'2026-10-30T00:00:00Z',
    'period_end_utc'=>'2026-10-31T00:00:00Z',
    'generated_at_utc'=>'2026-10-31T00:00:00Z',
]);
Assert::true($bundle->isOk(),'role-scoped digest retains member plus two suppressed records');
if(!$bundle->isOk())return;
$b=$bundle->value();
Assert::true(InternalDigest::verify($b)->isOk(),'digest can be replay-verified without owner grants');
Assert::same(1,$b['member_count'],'single eligible candidate member');
Assert::same(2,$b['suppressed_count'],'all suppressed events remain in ledger');
Assert::same(3,$b['all_input_count'],'no input dropped');
Assert::same(false,$b['sent'],'digest is not an external message');
$viewOwner=new Aq02FixtureAttentionOwner();
$render=InternalDigest::renderFixture($b,[$a,$d->value(),$h->value()],$viewOwner);
Assert::true($render->isOk(),'render rechecks source, M10 current reliance and role');
if($render->isOk()){
    Assert::same('SYNTHETIC_PRIVATE_DIGEST_PREVIEW_ONLY',$render->value()['status'],
        'rendering cannot auto-publish a P04 role briefing');
}
Assert::same(['RENDER'],$viewOwner->stages,
    'at-render reliance is a separate source-owner read');
Assert::same('AQ02_RENDER_CURRENT_OWNER_HOLD',
    InternalDigest::renderFixture(
        $b,[$a,$d->value(),$h->value()],
        new Aq02FixtureAttentionOwner('STALE'),
    )->reasonCode(),
    'later M10 supersession invalidates stored preview eligibility');
$empty=InternalDigest::freeze([
    'policy'=>$p['policy'],'attention_candidates'=>[$d->value(),$h->value()],
    'period_start_utc'=>'2026-10-30T00:00:00Z',
    'period_end_utc'=>'2026-10-31T00:00:00Z',
    'generated_at_utc'=>'2026-10-31T00:00:00Z',
]);
Assert::true($empty->isOk(),'all-suppressed digest remains inspectable');
if($empty->isOk()){
    Assert::same('EMPTY_SUPPRESSED_DIGEST_HOLD',$empty->value()['status'],
        'zero permissible members is not an implicit notification');
}
