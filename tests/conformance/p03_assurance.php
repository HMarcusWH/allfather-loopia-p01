<?php

declare(strict_types=1);

/**
 * CONFORMANCE: the P03 audits reproduce the FROZEN canonical governed transcript (P08).
 *
 * `tests/fixtures/p03_assurance_vectors.json` was produced by running canonical
 * `packages/allfather-control-substrate/src/p03/assurance.ts` (its commit and source SHA-256 are in the
 * file) over DECLARED fixtures. This suite rebuilds each declared fixture in MariaDB, runs the PHP port,
 * and compares everything the audit controls: refusal codes, reason codes in canonical order, finding
 * type and severity, the run/check/finding rows, the incident's trigger module, class, severity,
 * reason codes and related refs, row-count effects, and that the audited rows did not change.
 *
 * Identifiers are normalized to the labels the generator used (`catalogue`, `fit_hypothesis`,
 * `signal`, `queue:<label>`), so a vector compares governed outcomes, not implementation ids.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P03\Assurance as P03Assurance;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M11\Assurance as M11Assurance;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

$vectorsPath = dirname(__DIR__) . '/fixtures/p03_assurance_vectors.json';
$vectors = json_decode((string) file_get_contents($vectorsPath), true, 512, JSON_THROW_ON_ERROR);

// The provenance of the vectors is part of the claim: a vector set with no exact canonical commit
// and source digest could have come from anywhere, including the port it is meant to check.
Assert::true(preg_match('/^[0-9a-f]{40}$/', (string) $vectors['canonical_commit']) === 1, 'vectors name an exact canonical commit');
Assert::same('packages/allfather-control-substrate/src/p03/assurance.ts', $vectors['source'], 'vectors name the canonical source file');
Assert::true(preg_match('/^[0-9a-f]{64}$/', (string) $vectors['source_sha256']) === 1, 'vectors bind the canonical source digest');
Assert::true(count($vectors['cases']) >= 40, 'the vector set is not a token sample');
$families = array_count_values(array_column($vectors['cases'], 'family'));
foreach (P03Assurance::CATEGORIES as $category) {
    Assert::true(($families[$category] ?? 0) >= 5, "every audit family has positive and negative vectors: {$category}");
}
foreach (['AUDIT_PROFILE_INACTIVE', 'AUDIT_PROFILE_WRONG_CATEGORY', 'TARGET_NOT_FOUND', 'TARGET_SCOPE_MISMATCH'] as $refusal) {
    Assert::true(
        in_array($refusal, array_map(static fn (array $c): ?string => $c['expected']['reason_code'] ?? null, $vectors['cases']), true),
        "a canonical refusal vector exists for {$refusal}",
    );
}

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());

    return;
}

$clock = Harness::clock('2026-07-02T00:00:00.000Z');
$m11 = new M11Assurance($db, $clock, new DecisionLedger($db, $clock));
$p03 = new P03Assurance($db, $clock, $m11);
$T0 = '2026-06-01T00:00:00.000Z';

/** @return array{home: array{tenant: string, workspace: string}, sibling: array{tenant: string, workspace: string}, foreign: array{tenant: string, workspace: string}} */
$scopes = static function () use ($db, $clock, $T0): array {
    $home = Seed::tenantWorkspace($db, $clock, 'p03home');
    $siblingWorkspace = Ids::new();
    $db->insert('workspaces', [
        'workspace_id' => $siblingWorkspace, 'tenant_id' => $home['tenant_id'],
        'workspace_key' => 'sibling-' . substr($siblingWorkspace, 0, 8), 'schema_version' => '1',
        'display_name' => 'Sibling', 'workspace_type' => 'TEST', 'environment_class' => 'TEST',
        'external_side_effects_allowed' => 0, 'active_v1_capability_ceiling' => 'READ_ONLY',
        'status' => 'ACTIVE', 'created_at' => $T0,
    ]);
    $foreign = Seed::tenantWorkspace($db, $clock, 'p03foreign');

    return [
        'home' => ['tenant' => $home['tenant_id'], 'workspace' => $home['workspace_id']],
        'sibling' => ['tenant' => $home['tenant_id'], 'workspace' => $siblingWorkspace],
        'foreign' => ['tenant' => $foreign['tenant_id'], 'workspace' => $foreign['workspace_id']],
    ];
};

