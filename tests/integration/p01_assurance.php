<?php

declare(strict_types=1);

/**
 * The two P01 audits — the ones that ask whether what the product is saying is still true.
 *
 * Both are READ-ONLY over what they audit, and a failing audit OPENS AN INCIDENT rather than
 * recording a quiet finding. Those two properties are what make an audit worth running: one that
 * repaired what it found would leave no evidence the fault existed, and one nobody is required to
 * act on is indistinguishable from no audit at all.
 */

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P01\Assurance as P01Assurance;
use Allfather\Loopia\Product\P01\Registry as P01Registry;
use Allfather\Loopia\Product\P01\SourceCards;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M11\Assurance as M11Assurance;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());

    return;
}

$clock = Harness::clock();
$scope = Seed::tenantWorkspace($db, $clock);
$m11 = new M11Assurance($db, $clock, new DecisionLedger($db, $clock));
$p01 = new P01Assurance($db, $clock, $m11);

$freshnessProfile = $m11->createAuditProfile(
    $scope['tenant_id'], $scope['workspace_id'], 'p01.freshness', 'SOURCE_FRESHNESS', 'Source freshness'
);
$diffProfile = $m11->createAuditProfile(
    $scope['tenant_id'], $scope['workspace_id'], 'p01.diff', 'DIFF_INTEGRITY', 'Diff integrity'
);

// ============================================================ the profile category is authority

// A freshness profile must not back a diff-integrity finding: the finding would cite an audit that
// never asked the question it answers.
Assert::same(
    'AUDIT_PROFILE_CATEGORY_NOT_DIFF_INTEGRITY',
    $p01->runDiffIntegrityAudit([
        'audit_profile_id' => $freshnessProfile,
        'source_diff_id' => Ids::new(),
    ])->reasonCode(),
    'a freshness profile cannot back a diff-integrity audit',
);
Assert::same(
    'AUDIT_PROFILE_CATEGORY_NOT_SOURCE_FRESHNESS',
    $p01->runSourceFreshnessAudit([
        'audit_profile_id' => $diffProfile,
        'monitored_source_id' => Ids::new(),
    ])->reasonCode(),
    'and the reverse',
);

// A foreign target cannot be audited into this profile's scope.
$rival = Seed::tenantWorkspace($db, $clock, 'rival');
$rivalChain = Seed::p01SignalChain(
    $db, $clock, $rival,
    (new \Allfather\Loopia\Substrate\M04\Proposals($db, $clock))->createMission(
        $rival['tenant_id'], $rival['workspace_id'], 'COMPETITION_MONITORING', 'Rival', 'Rival.',
        ['OBSERVE', 'ANALYZE'], 'OPERATOR', $rival['operator_id']
    ),
    null
);
Assert::same(
    'MONITORED_SOURCE_NOT_FOUND_OR_OUT_OF_SCOPE',
    $p01->runSourceFreshnessAudit([
        'audit_profile_id' => $freshnessProfile,
        'monitored_source_id' => $rivalChain['monitored_source_id'],
    ])->reasonCode(),
    "another tenant's source cannot be audited under this profile",
);

// ============================================================ source freshness

$missionId = (new \Allfather\Loopia\Substrate\M04\Proposals($db, $clock))->createMission(
    $scope['tenant_id'], $scope['workspace_id'], 'COMPETITION_MONITORING', 'Monitor', 'Monitor.',
    ['OBSERVE', 'ANALYZE'], 'OPERATOR', $scope['operator_id']
);

/** A fresh chain per case, so a refusal is caused by the case's own override and nothing else. */
$freshness = static function (array $overrides, array $auditInput = []) use ($db, $clock, $scope, $missionId, $p01, $freshnessProfile): array {
    $chain = Seed::p01SignalChain($db, $clock, $scope, $missionId, null, $overrides);

    return $p01->runSourceFreshnessAudit([
        'audit_profile_id' => $freshnessProfile,
        'monitored_source_id' => $chain['monitored_source_id'],
        ...$auditInput,
    ])->value();
};

$healthy = $freshness([]);
Assert::same('CONTROL_PASS', $healthy['finding_type'], 'a current source passes');
Assert::false($healthy['stale'], 'and is not stale');
Assert::same(null, $healthy['incident_case_id'], 'opening no incident');

// Each stale condition contributes its OWN reason code, so an operator sees which rule fired.
foreach ([
    ['overrides' => ['review_status' => 'expired'], 'code' => 'SOURCE_REVIEW_STATUS_EXPIRED',
     'why' => 'an expired review keeps its own freshness-specific code'],
    ['overrides' => ['review_status' => 'blocked'], 'code' => 'SOURCE_REVIEW_NOT_APPROVED',
     'why' => 'and every other non-approved state shares one'],
    ['overrides' => ['review_expires_at' => '2020-01-01T00:00:00.000Z'], 'code' => 'SOURCE_REVIEW_PAST_EXPIRY',
     'why' => 'a lapsed review window is separate from the status'],
] as $case) {
    $result = $freshness($case['overrides']);
    Assert::true($result['stale'], "stale: {$case['why']}");
    Assert::true(in_array($case['code'], $result['reason_codes'], true), $case['why']);
    Assert::same('EVIDENCE_OR_AUTHORITY_GAP', $result['finding_type'], 'recorded as an authority gap');
    Assert::notSame(null, $result['incident_case_id'], 'and an incident IS opened — never a quiet finding');
}

