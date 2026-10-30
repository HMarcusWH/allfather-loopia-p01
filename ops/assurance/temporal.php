<?php

declare(strict_types=1);

/** VA-02 point-in-time source-only verifier. Never a host or M11 audit. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(2); }
require_once dirname(__DIR__,2).'/app/autoload.php';

use Allfather\Loopia\Product\P04\InternalAttention;
use Allfather\Loopia\Substrate\M17\Assurance;

$args=array_slice($argv,1);
if(count($args)!==1||!in_array($args[0],[
    '--help','--status','--synthetic-development',
],true)){
    fwrite(STDERR,"VA02_TEMPORAL_OWNER_NOT_ADOPTED\n");exit(3);
}
if($args[0]!=='--synthetic-development'){
    echo json_encode([
        'version'=>Assurance::VERSION,
        'status'=>'LOCAL_METADATA_ONLY_NOT_M11_OWNER_PROOF',
        'source_reuse_authorized'=>false,
        'host_provenance_verified'=>false,
        'customer_release_authorized'=>false,
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";exit(0);
}
if(getenv('ALLFATHER_VA02_SYNTHETIC_TEST')!=='1'){
    fwrite(STDERR,"VA02_EXPLICIT_SYNTHETIC_TEST_REQUIRED\n");exit(3);
}
try{
    require_once dirname(__DIR__,2).'/tests/support/An03MockFinding.php';
    require_once dirname(__DIR__,2).'/tests/support/Aq02FixtureAttentionOwner.php';
    require_once dirname(__DIR__,2).'/tests/support/Aq02MockAttention.php';
    if(!defined('ALLFATHER_AQ02_SYNTHETIC_TEST'))define('ALLFATHER_AQ02_SYNTHETIC_TEST',true);
    $f=\Allfather\Loopia\Tests\Aq02MockAttention::input();
    if(!$f->isOk())throw new \DomainException('FIXTURE');
    $attention=InternalAttention::composeFixture($f->value(),
        new \Allfather\Loopia\Tests\Aq02FixtureAttentionOwner());
    if(!$attention->isOk())throw new \DomainException('ATTENTION');
    $v=Assurance::inspect(
        $f->value()['native_release_proposal'],
        $f->value()['outcome_annotation'],
        $attention->value(),'2026-10-30T12:00:00Z',
    );
    if(!$v->isOk())throw new \DomainException('PROVENANCE');
    echo json_encode([
        'version'=>Assurance::VERSION,'status'=>$v->value()['status'],
        'observation_digest'=>$v->value()['observation_digest'],
        'selected_reference_count'=>$v->value()['selected_reference_count'],
        'contrary_reference_count'=>$v->value()['contrary_reference_count'],
        'rights_authenticated'=>false,'host_verified'=>false,
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
}catch(\Throwable){
    fwrite(STDERR,"VA02_SYNTHETIC_TEMPORAL_UNAVAILABLE\n");exit(4);
}
