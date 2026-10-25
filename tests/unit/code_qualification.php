<?php

declare(strict_types=1);

use Allfather\Loopia\Ops\CodeQualification as CQ;
use Allfather\Loopia\Ops\ReleaseQualification as Q;
use Allfather\Loopia\Tests\Assert;

$sha=str_repeat('a',40);
$tree=str_repeat('b',40);
$hash=str_repeat('c',64);
$lane=static function(string $server):array {
    $layers=[];
    foreach (CQ::LAYERS as $name) $layers[$name]=[
        'pass'=>5,'fail'=>0,'skip'=>0,'files'=>1,'duration_ms'=>1,
    ];
    return [
        'schema_version'=>'1.0','projection_id'=>Q::PROJECTION_ID,
        'php_version'=>'8.2.34','database_server_version'=>$server,
        'complete'=>true,'strict'=>true,'layer_filter'=>null,'file_filter'=>null,
        'layers'=>$layers,'total'=>['pass'=>35,'fail'=>0,'skip'=>0,'duration_ms'=>7],
        'skips_are_failures'=>true,'failure_count'=>0,'skip_count'=>0,
    ];
};
$records=static function(string $execution):array {
    $output=[];
    foreach (CQ::jobs($execution) as $job) {
        $steps=$execution===Q::EXECUTION_LOCAL?CQ::steps($job):['*'];
        foreach ($steps as $step) $output[]=[
            'job'=>$job,'step'=>$step,'status'=>'PASS','exit'=>$execution===Q::EXECUTION_LOCAL?0:null,
        ];
    }
    return $output;
};
$input=static function(array $changes=[]) use($sha,$tree,$hash,$lane,$records):array {
    return array_replace([
        'execution'=>Q::EXECUTION_GITHUB_ACTIONS,'commit'=>$sha,'tree'=>$tree,
        'file_sha256'=>['workflow'=>$hash,'runner'=>$hash,'verifier'=>$hash,'code_contract'=>$hash],
        'steps'=>$records(Q::EXECUTION_GITHUB_ACTIONS),
        'lane_reports'=>[
            'a/qualification-10.6.json'=>['sha256'=>$hash,'report'=>$lane('10.6.28-MariaDB')],
            'b/qualification-10.11.json'=>['sha256'=>$hash,'report'=>$lane('10.11.19-MariaDB')],
        ],
        'package'=>['sha256'=>$hash,'report'=>[
            'status'=>Q::PREPARED_RELEASE_STATUS,'release_ref'=>$sha,'path'=>'/private/prepared-release',
        ]],
        'github'=>['run_id'=>'12345','run_attempt'=>'1','repository'=>'example/repo',
            'checkout_sha'=>$sha,'event_name'=>'pull_request'],
        'toolchain'=>[],
    ],$changes);
};
Assert::equalsList(
    ['foundation','mariadb','package-integrity','code-qualification'],
    CQ::jobs(Q::EXECUTION_GITHUB_ACTIONS),
    'hosted code job list is explicit and disjoint from legacy release jobs',
);
Assert::equalsList(
    ['foundation','mariadb-10.6','mariadb-10.11','package-integrity','code-qualification'],
    CQ::jobs(Q::EXECUTION_LOCAL),
    'local code qualification must run both DB versions',
);
Assert::same(['preflight','verify-preflight'],array_slice(Q::JOBS['release-preparation'],-2),
    'legacy v1 inert release receipt still requires both original preflight steps');
Assert::false(in_array('preflight',Q::CODE_JOBS['package-integrity'],true),
    'code-only source profile cannot imply release readiness');
Assert::same(Q::CODE_JOBS['mariadb'],CQ::steps('mariadb-10.11'),'expanded lane runs all old strict steps');
Assert::throws(static fn()=>CQ::steps('mariadb-10.5'),\InvalidArgumentException::class,'unsupported database line refused');
Assert::throws(static fn()=>CQ::steps('release-preparation'),\InvalidArgumentException::class,'v1 release job is not a code job');

$good=CQ::build($input());
Assert::same('PASS',$good['result'],'complete honest source code receipt passes');
Assert::equalsList([],CQ::verify($good),'self-consistent v2 source receipt');
Assert::same('NOT_ASSESSED',$good['deployment_readiness'],'code pass establishes no deployment status');
Assert::same(CQ::KIND,$good['kind'],'new code receipt has unique kind');
Assert::same(CQ::VERSION,$good['schema_version'],'new code receipt has different schema');
Assert::same(true,$good['execution']['hosted_ci'],'hosted result remains explicitly self-reported');

