<?php
declare(strict_types=1);
namespace Allfather\Loopia\Ops;

/**
 * Separate source-code qualification. NEVER validates or grants deployment.
 * Original RELEASE_QUALIFICATION_RECEIPT v1 remains unchanged.
 */
final class CodeQualification
{
    public const VERSION='2.0';
    public const KIND='CODE_QUALIFICATION_RECEIPT';
    public const PROFILE='CODE_ONLY';
    public const LAYERS=['foundation','unit','integration','conformance','acceptance','security','rollback'];

    /** @return list<string> */
    public static function jobs(string $kind): array
    {
        if ($kind===ReleaseQualification::EXECUTION_GITHUB_ACTIONS) return array_keys(ReleaseQualification::CODE_JOBS);
        if ($kind!==ReleaseQualification::EXECUTION_LOCAL) throw new \InvalidArgumentException('unknown execution');
        $names=[];
        foreach (ReleaseQualification::CODE_JOBS as $job=>$_) {
            if ($job==='mariadb') foreach (ReleaseQualification::MARIADB_LANES as $lane) $names[]='mariadb-'.$lane;
            else $names[]=$job;
        }
        return $names;
    }

    /** @return list<string> */
    public static function steps(string $job): array
    {
        if (str_starts_with($job,'mariadb-')) {
            if (!in_array(substr($job,8),ReleaseQualification::MARIADB_LANES,true)) throw new \InvalidArgumentException('unknown lane');
            return ReleaseQualification::CODE_JOBS['mariadb'];
        }
        return ReleaseQualification::CODE_JOBS[$job] ?? throw new \InvalidArgumentException('unknown job');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function build(array $input): array
    {
        $kind=$input['execution']??null;
        $local=$kind===ReleaseQualification::EXECUTION_LOCAL;
        $jobs=[];
        foreach (($input['steps']??[]) as $s) {
            $name=(string)($s['job']??'');
            $jobs[$name]??=['job'=>$name,'steps'=>[]];
            $jobs[$name]['steps'][]=[
                'step'=>(string)($s['step']??''),
                'status'=>(string)($s['status']??''),
                'exit'=>is_int($s['exit']??null)?$s['exit']:null,
            ];
        }
        foreach ($jobs as $name=>$job) {
            $pass=$job['steps']!==[];
            foreach ($job['steps'] as $s) if ($s['status']!=='PASS'||($local&&$s['exit']!==0)) $pass=false;
            $jobs[$name]['result']=$pass?'PASS':'FAIL';
        }
        $lanes=[];
        foreach (($input['lane_reports']??[]) as $path=>$v) $lanes[]=[
            'report'=>(string)$path,'sha256'=>$v['sha256']??null,'content'=>$v['report']??null,
        ];
        $p=$input['package']??null;
        $r=[
            'schema_version'=>self::VERSION, 'kind'=>self::KIND, 'profile'=>self::PROFILE,
            'projection_id'=>ReleaseQualification::PROJECTION_ID,
            'result'=>null, 'problems'=>[],
            'source'=>[
                'commit'=>$input['commit']??null,'tree'=>$input['tree']??null,
                'requested_ref'=>$input['requested_ref']??null,
                'materialized_from'=>$local?'GIT_OBJECT_EXPORT':'CI_CHECKOUT',
                'file_sha256'=>$input['file_sha256']??[],
            ],
            'execution'=>[
                'kind'=>$kind,'granularity'=>$local?'STEP':'JOB',
                'self_reported'=>true,'hosted_ci'=>!$local,
                'github_run'=>$local?null:($input['github']??null),
                'toolchain'=>$input['toolchain']??null,
            ],
            'required'=>[
                'php_line'=>ReleaseQualification::PHP_LINE,
                'mariadb_lanes'=>ReleaseQualification::MARIADB_LANES,
                'jobs'=>ReleaseQualification::CODE_JOBS,
            ],
            'jobs'=>array_values($jobs),'lanes'=>$lanes,
            'package'=>is_array($p)?[
                'sha256'=>$p['sha256']??null,'content'=>$p['report']??null,
                'verified_by'=>ReleaseQualification::CODE_JOBS['package-integrity'],
            ]:null,
            'deployment_readiness'=>'NOT_ASSESSED',
            'establishes'=>'Strict source tests and byte-verified prepared package only',
            'does_not_establish'=>[
                'inert staging readiness or deployment authorization',
                'manual pilot, model or source-capture grants',
                'customer output, external actions or production GO',
                'host qualification; receipt is self-reported',
            ],
        ];
        $p=self::evaluate($r);$r['result']=$p===[]?'PASS':'FAIL';$r['problems']=$p;
        return $r;
    }

    /** @param mixed $receipt @return list<string> */
    public static function verify(mixed $receipt): array
    {
        if (!is_array($receipt)) return ['not a code receipt'];
        $problems=self::evaluate($receipt);
        if (($receipt['result']??null)!==($problems===[]?'PASS':'FAIL')) $problems[]='incorrect receipt result';
        if (($receipt['problems']??null)!==self::evaluate($receipt)) $problems[]='receipt problems do not match evidence';
        return $problems;
    }

    /** @param array<string,mixed> $r @return list<string> */
    private static function evaluate(array $r): array
    {
        $bad=[];
        if (($r['schema_version']??null)!==self::VERSION||($r['kind']??null)!==self::KIND
            ||($r['profile']??null)!==self::PROFILE
            ||($r['projection_id']??null)!==ReleaseQualification::PROJECTION_ID)
            $bad[]='unsupported code qualification profile';
        $source=is_array($r['source']??null)?$r['source']:[];
        foreach (['commit','tree'] as $key)
            if (!is_string($source[$key]??null)||preg_match('/^[a-f0-9]{40}$/D',$source[$key])!==1) $bad[]="invalid source {$key}";
        foreach (['workflow','runner','verifier','code_contract'] as $key)
            if (!is_string($source['file_sha256'][$key]??null)
                ||preg_match('/^[a-f0-9]{64}$/D',$source['file_sha256'][$key])!==1) $bad[]="missing source SHA-256 {$key}";
        $x=is_array($r['execution']??null)?$r['execution']:[];
        $kind=$x['kind']??null;
        if (!in_array($kind,[ReleaseQualification::EXECUTION_LOCAL,ReleaseQualification::EXECUTION_GITHUB_ACTIONS],true)) {
            $bad[]='unknown code execution'; return $bad;
        }
        $local=$kind===ReleaseQualification::EXECUTION_LOCAL;
        if (($x['granularity']??null)!==($local?'STEP':'JOB')
            ||($x['self_reported']??null)!==true||($x['hosted_ci']??null)!==!$local
            ||($source['materialized_from']??null)!==($local?'GIT_OBJECT_EXPORT':'CI_CHECKOUT'))
            $bad[]='execution provenance mismatch';
        if ($local&&($x['github_run']??null)!==null) $bad[]='local receipt claims hosted run';
        if (!$local) {
            $g=is_array($x['github_run']??null)?$x['github_run']:[];
            if (preg_match('/^\d+$/D',(string)($g['run_id']??''))!==1
                ||preg_match('/^\d+$/D',(string)($g['run_attempt']??''))!==1
                ||!is_string($g['repository']??null)||$g['repository']===''
                ||($g['checkout_sha']??null)!==($source['commit']??null))
                $bad[]='hosted run ID/attempt/checkout SHA mismatch';
        }
        if (($r['deployment_readiness']??null)!=='NOT_ASSESSED'
            ||array_key_exists('deployment_ready',$r)||array_key_exists('deployment_authorized',$r))
            $bad[]='code qualification cannot grant deployment';
        if (($r['required']['jobs']??null)!==ReleaseQualification::CODE_JOBS
            ||($r['required']['php_line']??null)!==ReleaseQualification::PHP_LINE
            ||($r['required']['mariadb_lanes']??null)!==ReleaseQualification::MARIADB_LANES)
            $bad[]='required code profile drift';
        $required=self::jobs($kind);$jobs=[];
        foreach (is_array($r['jobs']??null)?$r['jobs']:[] as $job) {
            $name=is_array($job)?($job['job']??null):null;
            if (!is_string($name)||!in_array($name,$required,true)||isset($jobs[$name])) {
                $bad[]='unexpected or duplicate code job';continue;
            }
            $jobs[$name]=$job;
        }
        foreach ($required as $name) {
            if (!isset($jobs[$name])) {$bad[]="missing code job {$name}";continue;}
            $steps=is_array($jobs[$name]['steps']??null)?$jobs[$name]['steps']:[];
            $names=array_map(static fn($s)=>is_array($s)?($s['step']??null):null,$steps);
            if ($names!==($local?self::steps($name):['*'])) $bad[]="wrong job step inventory {$name}";
            $passed=$steps!==[];
            foreach ($steps as $s) if (!is_array($s)||($s['status']??null)!=='PASS'
                ||($local&&($s['exit']??null)!==0)) $passed=false;
            if (!$passed||($jobs[$name]['result']??null)!==($passed?'PASS':'FAIL')) $bad[]="code job {$name} failed/skipped";
        }
        $lanes=[];
        foreach (is_array($r['lanes']??null)?$r['lanes']:[] as $l) {
            if (!is_array($l)||!is_string($l['report']??null)||isset($lanes[$l['report']])) {
                $bad[]='duplicate or malformed lane artifact';continue;
            }
            $label=$l['report'];$v=$l['content']??null;$lanes[$label]=$v;
            if (!is_string($l['sha256']??null)||preg_match('/^[a-f0-9]{64}$/D',$l['sha256'])!==1)
                $bad[]="lane {$label} digest missing";
            if (!is_array($v)) continue;
            $layers=$v['layers']??null;
            if (!is_array($layers)||array_keys($layers)!==self::LAYERS) {
                $bad[]="lane {$label} is not seven full ordered layers";continue;
            }
            $totals=['pass'=>0,'fail'=>0,'skip'=>0];
            foreach ($layers as $layer=>$metrics) {
                if (!is_array($metrics)||!is_int($metrics['files']??null)||$metrics['files']<1)
                    $bad[]="lane {$label}/{$layer} no test files";
                foreach ($totals as $key=>$_) {
                    if (!is_int($metrics[$key]??null)||$metrics[$key]<0)
                        $bad[]="lane {$label}/{$layer} invalid {$key}";
                    else $totals[$key]+=$metrics[$key];
                }
            }
            foreach ($totals as $key=>$value)
                if (($v['total'][$key]??null)!==$value) $bad[]="lane {$label} inconsistent {$key} total";
        }
        foreach (ReleaseQualification::laneSetProblems($lanes) as $error) $bad[]="lane: {$error}";
        $p=is_array($r['package']??null)?$r['package']:[];
        if (!is_string($p['sha256']??null)||preg_match('/^[a-f0-9]{64}$/D',$p['sha256'])!==1)
            $bad[]='package digest missing';
        $verdict=$p['content']??null;
        if (!is_array($verdict)||($verdict['status']??null)!==ReleaseQualification::PREPARED_RELEASE_STATUS
            ||($verdict['release_ref']??null)!==($source['commit']??null)
            ||!is_string($verdict['path']??null)||$verdict['path']==='')
            $bad[]='package report not for this exact source';
        if (($p['verified_by']??null)!==ReleaseQualification::CODE_JOBS['package-integrity'])
            $bad[]='package verification steps missing';
        return $bad;
    }
}