$catalogueRows = static function (array $s, array $c, array &$labels) use ($db, $T0): string {
    $sourceRefId = Ids::new();
    $cardId = Ids::new();
    $catalogueId = Ids::new();
    $db->insert('source_references', [
        'source_ref_id' => $sourceRefId, 'schema_version' => '1', 'tenant_id' => $s['tenant'], 'workspace_id' => null,
        'source_type' => 'UPLOADED_ARTIFACT', 'lawful_or_permitted_use_scope' => 'customer_owned_tenant_scoped/internal_fit_matching',
        'content_sensitivity_class' => 'INTERNAL_DEMO', 'status' => 'ACTIVE_FOR_CAPTURE', 'registered_at' => $T0,
    ]);
    $db->insert('source_license_cards', [
        'source_license_card_id' => $cardId, 'schema_version' => '1', 'tenant_id' => $s['tenant'], 'source_ref_id' => $sourceRefId,
        'source_name' => 'Synthetic catalogue', 'source_type' => 'customer_upload', 'allowed_use_class' => 'customer_owned_tenant_scoped',
        'license_class' => 'LICENSED_INTERNAL_ONLY', 'robots_policy_checked' => 1, 'terms_policy_checked' => 1,
        'pii_risk_class' => 'low', 'sensitive_data_risk_class' => 'low', 'review_status' => $c['review_status'],
        'approved_by' => 'synthetic-approver', 'approved_at' => $T0, 'review_expires_at' => $c['review_expires_at'] ?? null,
        'error_after_ms' => $c['error_after_ms'] ?? null, 'recheck_terms_after_ms' => $c['recheck_terms_after_ms'] ?? null,
        'data_ownership' => 'TENANT_OWNED', 'processing_purpose' => 'internal fit-matching', 'created_at' => $T0,
    ]);
    $db->insert('customer_product_catalogues', [
        'customer_product_catalogue_id' => $catalogueId, 'schema_version' => '1', 'tenant_id' => $s['tenant'],
        'workspace_id' => array_key_exists('workspace', $c) && $c['workspace'] === null ? null : $s['workspace'],
        'catalogue_key' => $catalogueId, 'display_name' => 'Synthetic catalogue', 'source_ref_id' => $sourceRefId,
        'source_license_card_id' => $cardId, 'catalogue_status' => $c['catalogue_status'], 'created_at' => $T0,
    ]);
    $otherSourceRefId = null;
    foreach ($c['captures'] ?? [] as $capture) {
        $ref = $sourceRefId;
        if (!empty($capture['other_source'])) {
            if ($otherSourceRefId === null) {
                $otherSourceRefId = Ids::new();
                $db->insert('source_references', [
                    'source_ref_id' => $otherSourceRefId, 'schema_version' => '1', 'tenant_id' => $s['tenant'], 'workspace_id' => null,
                    'source_type' => 'UPLOADED_ARTIFACT', 'lawful_or_permitted_use_scope' => 'customer_owned_tenant_scoped/internal_fit_matching',
                    'content_sensitivity_class' => 'INTERNAL_DEMO', 'status' => 'ACTIVE_FOR_CAPTURE', 'registered_at' => $T0,
                ]);
            }
            $ref = $otherSourceRefId;
        }
        $db->insert('evidence_objects', [
            'evidence_id' => Ids::new(), 'schema_version' => '1', 'tenant_id' => $s['tenant'], 'workspace_id' => $s['workspace'],
            'source_ref_id' => $ref, 'evidence_type' => $capture['evidence_type'] ?? 'UPLOADED_SOURCE_CAPTURE',
            'captured_content_reference_safe' => 'synthetic://catalogue', 'content_hash' => str_repeat('c', 64),
            'capture_method' => 'UPLOAD', 'captured_at' => $capture['captured_at'],
            'permitted_use_scope' => 'customer_owned_tenant_scoped/internal_fit_matching', 'evidence_status' => 'CAPTURED',
            'sensitivity_class' => 'INTERNAL_DEMO',
        ]);
    }
    foreach ($c['terms_reviews'] ?? [] as $reviewedAt) {
        $db->insert('source_terms_reviews', [
            'source_terms_review_id' => Ids::new(), 'schema_version' => '1', 'source_license_card_id' => $cardId,
            'robots_policy_checked' => 1, 'terms_policy_checked' => 1, 'reviewed_by' => 'synthetic-reviewer', 'reviewed_at' => $reviewedAt,
        ]);
    }
    $labels[$catalogueId] = 'catalogue';

    return $catalogueId;
};