// An ERROR window with NO capture at all is stale. A never-captured source cannot be "within
// freshness" — the window has nothing to measure, and an unmeasurable window fails closed.
//
// This is built through the real registry rather than the signal-chain seed, because the state
// under test is precisely a source that HAS no snapshots — which a chain built around a diff
// cannot be.
$cards = new SourceCards($db, $clock);
$registry = new P01Registry($db, $clock);
$bareCard = $cards->project([
    'tenant_id' => $scope['tenant_id'],
    'source_name' => 'Never captured',
    'source_url_safe' => 'https://never.example/page',
    'source_type' => 'website',
    'allowed_use_class' => 'derived_summary_allowed',
    'license_class' => 'PUBLIC_WEB_ALLOWED',
    'robots_policy_checked' => true,
    'terms_policy_checked' => true,
    'review_status' => 'approved',
    'error_after_ms' => 3_600_000,
]);
$bareCompetitor = $registry->registerCompetitor(
    $scope['tenant_id'], $scope['workspace_id'], 'Never Captured Ltd', 'DIRECT', 'OPERATOR', $scope['operator_id']
)->value()['competitor_id'];
$bareSourceId = $registry->registerMonitoredSource(
    $scope['tenant_id'], $scope['workspace_id'], $bareCompetitor,
    $bareCard['source_ref_id'], $bareCard['source_license_card_id'], 'WEBSITE'
)->value()['monitored_source_id'];

$neverCaptured = $p01->runSourceFreshnessAudit([
    'audit_profile_id' => $freshnessProfile,
    'monitored_source_id' => $bareSourceId,
])->value();
Assert::true($neverCaptured['stale'], 'a source with an error window and no capture is stale');
Assert::equalsList(
    ['NO_SNAPSHOT_FOR_FRESHNESS_WINDOW'],
    $neverCaptured['reason_codes'],
    'named specifically — nothing was captured, rather than something being too old',
);

// A caller-supplied window WINS over the card's, and an ancient capture is stale under it.
$stale = $freshness([], ['freshness_window_ms' => 1, 'as_of' => '2030-01-01T00:00:00.000Z']);
Assert::true(
    in_array('SNAPSHOT_OLDER_THAN_FRESHNESS_WINDOW', $stale['reason_codes'], true),
    'a capture older than the effective window is stale',
);

// The WARN tier never makes a source stale and never opens an incident — that is the whole
// difference between an early warning and a finding.
$warned = $freshness(['warn_after_ms' => 1], ['as_of' => '2030-01-01T00:00:00.000Z']);
Assert::false($warned['stale'], 'a warn-only threshold does not make the source stale');
Assert::true($warned['warn'], 'but the warning IS raised');
Assert::equalsList(['SNAPSHOT_OLDER_THAN_WARN_WINDOW'], $warned['warn_codes'], 'with its own code');
Assert::same(null, $warned['incident_case_id'], 'and no incident is opened for a warning');
Assert::same('CONTROL_PASS', $warned['finding_type'], 'the finding is still a pass');

// A card that requires a periodic terms recheck and has NO review at all is an authority gap.
$noReview = $freshness(['recheck_terms_after_ms' => 3_600_000, 'skip_terms_review' => true]);
Assert::true(
    in_array('TERMS_RECHECK_OVERDUE', $noReview['reason_codes'], true),
    'a card requiring periodic terms review with none on file is an authority gap',
);

// ============================================================ diff integrity

$diffChain = Seed::p01SignalChain($db, $clock, $scope, $missionId, null);
$sourceDiffId = $diffChain['source_diff_id'];

$reconciles = $p01->runDiffIntegrityAudit([
    'audit_profile_id' => $diffProfile,
    'source_diff_id' => $sourceDiffId,
])->value();
Assert::same('CONTROL_PASS', $reconciles['finding_type'], 'an intact diff reconciles');
Assert::true($reconciles['reconciles'], 'and says so');
Assert::same(null, $reconciles['incident_case_id'], 'opening no incident');

