<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ops;

/**
 * BF-00: a read-only verifier of build-candidate registries.
 *
 * No returned record is a source-use, provider-execution, SQL migration,
 * customer-release or production authority. Runtime authorization must keep
 * using the existing native owner/admission contracts.
 */
final class BuildFirstRegistry
{
    public const GATES = ['U01', 'U02', 'U03', 'U04', 'U05', 'U06', 'U07', 'U08', 'U09', 'U10'];
    public const FAMILIES = ['260', '265', '270', '280', '290', '300', '310', '320', '330', '340', '345', '350'];
    public const BUILD_STATUSES = [
        'NOT_STARTED', 'IN_PROGRESS', 'IMPLEMENTATION_INCOMPLETE', 'CONTRACT_BLOCKED',
        'BUILT_INERT', 'BUILD_HYGIENE_PASS', 'FROZEN', 'VERIFIED_IN_SCOPE',
        'OWNER_ADMITTED', 'RELEASED_IN_SCOPE',
    ];

    /** @return array{decisions:array,capabilities:array,schema:array} */
    public static function loadAndValidate(string $root): array
    {
        $root = rtrim($root, '/') . '/';
        $d = self::read($root . 'docs/longitudinal/BUILD_FIRST_DECISION_MATRIX.json');
        $c = self::read($root . 'docs/longitudinal/build-first/CAPABILITY_MATRIX.json');
        $s = self::read($root . 'docs/longitudinal/build-first/SCHEMA_FAMILY_REGISTRY.json');
        $lock = self::read($root . 'upstream.lock.json');
        self::validate($d, $c, $s, $lock);
        $migration900 = $root . 'database/migrations/900_deferred_foreign_keys.sql';
        $bytes = @file_get_contents($migration900);
        self::require(is_string($bytes), 'LEGACY_900_MISSING');
        $gitBlobSha1 = sha1('blob ' . strlen($bytes) . "\0" . $bytes);
        self::require($gitBlobSha1 === $s['legacy_migration_900']['source_git_blob_sha1'], 'LEGACY_900_CHANGED');
        return ['decisions' => $d, 'capabilities' => $c, 'schema' => $s];
    }

    /** @return array<string,mixed> */
    private static function read(string $filename): array
    {
        $text = @file_get_contents($filename);
        if (!is_string($text)) {
            throw new \DomainException('BF00_MISSING_DOCUMENT');
        }
        try {
            $json = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \DomainException('BF00_INVALID_JSON', 0, $e);
        }
        if (!is_array($json) || array_is_list($json)) {
            throw new \DomainException('BF00_DOCUMENT_NOT_OBJECT');
        }
        return $json;
    }