$signalRow = static function (array $s, string $class, string $interpretation, string $status, string $createdAt) use ($db, $T0): string {
    $competitorId = Ids::new();
    $db->insert('competitors', [
        'competitor_id' => $competitorId, 'schema_version' => '1', 'tenant_id' => $s['tenant'], 'workspace_id' => $s['workspace'],
        'display_name' => 'Synthetic rival', 'competitor_class' => 'DIRECT', 'status' => 'ACTIVE', 'created_at' => $T0,
    ]);
    $signalId = Ids::new();
    $db->insert('company_signals', [
        'company_signal_id' => $signalId, 'schema_version' => '1', 'tenant_id' => $s['tenant'], 'workspace_id' => $s['workspace'],
        'competitor_id' => $competitorId, 'signal_class' => $class, 'interpretation_kind' => $interpretation,
        'confidence_band' => 'HIGH', 'summary_safe' => 'Synthetic signal', 'status' => $status, 'created_at' => $createdAt,
    ]);

    return $signalId;
};

$hypothesisRows = static function (array $s, array $h, array &$labels) use ($db, $T0, $catalogueRows, $signalRow): string {
    $ignored = [];
    $catalogueId = $catalogueRows($s, ['catalogue_status' => 'ACTIVE', 'review_status' => 'approved'], $ignored);
    $productId = Ids::new();
    $db->insert('customer_products', [
        'customer_product_id' => $productId, 'schema_version' => '1', 'tenant_id' => $s['tenant'],
        'customer_product_catalogue_id' => $catalogueId, 'product_key' => 'p1', 'display_name' => 'Synthetic product',
        'product_offer_kind' => 'PRODUCT', 'attributes_json' => '[]', 'product_status' => 'ACTIVE', 'created_at' => $T0,
    ]);
    $signalId = $signalRow($s, 'PRICING_CHANGE_SIGNAL', 'FACT_OBSERVED', $h['signal_status'], $h['signal_created_at']);
    $fitId = Ids::new();
    $db->insert('fit_hypotheses', [
        'fit_hypothesis_id' => $fitId, 'schema_version' => '1', 'tenant_id' => $s['tenant'], 'workspace_id' => $s['workspace'],
        'customer_product_id' => $productId, 'company_signal_id' => $signalId, 'fit_band' => 'PROBABLE',
        'claim_kind' => 'REASON_TO_INVESTIGATE', 'uncertainty_explicit' => 1, 'fit_assessment_digest' => str_repeat('d', 64),
        'status' => 'ADMITTED', 'created_at' => $T0,
    ]);
    $labels[$fitId] = 'fit_hypothesis';
    $labels[$signalId] = 'signal';

    return $fitId;
};

$queueItem = static function (array $s, array $q, array &$labels) use ($db, $T0, $signalRow): void {
    $signalId = $signalRow($s, $q['signal_class'], $q['interpretation_kind'], 'RELEASED', $T0);
    $itemId = Ids::new();
    // The canonical fixture: a queue item that bypassed the cp5 flow and the M08 gate, carrying
    // synthetic hypothesis/verdict ids. Foreign-key checks are suspended for this one row only.
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        $db->insert('priority_outreach_queue_items', [
            'priority_outreach_queue_item_id' => $itemId, 'schema_version' => '1', 'tenant_id' => $s['tenant'],
            'workspace_id' => $s['workspace'], 'fit_hypothesis_id' => 'synthetic-fit-hypothesis',
            'triggering_company_signal_id' => $signalId, 'arbiter_verdict_id' => 'synthetic-arbiter-verdict',
            'queue_status' => $q['queue_status'] ?? 'OPEN', 'created_at' => $T0,
        ]);
    } finally {
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
    $labels[$itemId] = 'queue:' . $q['label'];
};

