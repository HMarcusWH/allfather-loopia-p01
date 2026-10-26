<?php

declare(strict_types=1);

use Allfather\Loopia\Substrate\M17\WindowEngine;
use Allfather\Loopia\Substrate\M17\ProfileResolver;
use Allfather\Loopia\Substrate\M17\Vocabulary;
use Allfather\Loopia\Tests\Assert;

$v = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/temporal_contract_vectors.json'), true, 64, JSON_THROW_ON_ERROR);
$calendar = ['timezone'=>'Europe/Stockholm','tzdb_version'=>timezone_version_get()];
foreach ($v['windows'] as $fixture) {
    $input = ['kind'=>$fixture['kind'], 'observation_at_utc'=>$fixture['observation_at_utc'],
        'calendar_config'=>$fixture['timezone'] === null ? null : [
            'timezone'=>$fixture['timezone'], 'tzdb_version'=>timezone_version_get(),
        ]];
    $r = WindowEngine::assign($input);
    Assert::true($r->isOk(), 'window can be assigned: ' . $fixture['case']);
    if (!$r->isOk()) continue;
    $x=$r->value();
    foreach (['start_inclusive_utc','end_exclusive_utc','duration_seconds'] as $field) {
        Assert::same($fixture[$field],$x[$field],$fixture['case'].' '.$field);
    }
    Assert::true(WindowEngine::verifyWindow($x)->isOk(), 'replay exact window: '.$fixture['case']);
    Assert::same($x['window_digest'],WindowEngine::assign($input)->value()['window_digest'],
        'deterministic window digest: '.$fixture['case']);
    Assert::same(false,$x['historical_reuse_admitted'],'window cannot authorize reuse');
}
$spring=WindowEngine::assign(['kind'=>'DAY','observation_at_utc'=>'2026-03-29T10:00:00Z',
    'calendar_config'=>$calendar])->value();
Assert::same(3600,$spring['start_offset_seconds'],'spring day begins CET');
Assert::same(7200,$spring['end_offset_seconds'],'spring day ends CEST');
$autumn=WindowEngine::assign(['kind'=>'DAY','observation_at_utc'=>'2026-10-25T10:00:00Z',
    'calendar_config'=>$calendar])->value();
Assert::same(7200,$autumn['start_offset_seconds'],'fall day begins CEST');
Assert::same(3600,$autumn['end_offset_seconds'],'fall day ends CET');
foreach (['2026-03-29T10:00:00+02:00', '2026-02-29T10:00:00Z',
    '2026-03-29T10:00:60Z','2026-03-29T25:00:00Z'] as $invalid) {
    Assert::false(Vocabulary::utc($invalid),'invalid/ambiguous timestamp explicitly refused');
}
foreach (['HOUR','QUARTER','YEAR','ROLLING','CALENDAR','EVENT_BOUNDED'] as $unknownKind) {
    $r=WindowEngine::assign(['kind'=>$unknownKind,
        'observation_at_utc'=>'2026-03-29T10:00:00Z','calendar_config'=>null]);
    Assert::same('M17_WINDOW_CONTRACT_INVALID',$r->reasonCode(),
        'unsupported window remains inert, rather than guessed: '.$unknownKind);
}
Assert::same('M17_CALENDAR_NOT_VERIFIED_OR_SUPPORTED',WindowEngine::assign([
    'kind'=>'DAY','observation_at_utc'=>'2026-03-29T10:00:00Z',
    'calendar_config'=>['timezone'=>'Europe/Stockholm','tzdb_version'=>'wrong'],
])->reasonCode(),'tzdb expectation mismatch refuses replay');
Assert::same('M17_CALENDAR_NOT_VERIFIED_OR_SUPPORTED',WindowEngine::assign([
    'kind'=>'DAY','observation_at_utc'=>'2026-03-29T10:00:00Z',
    'calendar_config'=>['timezone'=>'Europe/Berlin','tzdb_version'=>timezone_version_get()],
])->reasonCode(),'undeclared calendar zone never silently substituted');
$dirty=$spring;$dirty['end_exclusive_utc']='2026-03-29T23:00:00Z';
Assert::same('M17_WINDOW_REPLAY_MISMATCH',WindowEngine::verifyWindow($dirty)->reasonCode(),
    'mutated day end with stale digest refused');
$dirty=$spring;$dirty['historical_reuse_admitted']=true;
Assert::same('M17_WINDOW_SHAPE_INVALID',WindowEngine::verifyWindow($dirty)->reasonCode(),
    'window cannot be promoted into permission via field edit');

foreach (['SOURCE_DIFF','COMPANY_SIGNAL'] as $class) {
    $r=ProfileResolver::resolve(['scope'=>$v['scope'],'data_class'=>$class,
        'evaluation_time_utc'=>'2026-06-01T00:00:00Z','candidates'=>[],'floors'=>[]]);
    Assert::true($r->isOk(), 'supported class builds an offline profile '.$class);
    if (!$r->isOk()) continue;
    $profile=$r->value();
    foreach ($v['age_edges'] as $edge) {
        if ($edge['data_class']!==$class) continue;
        $observed=(new \DateTimeImmutable('2026-06-01T00:00:00Z'))
            ->modify('-'.$edge['days'].' days')->format('Y-m-d\TH:i:s\Z');
        $selected=WindowEngine::choose(['data_class'=>$class,'observation_at_utc'=>$observed,
            'evaluation_time_utc'=>'2026-06-01T00:00:00Z',
            'profile_candidate'=>$profile,'volatility_level'=>'LOW']);
        Assert::true($selected->isOk(), 'age-boundary selection is well formed');
        if ($selected->isOk()) {
            Assert::same($edge['kind'],$selected->value()['kind'],
                $class.' age '.$edge['days'].'d resolves exact proposed ladder threshold');
            Assert::same(false,$selected->value()['resolution_is_schedule'],
                'temporal resolution never schedules observation');
        }
    }
    $observed='2026-01-01T00:00:00Z';
    foreach (['HIGH','UNKNOWN'] as $signal) {
        $selected=WindowEngine::choose(['data_class'=>$class,'observation_at_utc'=>$observed,
            'evaluation_time_utc'=>'2026-06-01T00:00:00Z',
            'profile_candidate'=>$profile,'volatility_level'=>$signal]);
        Assert::same('DAY',$selected->value()['kind'],'high/unknown volatility conservatively holds fine day');
    }
    $bad=WindowEngine::choose(['data_class'=>$class,'observation_at_utc'=>'2026-06-02T00:00:00Z',
        'evaluation_time_utc'=>'2026-06-01T00:00:00Z',
        'profile_candidate'=>$profile,'volatility_level'=>'LOW']);
    Assert::same('M17_RESOLUTION_CLOCK_INVALID',$bad->reasonCode(),
        'future source observation cannot be silently clamped into now');
}