    /** @param array<string,mixed> $d
     *  @param array<string,mixed> $c
     *  @param array<string,mixed> $s
     *  @param array<string,mixed> $lock
     */
    public static function validate(array $d, array $c, array $s, array $lock): void
    {
        self::require(($d['schema_version'] ?? null) === 'allfather.build_first.decisions.v1', 'DECISION_VERSION');
        self::require(($c['schema_version'] ?? null) === 'allfather.build_first.capabilities.v1', 'CAPABILITY_VERSION');
        self::require(($s['schema_version'] ?? null) === 'allfather.build_first.schema_registry.v1', 'SCHEMA_VERSION');
        $source = $d['source_snapshot'] ?? [];
        self::require(is_array($source), 'SOURCE_MISSING');
        foreach ([$c, $s] as $doc) {
            self::require(($doc['source_snapshot'] ?? null) === $source, 'SOURCE_DRIFT');
            self::require(($doc['execution_manifest_sha256'] ?? null) === ($d['execution_manifest_sha256'] ?? null), 'PLAN_DRIFT');
        }
        self::require(preg_match('/^[0-9a-f]{64}$/D', (string) ($d['execution_manifest_sha256'] ?? '')) === 1, 'PLAN_DIGEST');
        foreach (['base_sha','base_tree','canonical_inspected_sha','canonical_inspected_tree'] as $k) {
            self::require(preg_match('/^[0-9a-f]{40}$/D', (string) ($source[$k] ?? '')) === 1, 'SOURCE_SHA_' . $k);
        }
        self::require(($d['grant_inheritance'] ?? null) === 'NONE', 'GRANT_INHERITANCE');
        self::require(($d['adopted_pin_modified'] ?? null) === false, 'PIN_MODIFIED');
        self::require(($source['adopted_upstream_pin'] ?? null) === 'c6d9cf9a7a1001b001ca6118999e9cb75c831c4d', 'BASE_PIN');
        self::require(($lock['projection_pinned_head'] ?? null) === $source['adopted_upstream_pin'], 'LOCK_PIN_DRIFT');
        self::require(($lock['action_classes'] ?? null) === ['A','B','C'], 'ACTION_CLASS_DRIFT');
        self::require(!in_array('P07-B', $lock['scope']['product_modules'] ?? [], true), 'EXTERNAL_SEND');
        foreach (['customer_visible_output_authorized','external_action_authorized','owner_go_granted','production_launch_authorized'] as $key) {
            self::require(($lock['claim_boundary'][$key] ?? null) === false, 'LOCK_' . $key);
        }

        $gates = [];
        foreach ($d['decisions'] ?? [] as $decision) {
            self::require(is_array($decision), 'GATE_RECORD');
            $id = $decision['id'] ?? null;
            self::require(is_string($id) && in_array($id, self::GATES, true) && !isset($gates[$id]), 'GATE_ID');
            $gates[$id] = true;
            foreach (['semantic_adopted','php_adopted','execution_authorized','historical_use_authorized',
                'provider_disclosure_authorized','customer_output_authorized','production_authorized'] as $key) {
                self::require(($decision[$key] ?? null) === false, 'UNADMITTED_' . $id);
            }
            self::require(is_array($decision['independent_approvals'] ?? null), 'GATE_REVIEW_MISSING');
            if ($id === 'U03') {
                self::require(count($decision['independent_approvals']) === 6, 'U03_SIX_OWNER_COUNT');
                foreach ($decision['independent_approvals'] as $state) {
                    self::require($state === 'PENDING', 'U03_PREMATURE_RATIFICATION');
                }
            }
            if ($id === 'U04') {
                self::require(($decision['selected_option'] ?? null) === 'OWNER_SELECTED_U04_A_A2_B_NOT_CROSS_OWNER_ADOPTED', 'U04_OPTION');
                self::require(($decision['independent_approvals']['cross-owner adoption'] ?? null) === 'PENDING', 'U04_CROSS_OWNER');
            }
        }
        self::require(count($gates) === 10, 'GATE_COVERAGE');

        self::require(($c['build_status_vocabulary'] ?? null) === self::BUILD_STATUSES, 'STATUS_VOCABULARY');
        self::require(($c['existing_runtime_ceiling'] ?? null) === ['A','B','C'], 'CAPABILITY_CEILING');
        self::require(in_array('P07-B', $c['excluded_effects'] ?? [], true), 'PROHIBITED_EFFECT_LOST');
        self::require(($c['new_factory_default'] ?? null) === 'NOT_REGISTERED', 'FACTORY_DEFAULT');
        self::require(($c['live_factory_bindings'] ?? null) === [], 'NEW_LIVE_FACTORY');
        $packages = [];
        foreach ($c['build_packages'] ?? [] as $p) {
            self::require(is_array($p), 'BUILD_PACKAGE');
            $id = $p['id'] ?? null;
            self::require(is_string($id) && preg_match('/^[A-Z]{2}-[0-9]{2}$/D', $id) === 1 && !isset($packages[$id]), 'PACKAGE_ID');
            self::require(is_int($p['wave'] ?? null) && $p['wave'] >= 0 && $p['wave'] <= 15, 'PACKAGE_WAVE');
            self::require(in_array($p['build_status'] ?? null, self::BUILD_STATUSES, true), 'PACKAGE_STATUS');
            foreach (['native_permission','customer_visibility','production_activation','live_migration','external_effects'] as $key) {
                self::require(($p[$key] ?? null) === false, 'PACKAGE_SELF_ACTIVATION_' . $id);
            }
            self::require(($p['candidate_factory_binding'] ?? null) === 'NOT_REGISTERED', 'PACKAGE_FACTORY_' . $id);
            foreach ($p['owner_gates'] ?? [] as $gate) {
                self::require(isset($gates[$gate]), 'PACKAGE_GATE_' . $id);
            }
            $packages[$id] = $p;
        }
        self::require(count($packages) === 29, 'PACKAGE_COUNT');
        self::require(($packages['BF-00']['depends_on'] ?? null) === [], 'BF00_NOT_ROOT');
        self::require(($packages['RT-02']['wave'] ?? null) === 15, 'RT02_NOT_LAST');
        foreach ($packages as $id => $p) {
            foreach ($p['depends_on'] ?? [] as $dep) {
                self::require(isset($packages[$dep]) && $dep !== $id && $packages[$dep]['wave'] < $p['wave'], 'DAG_' . $id);
            }
        }

        self::require(($s['ddl_generated'] ?? null) === false && ($s['any_live_migrations_applied'] ?? null) === false, 'DDL_FALSE_CLAIM');
        self::require(($s['foreign_key_columns_final'] ?? null) === false && ($s['index_columns_final'] ?? null) === false, 'DDL_DESIGN_FINAL');
        self::require(($s['legacy_migration_900']['preserve_original_bytes'] ?? null) === true, '900_IMMUTABLE');
        self::require(($s['legacy_migration_900']['never_replay_for_new_families'] ?? null) === true, '900_REPLAY');
        self::require(($s['legacy_migration_900']['path'] ?? null) === 'database/migrations/900_deferred_foreign_keys.sql', '900_PATH');
        $families = [];
        $tables = [];
        $foreignKeyNames = [];
        $indexNames = [];
        foreach ($s['families'] ?? [] as $family) {
            self::require(is_array($family), 'FAMILY_RECORD');
            $label = $family['label'] ?? null;
            self::require(is_string($label) && in_array($label, self::FAMILIES, true) && !isset($families[$label]), 'FAMILY_ID');
            $families[$label] = true;
            self::require(($family['status'] ?? null) === 'CANDIDATE_NOT_INSTALLED', 'FAMILY_LIVE');
            self::require(is_string($family['owner'] ?? null) && $family['owner'] !== '', 'FAMILY_OWNER');
            self::require(($family['recovery']['host_restored'] ?? null) === false, 'FALSE_RESTORE');
            foreach ($family['owner_gates'] ?? [] as $gate) {
                self::require(isset($gates[$gate]), 'FAMILY_GATE');
            }
            self::require(is_array($family['tables'] ?? null) && count($family['tables']) > 0, 'EMPTY_FAMILY');
            foreach ($family['tables'] as $t) {
                self::require(is_array($t), 'TABLE_RECORD');
                $name = $t['name'] ?? null;
                self::require(is_string($name) && preg_match('/^[a-z][a-z0-9_]+$/D', $name) === 1 && !isset($tables[$name]), 'TABLE_NAME_DUPLICATE');
                $tables[$name] = true;
                self::require(($t['table_state'] ?? null) === 'CANDIDATE_ONLY', 'TABLE_LIVE');
                self::require(($t['new_raw_source_bytes'] ?? null) === false, 'DUPLICATED_M05_BYTES');
                self::require(is_array($t['foreign_key_intents'] ?? null) && count($t['foreign_key_intents']) >= 2, 'MISSING_FK_INTENT');
                self::require(is_array($t['indexes'] ?? null) && count($t['indexes']) > 0, 'MISSING_INDEX_INTENT');
                self::require(($t['design_status'] ?? null) === 'COLUMN_LEVEL_CANDIDATE_UNRATIFIED_AND_NOT_APPLIED', 'PREMATURE_COLUMN_FINALITY');
                $proposedColumns = $t['column_proposals'] ?? null;
                self::require(is_array($proposedColumns) && isset($proposedColumns[$t['proposed_identity_column'] ?? '']), 'MISSING_PHYSICAL_COLUMN_DESIGN');
                foreach (['tenant_id','workspace_id','known_at','lineage_digest'] as $requiredColumn) {
                    self::require(isset($proposedColumns[$requiredColumn]), 'MISSING_SCOPE_LINEAGE_COLUMN');
                }
                foreach ($t['foreign_key_intents'] as $fk) {
                    self::require(($fk['state'] ?? null) === 'NOT_APPLIED', 'FK_IMPLICITLY_LIVE');
                    $fkName = $fk['constraint_name'] ?? null;
                    self::require(is_string($fkName) && preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $fkName) === 1 && !isset($foreignKeyNames[$fkName]), 'FK_CONSTRAINT_NAME_COLLISION');
                    $foreignKeyNames[$fkName] = true;
                    self::require(is_array($fk['columns'] ?? null) && count($fk['columns']) > 0, 'FK_COLUMNS_UNDESIGNED');
                    self::require(($fk['columns'] ?? null) === ($fk['target_columns'] ?? null), 'FK_COLUMN_TARGET_IDENTITY');
                    foreach ($fk['columns'] as $column) {
                        self::require(is_string($column) && isset($proposedColumns[$column]), 'FK_COLUMN_NOT_PROPOSED');
                    }
                }
                foreach ($t['indexes'] as $index) {
                    self::require(($index['state'] ?? null) === 'NOT_APPLIED', 'INDEX_IMPLICITLY_LIVE');
                    $indexName = $index['name'] ?? null;
                    self::require(is_string($indexName) && preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $indexName) === 1 && !isset($indexNames[$indexName]), 'INDEX_NAME_COLLISION');
                    $indexNames[$indexName] = true;
                    foreach ($index['candidate_columns'] ?? [] as $column) {
                        self::require(is_string($column) && isset($proposedColumns[$column]), 'INDEX_COLUMN_NOT_PROPOSED');
                    }
                }
            }
        }
        self::require(count($families) === count(self::FAMILIES), 'FAMILY_COUNT');
        self::require(count($tables) === 31, 'PROPOSED_TABLE_COUNT');
    }

    private static function require(bool $condition, string $reason): void
    {
        if (!$condition) {
            throw new \DomainException('BF00_' . $reason);
        }
    }
}