$auditedDigest = static function (Database $db, array $s): string {
    $tenants = [$s['home']['tenant'], $s['foreign']['tenant']];
    $hash = hash_init('sha256');
    foreach ([
        'customer_product_catalogues' => 'customer_product_catalogue_id', 'source_license_cards' => 'source_license_card_id',
        'source_references' => 'source_ref_id', 'evidence_objects' => 'evidence_id', 'company_signals' => 'company_signal_id',
        'fit_hypotheses' => 'fit_hypothesis_id', 'priority_outreach_queue_items' => 'priority_outreach_queue_item_id',
        'customer_products' => 'customer_product_id', 'competitors' => 'competitor_id', 'audit_profiles' => 'audit_profile_id',
    ] as $table => $pk) {
        foreach ($db->all("SELECT * FROM {$table} WHERE tenant_id IN (?, ?) ORDER BY {$pk}", $tenants) as $row) {
            hash_update($hash, $table . json_encode($row));
        }
    }
    foreach ($db->all(
        'SELECT r.* FROM source_terms_reviews r JOIN source_license_cards c ON c.source_license_card_id = r.source_license_card_id
         WHERE c.tenant_id IN (?, ?) ORDER BY r.source_terms_review_id',
        $tenants
    ) as $row) {
        hash_update($hash, 'terms' . json_encode($row));
    }

    return hash_final($hash);
};

$countTables = ['audit_runs', 'audit_check_records', 'audit_findings', 'incident_evaluation_requests', 'incident_cases'];
$counts = static function () use ($db, $countTables): array {
    $out = [];
    foreach ($countTables as $table) {
        $out[$table] = (int) $db->scalar("SELECT COUNT(*) FROM {$table}");
    }

    return $out;
};

