<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/support/An03MockFinding.php';
require_once dirname(__DIR__).'/support/Aq02FixtureAttentionOwner.php';
require_once dirname(__DIR__).'/support/Aq02MockAttention.php';

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\{Database,Migrator};
use Allfather\Loopia\Product\P04\{
    InternalAttention,InternalDigest,InternalAttentionStore,
};
use Allfather\Loopia\Tests\{
    Assert,Harness,Seed,Aq02MockAttention,Aq02FixtureAttentionOwner,
};

$db=Harness::freshDb();
if($db===null){Assert::skip(Harness::unavailableReason());return;}
$root=dirname(__DIR__,2);
$sql=Migrator::splitStatements((string)file_get_contents(
    $root.'/docs/longitudinal/proposals/340_internal_attention_CANDIDATE.sql'));
Assert::same(12,count($sql),
    'four disposable family340 tables and eight append-only triggers');
$tables=[
    'history_internal_digest_members','history_internal_digest_candidates',
    'history_attention_suppression_events','history_internal_attention_candidates',
];
$installed=false;
try{
    foreach($sql as $statement)$db->pdo()->exec($statement);
    $installed=true;
    if(!defined('ALLFATHER_AQ02_DISPOSABLE_SCHEMA_LOADED')){
        define('ALLFATHER_AQ02_DISPOSABLE_SCHEMA_LOADED',true);
    }
    if(!defined('ALLFATHER_AQ02_SYNTHETIC_TEST')){
        define('ALLFATHER_AQ02_SYNTHETIC_TEST',true);
    }
    $clock=Harness::clock('2026-10-30T12:10:00.000Z');
    $owner=Seed::tenantWorkspace($db,$clock,'aq02-owner');
    $other=Seed::tenantWorkspace($db,$clock,'aq02-other');
    $competitor=Ids::new();
    $db->insert('competitors',[
        'competitor_id'=>$competitor,'schema_version'=>'1',
        'tenant_id'=>$owner['tenant_id'],'workspace_id'=>$owner['workspace_id'],
        'display_name'=>'AQ02 synthetic P04 scope',
        'competitor_class'=>'DIRECT','status'=>'ACTIVE','created_at'=>$clock->now(),
    ]);
    $scope=[
        'tenant_id'=>$owner['tenant_id'],'workspace_id'=>$owner['workspace_id'],
        'competitor_id'=>$competitor,'question_id'=>Ids::new(),
    ];
    $in=Aq02MockAttention::input($scope);
    Assert::true($in->isOk(),'fixed scoped AN03/AN04/P04 test metadata exists');
    if(!$in->isOk())return;
    $base=$in->value();
    $accepted=InternalAttention::composeFixture(
        $base,new Aq02FixtureAttentionOwner(),
    );
    Assert::true($accepted->isOk(),'synthetic owner can compose bounded internal attention');
    if(!$accepted->isOk())return;
    $duplicate=$base;
    $duplicate['at_utc']='2026-10-30T12:01:00Z';
    $duplicate['existing_dedup_keys']=[
        $accepted->value()['attention_dedup_key'],
    ];
    $suppressed=InternalAttention::composeFixture(
        $duplicate,new Aq02FixtureAttentionOwner(),
    );
    Assert::true($suppressed->isOk(),'duplicate candidate preserves suppression reason');
    if(!$suppressed->isOk())return;
    $rawDup=$base;$rawDup['at_utc']='2026-10-30T12:02:00Z';
    $undeduced=InternalAttention::composeFixture(
        $rawDup,new Aq02FixtureAttentionOwner(),
    );
    Assert::true($undeduced->isOk(),'uncorrected duplicate attempt can be detected by store');
    if(!$undeduced->isOk())return;

    $before=[];
    foreach([
        'decision_records','release_artifacts','role_distribution_briefings',
        'customer_output_projections','proposal_envelopes',
    ] as $table){
        $before[$table]=(int)$db->scalar('SELECT COUNT(*) FROM '.$table);
    }
    $store=InternalAttentionStore::forDisposableTest($db,$clock);
    Assert::true($store->isOk(),'family340 writer available only in disposable CLI');
    if(!$store->isOk())return;
    $store=$store->value();

    $first=$store->recordAttention($accepted->value());
    Assert::true($first->isOk(),'one bounded attention candidate appended');
    if(!$first->isOk())return;
    Assert::same('DISPOSABLE_ATTENTION_RECORDED',$first->value()['action'],
        'source-only candidate never releases P04 distribution');
    Assert::same(false,$first->value()['suppression_event_recorded'],
        'allowed candidate has no suppression event');
    Assert::same('EXACT_ATTENTION_REPLAY_NO_WRITE',
        $store->recordAttention($accepted->value())->value()['action'],
        'same event never repeatedly writes an interrupt');
    Assert::same('AQ02_REPEAT_ATTENTION_REQUIRES_SUPPRESSION',
        $store->recordAttention($undeduced->value())->reasonCode(),
        'later duplicate with same dedup key must be classified as suppressed');
    $second=$store->recordAttention($suppressed->value());
    Assert::true($second->isOk(),'explicitly suppressed duplicate recorded in ledger');
    if(!$second->isOk())return;
    Assert::same(true,$second->value()['suppression_event_recorded'],
        'suppression reason preserved in a separate append-only row');
    Assert::same(2,(int)$db->scalar(
        'SELECT COUNT(*) FROM history_internal_attention_candidates'),
        'one included and one suppressed attention event stored');
    Assert::same(1,(int)$db->scalar(
        'SELECT COUNT(*) FROM history_attention_suppression_events'),
        'one explicit no-interruption reason');

    $digest=InternalDigest::freeze([
        'policy'=>$base['policy'],
        'attention_candidates'=>[$accepted->value(),$suppressed->value()],
        'period_start_utc'=>'2026-10-30T00:00:00Z',
        'period_end_utc'=>'2026-10-31T00:00:00Z',
        'generated_at_utc'=>'2026-10-31T00:00:00Z',
    ]);
    Assert::true($digest->isOk(),'role digest keeps both admission and suppression');
    if(!$digest->isOk())return;
    $saved=$store->recordDigest(
        $base['policy'],[$accepted->value(),$suppressed->value()],
        $digest->value(),
    );
    Assert::true($saved->isOk(),'native disposable SQL contains full digest membership');
    if(!$saved->isOk())return;
    Assert::same(1,$saved->value()['members_saved'],'one metadata-only member stored');
    Assert::same(1,$saved->value()['suppressed_saved'],'suppressed duplicate has a reason row');
    Assert::same('EXACT_DIGEST_REPLAY_NO_WRITE',
        $store->recordDigest(
            $base['policy'],[$accepted->value(),$suppressed->value()],
            $digest->value(),
        )->value()['action'],'replayed digest does not write again');
    $readback=$store->replayDigest(
        $scope,$saved->value()['history_internal_digest_candidate_id'],
    );
    Assert::true($readback->isOk(),'scoped digest/membership readback is replayable');
    if($readback->isOk()){
        Assert::same('EXACT_INTERNAL_DIGEST_REPLAY_NO_WRITE',
            $readback->value()['status'],'readback remains metadata-only');
        Assert::same(false,$readback->value()['external_delivery_authorized'],
            'no external recipient output');
    }
    Assert::same(2,(int)$db->scalar('SELECT COUNT(*) FROM history_internal_digest_members'),
        'suppression is retained alongside included membership');

    $foreign=$scope;
    $foreign['tenant_id']=$other['tenant_id'];
    $foreign['workspace_id']=$other['workspace_id'];
    Assert::same('AQ02_DIGEST_NOT_IN_SCOPE',
        $store->replayDigest(
            $foreign,$saved->value()['history_internal_digest_candidate_id'],
        )->reasonCode(),'foreign workspace cannot discover the stored digest');

    foreach($before as $table=>$n){
        Assert::same($n,(int)$db->scalar('SELECT COUNT(*) FROM '.$table),
            'internal attention did not mutate native M10/P04/M16 owner: '.$table);
    }
    foreach($tables as $table){
        try{
            $db->run('DELETE FROM '.Database::identifier($table));
            Assert::fail('candidate family340 permitted destructive deletion');
        }catch(\PDOException){Assert::pass();}
    }
    Assert::same(0,(int)$db->scalar(
        "SELECT COUNT(*) FROM schema_migrations WHERE migration_id LIKE '340%'"),
        'candidate 340 remains uninstalled in native runtime migrations');
}finally{
    if($installed){
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach($tables as $table){
            $db->pdo()->exec('DROP TABLE IF EXISTS '.Database::identifier($table));
        }
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