$local=CQ::build($input([
    'execution'=>Q::EXECUTION_LOCAL,'github'=>null,
    'steps'=>$records(Q::EXECUTION_LOCAL),
]));
Assert::same('PASS',$local['result'],'local exact-git-object procedure has same code profile');
Assert::same(false,$local['execution']['hosted_ci'],'local may never claim hosted CI');
Assert::equalsList([],CQ::verify($local),'local source receipt consistent');

$blocked=CQ::build($input(['steps'=>array_values(array_filter(
    $records(Q::EXECUTION_GITHUB_ACTIONS),static fn(array $s):bool=>$s['job']!=='package-integrity',
))]));
Assert::same('FAIL',$blocked['result'],'missing package-integrity job cannot create green code receipt');

$bad=$input();
$bad['steps'][0]['status']='NOT_RUN';
Assert::same('FAIL',CQ::build($bad)['result'],'missing/unexecuted code step refuses');

$bad=$input();
$bad['lane_reports']['a/qualification-10.6.json']['report']['total']['skip']=1;
Assert::same('FAIL',CQ::build($bad)['result'],'skip in MariaDB lane fails code receipt');

$bad=$input();
unset($bad['lane_reports']['a/qualification-10.6.json']);
Assert::same('FAIL',CQ::build($bad)['result'],'omitted 10.6 lane fails despite 10.11 success');

$bad=$input();
$bad['lane_reports']['a/qualification-10.6.json']['report']['database_server_version']='10.11.19-MariaDB';
Assert::same('FAIL',CQ::build($bad)['result'],'two 10.11 reports never substitute 10.6');

$bad=$input();
unset($bad['lane_reports']['a/qualification-10.6.json']['report']['layers']['security']);
Assert::same('FAIL',CQ::build($bad)['result'],'missing security layer fails strict code proof');

$bad=$input();
$bad['lane_reports']['a/qualification-10.6.json']['report']['total']['pass']=900;
Assert::same('FAIL',CQ::build($bad)['result'],'forged total inconsistent with seven layers');

$bad=$input();
$bad['package']['report']['release_ref']=str_repeat('e',40);
Assert::same('FAIL',CQ::build($bad)['result'],'package built from another source SHA refused');

$bad=$input();
$bad['package']['sha256']=null;
Assert::same('FAIL',CQ::build($bad)['result'],'unhashed package report refused');

$bad=$input();
$bad['github']['checkout_sha']=str_repeat('f',40);
Assert::same('FAIL',CQ::build($bad)['result'],'GitHub checkout commit must match source');

$bad=$input();
$bad['tree']='short';
Assert::same('FAIL',CQ::build($bad)['result'],'invalid tree object ID refused');

$bad=$input();
$bad['execution']='INVALID';
Assert::same('FAIL',CQ::build($bad)['result'],'unknown code execution refused');

$modified=$good;$modified['deployment_readiness']='READY_FOR_INERT_STAGING_COPY';
Assert::true(CQ::verify($modified)!==[],'code receipt tampered to claim inert readiness invalid');
$modified=$good;$modified['deployment_ready']=true;
Assert::true(CQ::verify($modified)!==[],'code receipt cannot smuggle deployment approval');
$modified=$good;$modified['result']='FAIL';
Assert::true(CQ::verify($modified)!==[],'edited success result fails receipt consistency');
$modified=$good;$modified['lanes'][0]['content']['total']['fail']=1;
Assert::true(CQ::verify($modified)!==[],'altered lane evidence invalidates source receipt');
$modified=$good;$modified['kind']=Q::RECEIPT_KIND;
Assert::true(CQ::verify($modified)!==[],'legacy and v2 kinds not interchangeable');
Assert::true(Q::receiptProblems($good)!==[],'legacy receipt verifier must NOT accept v2 kind');

// Even a perfectly passing source receipt cannot turn a failed inert preflight ready.
$inertBlocked=[
    'status'=>'NOT_READY','release_ref'=>$sha,
    'problems'=>['docs/decisions carries 4 owner decision(s)'],
    'live_read'=>false,'scheduler_handlers'=>false,
    'external_action'=>false,'customer_visible_output'=>false,
    'production_launch_authorized'=>false,'authorizes_deployment'=>false,
    'copies_anything'=>false,
];
Assert::true(Q::preflightProblems($inertBlocked,$sha)!==[],
    'legacy inert preflight still refuses when owner decisions are present');