// Tamper with the stored digest. The audit re-derives from the CITED SNAPSHOTS, so the record no
// longer describes what it cites.
//
// `source_diffs` is append-only, so the tamper needs the trigger lifted — which is itself the
// point: no application path can produce this state, and the audit exists to catch what only a
// direct write could have done. The trigger is captured and restored immediately, and the
// restoration is PROVED, because a test that left the schema weaker would silently disarm every
// later assertion in the suite.
$diffTrigger = (string) $db->scalar(
    "SELECT action_statement FROM information_schema.triggers
     WHERE trigger_schema = DATABASE() AND trigger_name = 'trg_source_diffs_no_update'"
);
$db->run('DROP TRIGGER trg_source_diffs_no_update');
$db->run('UPDATE source_diffs SET diff_digest = ? WHERE source_diff_id = ?', [
    str_repeat('a', 64), $sourceDiffId,
]);
$tampered = $p01->runDiffIntegrityAudit([
    'audit_profile_id' => $diffProfile,
    'source_diff_id' => $sourceDiffId,
])->value();
Assert::same('INTEGRITY_FAILURE', $tampered['finding_type'], 'a tampered digest is an integrity failure');
Assert::equalsList(
    ['DIFF_DIGEST_DOES_NOT_RECONCILE'],
    $tampered['reason_codes'],
    'named specifically',
);
Assert::notSame(null, $tampered['incident_case_id'], 'and an incident IS opened');

// THE AUDIT REPAIRED NOTHING. The tampered digest is still tampered — which is what makes the
// incident meaningful, and what a repairing audit would have destroyed.
Assert::same(
    str_repeat('a', 64),
    (string) $db->scalar('SELECT diff_digest FROM source_diffs WHERE source_diff_id = ?', [$sourceDiffId]),
    'and the audit repaired NOTHING — a fixing audit would leave no evidence the fault existed',
);

// A diff citing a snapshot from ANOTHER source is a corrupt citation. Recomputing against the
// foreign snapshot would RECONCILE and mask it as a pass — so scope is checked before the digest.
$otherChain = Seed::p01SignalChain($db, $clock, $scope, $missionId, null);
$foreignSnapshot = (string) $db->scalar(
    'SELECT after_snapshot_id FROM source_diffs WHERE source_diff_id = ?',
    [$otherChain['source_diff_id']]
);
$forgedDiffId = $diffChain['source_diff_id'];
$before = (string) $db->scalar('SELECT before_snapshot_id FROM source_diffs WHERE source_diff_id = ?', [$forgedDiffId]);
$beforeHash = (string) $db->scalar('SELECT content_hash FROM source_snapshots WHERE source_snapshot_id = ?', [$before]);
$foreignHash = (string) $db->scalar('SELECT content_hash FROM source_snapshots WHERE source_snapshot_id = ?', [$foreignSnapshot]);
// The forged row is given a digest that RECONCILES against the foreign pair, which is exactly the
// state a scope-blind audit would pass.
$db->run('UPDATE source_diffs SET after_snapshot_id = ?, diff_digest = ? WHERE source_diff_id = ?', [
    $foreignSnapshot,
    Digest::canonicalDigest([
        'beforeSnapshotId' => $before,
        'afterSnapshotId' => $foreignSnapshot,
        'beforeContentHash' => $beforeHash,
        'afterContentHash' => $foreignHash,
    ]),
    $forgedDiffId,
]);
$db->run("CREATE TRIGGER trg_source_diffs_no_update BEFORE UPDATE ON source_diffs FOR EACH ROW {$diffTrigger}");
Assert::throws(
    static fn () => $db->run('UPDATE source_diffs SET diff_summary_safe = ? WHERE source_diff_id = ?', [
        'x', $forgedDiffId,
    ]),
    \PDOException::class,
    'the append-only trigger is restored and enforcing again',
);

$outOfScope = $p01->runDiffIntegrityAudit([
    'audit_profile_id' => $diffProfile,
    'source_diff_id' => $forgedDiffId,
])->value();
Assert::same(
    'INTEGRITY_FAILURE',
    $outOfScope['finding_type'],
    'a cross-source citation is an integrity failure EVEN THOUGH its digest reconciles',
);
Assert::equalsList(
    ['CITED_SNAPSHOT_OUT_OF_SCOPE'],
    $outOfScope['reason_codes'],
    'named as a scope violation, not as a digest mismatch — the digest was correct',
);

// ============================================================ every audit leaves its own evidence

$runs = (int) $db->scalar('SELECT COUNT(*) FROM audit_runs WHERE tenant_id = ?', [$scope['tenant_id']]);
Assert::same(
    $runs,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM audit_check_records c JOIN audit_runs r ON r.audit_run_id = c.audit_run_id
         WHERE r.tenant_id = ?',
        [$scope['tenant_id']]
    ),
    'every audit run left exactly one check record',
);
Assert::same(
    $runs,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM audit_findings f JOIN audit_runs r ON r.audit_run_id = f.audit_run_id
         WHERE r.tenant_id = ?',
        [$scope['tenant_id']]
    ),
    'and exactly one finding — a run without either would be an assertion that reasoning happened',
);
Assert::same(
    0,
    (int) $db->scalar("SELECT COUNT(*) FROM audit_runs WHERE tenant_id = ? AND status <> 'COMPLETED'", [
        $scope['tenant_id'],
    ]),
    'and every run reached COMPLETED rather than being left RUNNING',
);
