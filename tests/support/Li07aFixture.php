<?php

declare(strict_types=1);

namespace Allfather\Loopia\Tests;

use Allfather\Loopia\Substrate\M17\InputSet;
use Allfather\Loopia\Substrate\M17\ProfileResolver;
use Allfather\Loopia\Substrate\M17\WindowEngine;

/** Synthetic fixture composition; never used by application services. */
final class Li07aFixture
{
    /** @return array<string,mixed> */
    public static function data(): array
    {
        return json_decode((string)file_get_contents(dirname(__DIR__) . '/fixtures/li07a_rollup_cases.json'), true, 48, JSON_THROW_ON_ERROR);
    }

    /** @param list<array<string,mixed>>|null $refs @param array<string,string>|null $scope */
    public static function inputs(?array $refs = null, ?array $scope = null, string $kind = 'DAY',
        ?string $anchor = null, ?string $dataClass = null): array
    {
        $v = self::data();
        $scope ??= $v['scope'];
        $dataClass ??= $v['class'];
        $rows = $refs ?? $v['refs'];
        $set = InputSet::freeze(['scope'=>$scope, 'data_class'=>$dataClass, 'refs'=>$rows]);
        $profile = ProfileResolver::resolve([
            'scope'=>$scope, 'data_class'=>$dataClass,
            'evaluation_time_utc'=>$v['evaluation_time_utc'],
            'candidates'=>[], 'floors'=>[],
        ]);
        $window = WindowEngine::assign([
            'kind'=>$kind,
            'observation_at_utc'=>$anchor ?? $v['observation_at_utc'],
            'calendar_config'=>['timezone'=>$v['calendar'],'tzdb_version'=>timezone_version_get()],
        ]);
        foreach (['set'=>$set, 'profile'=>$profile, 'window'=>$window] as $name=>$out) {
            if (!$out->isOk()) {
                throw new \RuntimeException('LI07A fixture component refused: ' . $name . ' => ' . $out->reasonCode());
            }
        }
        return [
            'input_set'=>$set->value(), 'window'=>$window->value(),
            'profile_candidate'=>$profile->value(), 'importance_weights'=>null,
        ];
    }
}
