<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;

$development = json_decode(
    file_get_contents(__DIR__ . '/../fixtures/historical_review_development_cases.json'),
    true, 512, JSON_THROW_ON_ERROR
);
$heldout = json_decode(
    file_get_contents(__DIR__ . '/../fixtures/historical_review_heldout_manifest.json'),
    true, 512, JSON_THROW_ON_ERROR
);

Assert::same('OFFLINE_FIXTURE_ONLY_NOT_ADMITTED', $development['status'], 'LI18 dev cases cannot authorize execution');
Assert::same(0, $development['authority_boundary']['service_model_attempts'], 'LI18 fixture has no model budget');
Assert::same(true, $development['authority_boundary']['no_provider_calls'], 'LI18 forbids provider calls');
Assert::same(true, $development['authority_boundary']['no_release'], 'LI18 fixture cannot release');
Assert::same(15, count($development['cases']), 'LI18 development case inventory frozen at 15');
Assert::same('RESERVED_NOT_FROZEN_NOT_RUN', $heldout['status'], 'LI18 heldout remains NOT FROZEN, not invented pass');
Assert::same(6, count($heldout['slots']), 'LI18 heldout reserve count');

$caseIds = [];
foreach ($development['cases'] as $case) {
    $id = $case['case_id'];
    Assert::false(isset($caseIds[$id]), "unique sequence ID {$id}");
    $caseIds[$id] = true;
    Assert::same('DEVELOPMENT', $case['split'], "{$id}: no heldout leakage into training suite");
    Assert::same(0, $case['expected']['model_calls_permitted'], "{$id}: due cannot spend a model call");
    Assert::same('NONE', $case['expected']['attention_authority'], "{$id}: observation is not notification permission");
    Assert::same('NOT_ESTABLISHED_BY_FIXTURE', $case['expected']['materiality_assessment'], "{$id}: structural data not business materiality");
    Assert::true(
        preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $case['knowledge_cut']) === 1,
        "{$id}: exact UTC knowledge cut"
    );

    $observed = [];
    $eligible = [];
    $events = [];
    $origins = [];
    foreach ($case['observations'] as $o) {
        Assert::false(isset($observed[$o['observation_id']]), "{$id}: unique observation ref");
        $observed[$o['observation_id']] = true;
        Assert::true(
            preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $o['known_at']) === 1,
            "{$id}: known-at clock canonical"
        );
        Assert::true($o['observed_at'] <= $o['known_at'], "{$id}: observation known no earlier than capture");
        if ($o['event_at'] !== null) {
            Assert::true($o['event_at'] <= $o['known_at'], "{$id}: event cannot be in future of recorded knowledge");
        }
        if ($o['known_at'] > $case['knowledge_cut'] || $o['current_reuse_right'] !== 'ACTIVE') {
            continue;
        }
        $eligible[] = $o['observation_id'];
        if ($o['kind'] === 'CHANGED') {
            $events[$o['event_id']] = true;
            $origins[$o['origin_group_id']] = true;
        }
    }
    sort($eligible, SORT_STRING);
    $wanted = $case['expected']['eligible_observation_ids'];
    sort($wanted, SORT_STRING);
    Assert::same($wanted, $eligible, "{$id}: only permission-eligible known-at evidence admitted");
    $eventIds = array_keys($events); sort($eventIds, SORT_STRING);
    $wantedEvents = $case['expected']['distinct_event_ids']; sort($wantedEvents, SORT_STRING);
    Assert::same($wantedEvents, $eventIds, "{$id}: unique events not capture count");
    $originIds = array_keys($origins); sort($originIds, SORT_STRING);
    $wantedOrigins = $case['expected']['origin_group_ids']; sort($wantedOrigins, SORT_STRING);
    Assert::same($wantedOrigins, $originIds, "{$id}: corroboration not raw source count");
}
foreach ($heldout['slots'] as $slot) {
    Assert::same('NOT_FROZEN', $slot['status'], 'LI18 heldout payload remains unavailable for scoring');
    Assert::same('NOT_SUPPLIED', $slot['payload'], 'LI18 no invented heldout material');
}
