<?php

declare(strict_types=1);

namespace Allfather\Loopia\Tests;

use Allfather\Loopia\Auth\PasswordPolicy;
use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M03\Registry;
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
use Allfather\Loopia\Substrate\M05\Readiness as ReadinessEvaluator;
use Allfather\Loopia\Substrate\M07\Council;
use Allfather\Loopia\Substrate\M08\Arbiter;
use Allfather\Loopia\Substrate\M08\PolicySets;
use Allfather\Loopia\Substrate\M10\DecisionLedger;

/**
 * Minimal authority-chain seeding for tests.
 *
 * Deliberately NOT a fixture factory that hides governance: every seed here creates only rows a
 * real operator act would create, so a test cannot accidentally prove behavior that depends on a
 * state the product could never reach.
 */
final class Seed
{
    /** @return array{tenant_id: string, workspace_id: string, operator_id: string} */
    public static function tenantWorkspace(Database $db, Clock $clock, string $key = 'acme'): array
    {
        $now = $clock->now();
        $tenantId = Ids::new();
        $workspaceId = Ids::new();
        $operatorId = Ids::new();

        $db->insert('tenants', [
            'tenant_id' => $tenantId,
            'tenant_key' => $key . '-' . substr($tenantId, 0, 8),
            'schema_version' => '1',
            'display_name' => ucfirst($key),
            'tenant_type' => 'TEST',
            'status' => 'ACTIVE',
            'created_at' => $now,
        ]);

        $db->insert('operators', [
            'operator_id' => $operatorId,
            'tenant_id' => $tenantId,
            'schema_version' => '1',
            'display_name' => 'Operator ' . $key,
            'status' => 'ACTIVE',
            'created_at' => $now,
        ]);

        $db->insert('workspaces', [
            'workspace_id' => $workspaceId,
            'tenant_id' => $tenantId,
            'workspace_key' => 'ws-' . substr($workspaceId, 0, 8),
            'schema_version' => '1',
            'display_name' => 'Workspace',
            'workspace_type' => 'TEST',
            'environment_class' => 'TEST',
            'external_side_effects_allowed' => 0,
            'active_v1_capability_ceiling' => 'RELEASE_INTELLIGENCE',
            'status' => 'ACTIVE',
            'created_at' => $now,
        ]);

        return ['tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'operator_id' => $operatorId];
    }

    /** An additional operator inside an existing tenant. */
    public static function operator(Database $db, Clock $clock, string $tenantId, string $displayName): string
    {
        $operatorId = Ids::new();
        $db->insert('operators', [
            'operator_id' => $operatorId,
            'tenant_id' => $tenantId,
            'schema_version' => '1',
            'display_name' => $displayName,
            'status' => 'ACTIVE',
            'created_at' => $clock->now(),
        ]);

        return $operatorId;
    }

    /** @return array{role_id: string} */
    public static function role(Database $db, Clock $clock, string $roleKey, array $permissions = []): array
    {
        $now = $clock->now();
        $roleId = Ids::new();
        $db->insert('roles', [
            'role_id' => $roleId,
            'role_key' => $roleKey,
            'role_version' => 1,
            'schema_version' => '1',
            'display_name' => $roleKey,
            'status' => 'ACTIVE',
            'created_at' => $now,
        ]);
        foreach ($permissions as $permissionKey) {
            $exists = $db->one('SELECT permission_key FROM permissions WHERE permission_key = ?', [$permissionKey]);
            if ($exists === null) {
                $db->insert('permissions', [
                    'permission_key' => $permissionKey,
                    'schema_version' => '1',
                    'display_name' => $permissionKey,
                    'created_at' => $now,
                ]);
            }
            $db->insert('role_permissions', ['role_id' => $roleId, 'permission_key' => $permissionKey]);
        }

        return ['role_id' => $roleId];
    }

    public static function membership(
        Database $db,
        Clock $clock,
        string $tenantId,
        string $workspaceId,
        string $operatorId,
        string $roleId,
    ): string {
        $now = $clock->now();
        $membershipId = Ids::new();
        $db->insert('workspace_memberships', [
            'membership_id' => $membershipId,
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'principal_type' => 'OPERATOR',
            'principal_id' => $operatorId,
            'role_id' => $roleId,
            'role_version' => 1,
            'status' => 'ACTIVE',
            'valid_from' => $now,
            'created_at' => $now,
        ]);

        return $membershipId;
    }

    /** @return array{platform_user_id: string, email: string, password: string} */
    public static function platformUser(
        Database $db,
        Clock $clock,
        string $tenantId,
        string $operatorId,
        string $email = 'operator@example.test',
        string $password = 'correct horse battery staple',
    ): array {
        $now = $clock->now();
        $hashed = PasswordPolicy::hash($password);
        $platformUserId = Ids::new();
        $db->insert('platform_users', [
            'platform_user_id' => $platformUserId,
            'schema_version' => '1',
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'email' => $email,
            'display_name' => 'Test Operator',
            'password_hash' => $hashed['hash'],
            'password_algo' => $hashed['algo'],
            'status' => 'ACTIVE',
            'password_changed_at' => $now,
            'created_at' => $now,
        ]);

        return ['platform_user_id' => $platformUserId, 'email' => $email, 'password' => $password];
    }

    /**
     * The full P01 evidence chain behind one company signal:
     * competitor -> source reference + licence card -> monitored source -> two snapshots -> diff -> signal.
     *
     * Built the way the product builds it, not shortcut: a diff REQUIRES two snapshots, a snapshot
     * REQUIRES an evidence object, and an evidence object requires an admitted source. Seeding a
     * signal without that chain would let a test prove behavior over a state the product can never
     * actually reach.
     *
     * `$overrides` bends one link at a time so a single refusal path can be isolated.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, string>
     */
    public static function p01SignalChain(
        Database $db,
        Clock $clock,
        array $scope,
        string $missionId,
        ?string $proposalId,
        array $overrides = [],
    ): array {
        $now = $clock->now();
        $m05 = new EvidenceSpine($db, $clock);

        $competitorId = Ids::new();
        $db->insert('competitors', [
            'competitor_id' => $competitorId,
            'schema_version' => '1',
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'display_name' => 'Competitor A',
            'competitor_class' => $overrides['competitor_class'] ?? 'DIRECT',
            'status' => 'ACTIVE',
            'created_at' => $now,
        ]);

        $sourceRefId = $m05->registerSource(
            $scope['tenant_id'], $scope['workspace_id'], 'WEB_RESOURCE',
            'https://competitor.example/pricing', 'OBSERVE_AND_DERIVE',
            'PUBLIC_OR_NON_SENSITIVE', 'ACTIVE_FOR_CAPTURE'
        );

        $cardId = Ids::new();
        $db->insert('source_license_cards', [
            'source_license_card_id' => $cardId,
            'schema_version' => '1',
            'tenant_id' => $scope['tenant_id'],
            'source_ref_id' => $sourceRefId,
            'source_name' => 'Competitor pricing page',
            'source_url_safe' => 'https://competitor.example/pricing',
            'source_type' => 'website',
            'allowed_use_class' => 'customer_visible_allowed',
            'license_class' => $overrides['license_class'] ?? 'PUBLIC_WEB_ALLOWED',
            'customer_output_allowed' => $overrides['customer_output_allowed'] ?? 1,
            'derived_summary_allowed' => 1,
            'robots_policy_checked' => 1,
            'terms_policy_checked' => 1,
            'review_status' => $overrides['review_status'] ?? 'approved',
            'approved_at' => $now,
            'review_expires_at' => $overrides['review_expires_at'] ?? null,
            'created_at' => $now,
        ]);

        $monitoredSourceId = Ids::new();
        $db->insert('monitored_sources', [
            'monitored_source_id' => $monitoredSourceId,
            'schema_version' => '1',
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'competitor_id' => $competitorId,
            'source_ref_id' => $sourceRefId,
            'source_license_card_id' => $cardId,
            'source_kind' => 'WEBSITE',
            'status' => 'ACTIVE',
            'created_at' => $now,
        ]);

        // Two snapshots, each backed by its own evidence object — the N-1 / N pair a diff is over.
        $snapshotIds = [];
        foreach ([['449 SEK', 1], ['499 SEK', 2]] as [$body, $sequence]) {
            $contentHash = Digest::contentDigest($body . $monitoredSourceId);
            $evidenceId = $m05->capture([
                'tenant_id' => $scope['tenant_id'],
                'workspace_id' => $scope['workspace_id'],
                'mission_id' => $missionId,
                'source_ref_id' => $sourceRefId,
                'evidence_type' => 'SOURCE_CAPTURE',
                'captured_content_reference_safe' => 'evidence://sha256/' . substr($contentHash, 0, 12),
                'content_hash' => $contentHash,
                'capture_method' => 'GOVERNED_HTTPS_GET',
                'sensitivity_class' => 'PUBLIC_OR_NON_SENSITIVE',
            ])->value()['evidence_id'];

            $snapshotId = Ids::new();
            $db->insert('source_snapshots', [
                'source_snapshot_id' => $snapshotId,
                'schema_version' => '1',
                'tenant_id' => $scope['tenant_id'],
                'workspace_id' => $scope['workspace_id'],
                'monitored_source_id' => $monitoredSourceId,
                'evidence_id' => $evidenceId,
                'snapshot_sequence' => $sequence,
                'content_hash' => $contentHash,
                'hash_algorithm' => 'sha256',
                'capture_method' => 'FIXTURE',
                'payload_class' => 'html_snapshot',
                'captured_at' => $now,
            ]);
            $snapshotIds[] = $snapshotId;
        }

        $diffId = Ids::new();
        $db->insert('source_diffs', [
            'source_diff_id' => $diffId,
            'schema_version' => '1',
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'monitored_source_id' => $monitoredSourceId,
            'before_snapshot_id' => $snapshotIds[0],
            'after_snapshot_id' => $snapshotIds[1],
            'diff_digest' => Digest::canonicalDigest([
                'before' => $snapshotIds[0],
                'after' => $snapshotIds[1],
            ]),
            'hash_algorithm' => 'sha256',
            'change_significance' => $overrides['change_significance'] ?? 'MATERIAL',
            'diff_summary_safe' => 'List price 449 -> 499 SEK.',
            'detected_at' => $now,
        ]);

        $signalId = Ids::new();
        $db->insert('company_signals', [
            'company_signal_id' => $signalId,
            'schema_version' => '1',
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'competitor_id' => $competitorId,
            'source_diff_id' => $diffId,
            'signal_class' => 'PRICING_CHANGE_SIGNAL',
            // FACT_OBSERVED: the price on the page changed, and that IS what was observed. The
            // interpretation of WHY is a separate, HYPOTHESIS-classed claim.
            'interpretation_kind' => $overrides['interpretation_kind'] ?? 'FACT_OBSERVED',
            'confidence_band' => 'HIGH',
            'summary_safe' => 'List price rose from 449 to 499 SEK.',
            'proposal_id' => $proposalId,
            'status' => 'PROPOSED',
            'created_at' => $now,
        ]);

        return [
            'competitor_id' => $competitorId,
            'source_ref_id' => $sourceRefId,
            'source_license_card_id' => $cardId,
            'monitored_source_id' => $monitoredSourceId,
            'before_snapshot_id' => $snapshotIds[0],
            'after_snapshot_id' => $snapshotIds[1],
            'source_diff_id' => $diffId,
            'company_signal_id' => $signalId,
        ];
    }

    /**
     * The WHOLE governed chain, run through the real modules rather than inserted:
     *
     *   valkyrie + contract -> mission -> proposal -> evidence -> bundle (frozen)
     *     -> council session + assessment + frozen result
     *     -> arbiter verdict
     *     -> M10 INTERNAL_INFORMATIONAL_RELEASE record
     *
     * Every product test needs this, and every step of it is a gate. Inserting the end state
     * directly would let a test prove behavior over a state the product cannot reach — and the
     * foreign keys would reject most of it anyway, which is the schema saying the same thing.
     *
     * `$signalOverrides` bends one link of the P01 evidence chain so a single refusal path can be
     * isolated; `$councilRecommendation` bends the review outcome.
     *
     * @param array<string, mixed> $signalOverrides
     * @return array<string, mixed>
     */
    public static function governedReleaseChain(
        Database $db,
        Clock $clock,
        array $scope,
        array $signalOverrides = [],
        string $councilRecommendation = 'PROCEED_TO_ARBITER',
        string $proposalType = 'BATTLECARD_BRIEF',
    ): array {
        $m03 = new Registry($db, $clock);
        $m04 = new Proposals($db, $clock);
        $m05 = new EvidenceSpine($db, $clock);
        $m07 = new Council($db, $clock);
        $m08 = new Arbiter($db, $clock, new ReadinessEvaluator($db, $clock));
        $m10 = new DecisionLedger($db, $clock);
        $policies = new PolicySets($db, $clock);

        $policySetId = $policies->seed(PolicySets::P01_COMPETITION_RELEVANCE, $scope['tenant_id'], $scope['workspace_id'])
            ->value()['arbiter_policy_set_id'];

        $producer = $m03->registerValkyrie(
            $scope['tenant_id'], $scope['workspace_id'], 'Competition Monitor',
            'COMPETITION_MONITOR_VALKYRIE', 'Monitors approved competitor sources.',
            $scope['operator_id'], 'ACTIVE'
        );
        $critic = $m03->registerValkyrie(
            $scope['tenant_id'], $scope['workspace_id'], 'Review Critic',
            'REVIEW_CRITIC_VALKYRIE', 'Independent review.', $scope['operator_id'], 'ACTIVE'
        );
        $contractId = $m03->issueContract(
            $producer, $scope['tenant_id'], $scope['workspace_id'], 'p01.monitor',
            'COMPETITION_MONITOR_VALKYRIE', ['OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE']
        )->value()['capability_contract_id'];

        $missionId = $m04->createMission(
            $scope['tenant_id'], $scope['workspace_id'], 'COMPETITION_MONITORING',
            'Monitor competitor pricing', 'Detect and brief material pricing changes.',
            ['OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE'], 'OPERATOR', $scope['operator_id']
        );

        $proposalId = $m04->createDraft([
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'mission_id' => $missionId,
            'producing_valkyrie_id' => $producer,
            'capability_contract_id' => $contractId,
            'proposal_type' => $proposalType,
            'requested_action_class' => 'RELEASE_INTELLIGENCE',
            'title' => 'Competitor A raised list pricing',
            'safe_purpose_summary' => 'Brief the customer on an observed pricing change.',
            'proposed_content_or_action_summary' => 'List price moved 449 -> 499 SEK.',
            'uncertainty_summary' => 'Applicability to existing customers is NOT observed.',
        ])->value()['proposal_id'];

        $chain = self::p01SignalChain($db, $clock, $scope, $missionId, $proposalId, $signalOverrides);

        // The evidence bundle: the frozen basis the verdict is decided on.
        $evidenceId = (string) $db->scalar(
            'SELECT e.evidence_id FROM evidence_objects e
             JOIN source_snapshots s ON s.evidence_id = e.evidence_id
             WHERE s.source_snapshot_id = ?',
            [$chain['after_snapshot_id']]
        );
        $bundleId = $m05->createBundleDraft(
            $scope['tenant_id'], $scope['workspace_id'], $missionId, $proposalId, 'P01 signal basis'
        );
        $m05->addBundleItem($bundleId, $evidenceId);
        $m05->submitBundle($bundleId);

        $m04->submit($proposalId);

        $sessionId = $m07->openSession(
            $scope['tenant_id'], $scope['workspace_id'], $missionId, $proposalId,
            'MULTI_REVIEWER_DEMO', 'COMPETITION_RELEVANCE_REVIEW', 'OPERATOR', $scope['operator_id']
        )->value()['review_session_id'];
        $assignmentId = $m07->assignParticipant($sessionId, $critic, 'EVIDENCE_CRITIC', 'INDEPENDENT_DECLARED_REVIEWER')
            ->value()['participant_assignment_id'];
        $m07->recordAssessment(
            $sessionId, $assignmentId, $critic,
            $councilRecommendation === 'PROCEED_TO_ARBITER' ? 'SUPPORT_PROCEED_TO_ARBITER' : 'HOLD',
            'Reviewed.'
        );
        $m07->freezeResult($sessionId, $councilRecommendation, 'Council result.');

        $verdict = $m08->evaluateProposal($proposalId, $policySetId);

        $release = null;
        if ($verdict->isOk() && $verdict->value()['verdict'] === 'ALLOW_INFORMATIONAL_RELEASE') {
            $streamId = $m10->ensureActiveStream($scope['tenant_id'], $scope['workspace_id'], 'TEST');
            $release = $m10->appendInternalRelease($streamId, 'Released competition briefing.', [
                [
                    'owner_module_id' => 'M08', 'input_object_type' => 'ARBITER_VERDICT',
                    'input_object_id' => $verdict->value()['arbiter_verdict_id'],
                    'input_object_version_or_digest' => $verdict->value()['basis_hash'],
                    'reference_role' => 'VERDICT_BASIS', 'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
                ],
                [
                    'owner_module_id' => 'M04', 'input_object_type' => 'PROPOSAL',
                    'input_object_id' => $proposalId,
                    'reference_role' => 'PROPOSAL_BASIS', 'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
                ],
                [
                    'owner_module_id' => 'M05', 'input_object_type' => 'EVIDENCE_BUNDLE',
                    'input_object_id' => $bundleId,
                    'reference_role' => 'EVIDENCE_BASIS', 'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
                ],
            ], ['proposal_id' => $proposalId])->value();
        }

        return [
            ...$chain,
            'arbiter_policy_set_id' => $policySetId,
            'producing_valkyrie_id' => $producer,
            'critic_valkyrie_id' => $critic,
            'capability_contract_id' => $contractId,
            'mission_id' => $missionId,
            'proposal_id' => $proposalId,
            'evidence_id' => $evidenceId,
            'evidence_bundle_id' => $bundleId,
            'review_session_id' => $sessionId,
            'verdict' => $verdict->isOk() ? $verdict->value() : null,
            'verdict_refusal' => $verdict->isOk() ? null : $verdict->reasonCode(),
            'release' => $release,
        ];
    }
}
