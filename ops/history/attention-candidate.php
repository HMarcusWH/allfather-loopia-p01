<?php

declare(strict_types=1);

/**
 * AQ-02 source-only status/fixture preview. Never a browser, schedule, sender,
 * M10 recorder, database migrator or customer-facing delivery entrypoint.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(2);
}
require_once dirname(__DIR__,2).'/app/autoload.php';

use Allfather\Loopia\Product\P04\{
    AttentionPolicy,InternalAttention,InternalDigest,
};

$args=array_slice($argv,1);
if(count($args)!==1
    || !in_array($args[0],['--help','--status','--synthetic-development'],true)){
    fwrite(STDERR,"AQ02_NATIVE_ATTENTION_OWNER_NOT_ADOPTED\n");
    exit(3);
}
if(in_array($args[0],['--help','--status'],true)){
    echo json_encode([
        'version'=>InternalAttention::VERSION,
        'policy_version'=>AttentionPolicy::VERSION,
        'digest_version'=>InternalDigest::VERSION,
        'status'=>'CANDIDATE_U07_U09_NO_LIVE_RELEASE',
        'source_or_m10_rights_authenticated'=>false,
        'human_actor_or_role_authenticated'=>false,
        'scheduler_or_provider_calls'=>0,
        'p04_or_m16_release'=>false,
        'customer_visibility_authorized'=>false,
        'external_delivery_authorized'=>false,
        'synthetic_requires'=>'ALLFATHER_AQ02_SYNTHETIC_TEST=1',
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
}
if(getenv('ALLFATHER_AQ02_SYNTHETIC_TEST')!=='1'){
    fwrite(STDERR,"AQ02_FIXED_SYNTHETIC_TEST_MODE_REQUIRED\n");
    exit(3);
}
try{
    require_once dirname(__DIR__,2).'/tests/support/An03MockFinding.php';
    require_once dirname(__DIR__,2).'/tests/support/Aq02FixtureAttentionOwner.php';
    require_once dirname(__DIR__,2).'/tests/support/Aq02MockAttention.php';
    if(!defined('ALLFATHER_AQ02_SYNTHETIC_TEST')){
        define('ALLFATHER_AQ02_SYNTHETIC_TEST',true);
    }
    $input=\Allfather\Loopia\Tests\Aq02MockAttention::input();
    if(!$input->isOk())throw new \RuntimeException('INERT_INPUT_UNAVAILABLE');
    $attention=InternalAttention::composeFixture(
        $input->value(),
        new \Allfather\Loopia\Tests\Aq02FixtureAttentionOwner(),
    );
    if(!$attention->isOk())throw new \RuntimeException('FIXTURE_OWNER_HOLD');
    $digest=InternalDigest::freeze([
        'policy'=>$input->value()['policy'],
        'attention_candidates'=>[$attention->value()],
        'period_start_utc'=>'2026-10-30T00:00:00Z',
        'period_end_utc'=>'2026-10-31T00:00:00Z',
        'generated_at_utc'=>'2026-10-31T00:00:00Z',
    ]);
    if(!$digest->isOk())throw new \RuntimeException('DIGEST_INVALID');
    echo json_encode([
        'version'=>InternalAttention::VERSION,
        'source'=>'SYNTHETIC_FIXED_METADATA_ONLY',
        'attention_digest'=>$attention->value()['attention_digest'],
        'attention_status'=>$attention->value()['status'],
        'role'=>$attention->value()['target_org_role'],
        'digest'=>$digest->value()['digest'],
        'digest_members'=>$digest->value()['member_count'],
        'suppressed_entries'=>$digest->value()['suppressed_count'],
        'actual_m10_release'=>false,
        'raw_source_bytes_exported'=>false,
        'scheduler_or_provider_calls'=>0,
        'customer_visibility_authorized'=>false,
        'external_delivery_authorized'=>false,
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
}catch(\Throwable){
    fwrite(STDERR,"AQ02_SYNTHETIC_PREVIEW_UNAVAILABLE\n");
    exit(4);
}
