<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/Ob01Fixture.php';

use Allfather\Loopia\PromptOps\{ObservationMaterialityV2,PromptBundleRegistry,PromptSelectionResolver};
use Allfather\Loopia\Provider\PreparedModelRequest;
use Allfather\Loopia\Tests\{Assert,Harness,Ob01Fixture};

/**
 * Real disposable MariaDB proves that synthetic v2 composition creates NO
 * prompt approvals, provider attempts, evidence snapshots or release records.
 *
 * The native DB remains fully owned by M02/M05/M06/PromptOps and cannot be
 * inferred from this offline test harness.
 */
$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());
    return;
}
$before = [
    'approvals' => (int) $db->scalar('SELECT COUNT(*) FROM monitor_prompt_approvals'),
    'calls' => (int) $db->scalar('SELECT COUNT(*) FROM provider_call_records'),
    'snapshots' => (int) $db->scalar('SELECT COUNT(*) FROM source_snapshots'),
    'released' => (int) $db->scalar('SELECT COUNT(*) FROM released_briefings'),
];
$cases = Ob01Fixture::loadCases()['cases'];
$observed = [];
foreach ($cases as $row) {
    $fixture = Ob01Fixture::scenario($row);
    $context = Ob01Fixture::context($fixture);
    $result = ObservationMaterialityV2::composeFixture($fixture, $context);
    Assert::true($result->isOk(), $row['id'] . ': exact v2 fixture can compose with a migrated DB present');
    if ($result->isOk()) {
        $observed[] = $result->value()['candidate_digest'];
        Assert::same(false, $result->value()['provider_dispatch_authorized'],
            'no implicit paid dispatch with a DB present');
    }
}
Assert::same(count($cases), count($observed), 'all synthetic cases accepted mechanically');
$resolver = PromptSelectionResolver::inert();
$v2 = $resolver->contentVersioned('P01_SIGNAL_CLASSIFIER',
    PromptBundleRegistry::OBSERVATION_V2_PACK)->value();
Assert::same('PROMPT_OBSERVATION_V2_U01_NOT_ADOPTED',
    $resolver->selectVersioned('P01_SIGNAL_CLASSIFIER', PromptBundleRegistry::OBSERVATION_V2_PACK,
        ['tenant_id' => Ob01Fixture::TENANT_ID, 'purpose' => 'MANUAL_MONITOR'])->reasonCode(),
    'even a claimed scope/purpose cannot select a v2 production grant');
Assert::same('MODEL_PROPOSED_OBSERVATION_V2_INERT',
    PreparedModelRequest::prepare($v2, Ob01Fixture::context(Ob01Fixture::scenario($cases[0])))->reasonCode(),
    'bound provider factory refuses proposed v2 before any request/cost');
$after = [
    'approvals' => (int) $db->scalar('SELECT COUNT(*) FROM monitor_prompt_approvals'),
    'calls' => (int) $db->scalar('SELECT COUNT(*) FROM provider_call_records'),
    'snapshots' => (int) $db->scalar('SELECT COUNT(*) FROM source_snapshots'),
    'released' => (int) $db->scalar('SELECT COUNT(*) FROM released_briefings'),
];
Assert::same($before, $after,
    'synthetic v2 may not create prompt approvals, paid calls, M05 snapshots or released briefings');