foreach ($vectors['cases'] as $case) {
    $name = $case['name'];
    $setup = $case['setup'];
    $input = $case['input'];
    $expected = $case['expected'];
    $s = $scopes();
    $labels = [];

    $profileScope = $s[$setup['profile']['scope'] ?? 'home'];
    $profileId = $m11->createAuditProfile(
        $profileScope['tenant'], $profileScope['workspace'], 'p03-' . $setup['profile']['category'],
        $setup['profile']['category'], 'P03 conformance', $setup['profile']['status'] ?? 'ACTIVE',
    );
    $labels[$profileId] = 'profile';

    $common = [
        'audit_profile_id' => $profileId,
        'as_of' => $input['asOf'],
        'requested_by_actor_type' => $input['requestedByActorType'] ?? null,
    ];
    $common = array_filter($common, static fn ($v): bool => $v !== null);

    if ($case['family'] === 'CATALOGUE_FRESHNESS') {
        $target = $setup['catalogue'] === null ? Ids::new() : $catalogueRows($s[$setup['catalogue']['scope'] ?? 'home'], $setup['catalogue'], $labels);
        $before = $auditedDigest($db, $s);
        $countsBefore = $counts();
        $outcome = $p03->runCatalogueFreshnessAudit([
            ...$common,
            'customer_product_catalogue_id' => $target,
            ...(array_key_exists('freshnessWindowMs', $input) ? ['freshness_window_ms' => $input['freshnessWindowMs']] : []),
        ]);
    } elseif ($case['family'] === 'SIGNAL_PAIRING_FRESHNESS') {
        $target = $setup['hypothesis'] === null ? Ids::new() : $hypothesisRows($s[$setup['hypothesis']['scope'] ?? 'home'], $setup['hypothesis'], $labels);
        $before = $auditedDigest($db, $s);
        $countsBefore = $counts();
        $outcome = $p03->runSignalPairingFreshnessAudit([
            ...$common,
            'fit_hypothesis_id' => $target,
            'signal_pairing_freshness_window_ms' => $input['signalPairingFreshnessWindowMs'],
        ]);
    } else {
        foreach ($setup['queue_items'] as $q) {
            $queueItem($s[$q['scope'] ?? 'home'], $q, $labels);
        }
        $before = $auditedDigest($db, $s);
        $countsBefore = $counts();
        $outcome = $p03->runBuyerIntentInvariantAudit($common);
    }

    $relabel = static fn (mixed $v): mixed => is_string($v) && isset($labels[$v]) ? $labels[$v] : $v;
    $sorted = static function (array $values) use ($relabel): array {
        $mapped = array_map($relabel, $values);
        sort($mapped, SORT_STRING);

        return $mapped;
    };

    Assert::same($expected['audited_rows_unchanged'], $auditedDigest($db, $s) === $before, "{$name}: audited rows unchanged");
    $countsAfter = $counts();
    $delta = [];
    foreach ($countTables as $table) {
        $delta[$table] = $countsAfter[$table] - $countsBefore[$table];
    }
    Assert::same($expected['row_counts'], $delta, "{$name}: governed row effects");

    if ($expected['outcome'] === 'REJECTED') {
        Assert::true($outcome->isRefusal(), "{$name}: refused");
        Assert::same($expected['reason_code'], $outcome->isRefusal() ? $outcome->reasonCode() : null, "{$name}: refusal code");
        continue;
    }

    Assert::true($outcome->isOk(), "{$name}: completed" . ($outcome->isOk() ? '' : ' (refused ' . $outcome->reasonCode() . ')'));
    if (!$outcome->isOk()) {
        continue;
    }
    $value = $outcome->value();
    Assert::same($expected['finding_type'], $value['finding_type'], "{$name}: finding type");
    Assert::same($expected['failed'], $value['failed'], "{$name}: failed");
    if ($case['family'] === 'BUYER_INTENT_INVARIANT') {
        Assert::same($expected['violation_count'], $value['violation_count'], "{$name}: violation count");
        Assert::same($expected['violating_queue_items'], $sorted($value['violating_queue_item_ids']), "{$name}: violating items");
    } else {
        Assert::same($expected['reason_codes'], $value['reason_codes'], "{$name}: reason codes in canonical order");
    }

    $run = $db->one('SELECT * FROM audit_runs WHERE audit_run_id = ?', [$value['audit_run_id']]);
    Assert::same($expected['audit_run'], [
        'audit_profile_id' => $relabel((string) $run['audit_profile_id']),
        'audit_category' => (string) $run['audit_category'],
        'target_object_refs' => $sorted(json_decode((string) $run['target_object_refs'], true)),
        'requested_by_actor_type' => (string) $run['requested_by_actor_type'],
        'status' => (string) $run['status'],
        'summary' => (string) $run['summary'],
        'scope' => (string) $run['tenant_id'] === $s['home']['tenant'] && (string) $run['workspace_id'] === $profileScope['workspace'] ? 'profile' : 'OTHER',
    ], "{$name}: audit run");

    $check = $db->one('SELECT * FROM audit_check_records WHERE audit_check_record_id = ?', [$value['audit_check_record_id']]);
    Assert::same($expected['check'], [
        'check_key' => (string) $check['check_key'],
        'decision' => (string) $check['decision'],
        'reason_codes' => json_decode((string) $check['reason_codes'], true),
        'severity_if_failed' => $check['severity_if_failed'],
        'safe_summary' => (string) $check['safe_summary'],
    ], "{$name}: check record");

    $finding = $db->one('SELECT * FROM audit_findings WHERE audit_finding_id = ?', [$value['audit_finding_id']]);
    Assert::same($expected['finding'], [
        'finding_type' => (string) $finding['finding_type'],
        'finding_status' => (string) $finding['finding_status'],
        'severity' => (string) $finding['severity'],
        'confidence_classification' => (string) $finding['confidence_classification'],
        'current_reliance_consequence' => (string) $finding['current_reliance_consequence'],
        'safe_finding_summary' => (string) $finding['safe_finding_summary'],
        'reason_codes' => json_decode((string) $finding['reason_codes'], true),
        'incident_evaluation_required' => (int) $finding['incident_evaluation_required'],
        'decision_ledger_record_required' => (int) $finding['decision_ledger_record_required'],
    ], "{$name}: finding");

    if ($expected['incident'] === null) {
        Assert::same(null, $value['incident_case_id'], "{$name}: no incident on a pass");
        continue;
    }
    $kase = $db->one('SELECT * FROM incident_cases WHERE incident_case_id = ?', [$value['incident_case_id']]);
    $request = $db->one(
        'SELECT * FROM incident_evaluation_requests WHERE incident_evaluation_request_id = ?',
        [(string) $kase['incident_evaluation_request_id']]
    );
    Assert::same($expected['incident'], [
        'trigger_owner_module_id' => (string) $request['trigger_owner_module_id'],
        'trigger_reason_codes' => json_decode((string) $request['trigger_reason_codes'], true),
        'trigger_severity' => (string) $request['trigger_severity'],
        'related_object_refs' => $sorted(json_decode((string) $request['related_object_refs'], true)),
        'incident_class' => (string) $kase['incident_class'],
        'severity' => (string) $kase['severity'],
        'status' => (string) $kase['status'],
        'current_release_or_operation_consequence' => (string) $kase['current_release_or_operation_consequence'],
        'safe_summary' => (string) $kase['safe_summary'],
        'scope' => (string) $kase['tenant_id'] === $profileScope['tenant'] && (string) $kase['workspace_id'] === $profileScope['workspace'] ? 'profile' : 'OTHER',
    ], "{$name}: incident");
}
