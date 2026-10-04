<?php

declare(strict_types=1);

namespace Allfather\Loopia\Tests;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Product\P01\SourceCards;
use Allfather\Loopia\Product\P02\EntityTwins;
use Allfather\Loopia\Product\P02\TwinAdmission;
use Allfather\Loopia\Product\P02\TwinRelease;
use Allfather\Loopia\Product\P02\TwinStateChange;
use Allfather\Loopia\Product\P03\Catalogue;
use Allfather\Loopia\Product\P03\FitHypotheses;
use Allfather\Loopia\Product\P03\Icp;
use Allfather\Loopia\Product\P03\ProspectQueue;
use Allfather\Loopia\Product\P03\Release as P03Release;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
use Allfather\Loopia\Substrate\M05\Readiness;
use Allfather\Loopia\Substrate\M07\Council;
use Allfather\Loopia\Substrate\M08\Arbiter;
use Allfather\Loopia\Substrate\M08\PolicySets;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M16\P02Adapter;
use Allfather\Loopia\Substrate\M16\ReleaseOutput;

/**
 * One released P03 priority-queue item and one released P02 entity snapshot, in one fresh workspace,
 * reached through the real governed lifecycle — the same hops `tests/acceptance/golden_flows.php`
 * walks — so a detail-read test reads objects the product can actually produce. The reader holds
 * the three read permissions and a tier granting them; nothing else is granted.
 */
final class ProductReleaseFixture
{
    /** @return array{scope: array<string, string>, reader: string, snapshot_id: string, output_id: string, signal_id: string, decision_record_id: string} */
    public static function create(Database $db, Clock $clock, string $key): array
    {
        $scope = Seed::tenantWorkspace($db, $clock, $key);
        $ledger = new DecisionLedger($db, $clock);
        $proposals = new Proposals($db, $clock);
        $evidence = new EvidenceSpine($db, $clock);
        $readiness = new Readiness($db, $clock);
        $council = new Council($db, $clock);
        $arbiter = new Arbiter($db, $clock, $readiness);
        $access = new AccessGuard($db, $clock);
        $entitlements = new Entitlements($db, $clock);
        $policySets = new PolicySets($db, $clock);

        $permissions = ['priority_outreach_queue.read', 'entity_twin.read'];
        $reader = Seed::operator($db, $clock, $scope['tenant_id'], 'Detail reader ' . $key);
        $role = Seed::role($db, $clock, 'detail-' . substr(Ids::new(), 0, 8), $permissions);
        Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $reader, $role['role_id']);
        $tierId = self::ok($entitlements->defineTier('detail_' . substr(Ids::new(), 0, 8), 'Detail reads', 100, $permissions))['subscription_tier_id'];
        $entitlements->subscribe($scope['tenant_id'], $tierId);

        // ---- P03: the tenant's catalogue and a released signal become a released queue item.
        $card = (new SourceCards($db, $clock))->project([
            'tenant_id' => $scope['tenant_id'], 'source_name' => 'Our product list', 'source_url_safe' => 'https://tenant.example/products',
            'source_type' => 'manual', 'allowed_use_class' => 'derived_summary_allowed', 'license_class' => 'OPEN_CC0',
            'review_status' => 'approved', 'data_ownership' => 'TENANT_OWNED',
        ]);
        $catalogue = new Catalogue($db, $clock);
        $catalogueId = self::ok($catalogue->registerCatalogue($scope['tenant_id'], $scope['workspace_id'], 'main', 'Main', $card['source_ref_id'], $card['source_license_card_id']))['customer_product_catalogue_id'];
        $productId = self::ok($catalogue->addProduct($catalogueId, 'pricing-suite', 'Pricing Suite', 'PRODUCT', ['pricing', 'renewal', 'billing', 'contract']))['customer_product_id'];
        $chain = Seed::governedReleaseChain($db, $clock, $scope, [], 'PROCEED_TO_ARBITER', 'COMPANY_SIGNAL_BRIEF');
        $signalId = Ids::new();
        $db->insert('company_signals', [
            'company_signal_id' => $signalId, 'schema_version' => '1', 'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
            'competitor_id' => $chain['competitor_id'], 'source_diff_id' => $chain['source_diff_id'], 'signal_class' => 'PRICING_CHANGE_SIGNAL',
            'interpretation_kind' => 'FACT_OBSERVED', 'confidence_band' => 'HIGH',
            // Source-derived text is untrusted all the way to the browser: it must arrive as text.
            'summary_safe' => 'Competitor raised renewal pricing and changed contract billing terms. <script>alert(1)</script>',
            'proposal_id' => $chain['proposal_id'], 'status' => 'RELEASED', 'created_at' => $clock->now(),
        ]);
        $hypothesisId = self::ok((new FitHypotheses($db, $clock, $evidence, new Icp($db, $clock)))->pair([
            'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'customer_product_id' => $productId, 'company_signal_id' => $signalId,
        ]))['fit_hypothesis_id'];
        $governance = ['mission_id' => $chain['mission_id'], 'producing_valkyrie_id' => $chain['producing_valkyrie_id'], 'capability_contract_id' => $chain['capability_contract_id']];
        $queue = new ProspectQueue($db, $clock, $proposals, $evidence, $readiness, $arbiter);
        $queue->submitForAdmission(['tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'fit_hypothesis_id' => $hypothesisId, 'admission_path' => 'PRIORITY_OUTREACH_QUEUE_ADMISSION', 'governance' => $governance]);
        $queueItemId = (string) self::ok($queue->admit([
            'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'fit_hypothesis_id' => $hypothesisId,
            'arbiter_policy_set_id' => self::ok($policySets->seed(PolicySets::P03_PROSPECT_ADMISSION, $scope['tenant_id'], $scope['workspace_id']))['arbiter_policy_set_id'],
        ]))['priority_outreach_queue_item_id'];
        $released = self::ok((new P03Release($db, $clock, $ledger, $access, $entitlements))->releaseProspectOutput([
            'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'output_kind' => 'PRIORITY_OUTREACH_QUEUE_ITEM', 'output_id' => $queueItemId,
        ]));

        // ---- P02: a curated twin version becomes a released snapshot.
        $twins = new EntityTwins($db, $clock);
        $stateChange = new TwinStateChange($db, $clock, $proposals, $evidence, $readiness, $council);
        $twinId = self::ok($twins->registerTwin($scope['tenant_id'], $scope['workspace_id'], 'COMPETITOR', 'Rival <b>AB</b>', [], $scope['operator_id']))['entity_twin_id'];
        $versionId = self::ok($twins->appendVersion([
            'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'entity_twin_id' => $twinId, 'claim_ceiling' => 'INTERNAL_REVIEWED',
            'fields' => [
                ['field_key' => 'industry', 'field_value_safe' => 'Retail software', 'value_status' => 'PUBLIC_SOURCE'],
                ['field_key' => 'headcount', 'field_value_safe' => null, 'value_status' => 'UNKNOWN'],
            ],
            'uncertainty' => ['summary_safe' => 'Headcount is unknown; segment is inferred from the public site.'],
            'source_links' => [['source_ref_id' => $card['source_ref_id'], 'source_basis' => 'PUBLIC_SOURCE', 'allowed_use' => 'DERIVED_SUMMARY_ALLOWED']],
        ]))['entity_twin_version_id'];
        $changeId = self::ok($stateChange->create([
            'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'entity_twin_version_id' => $versionId,
            'requested_visibility_scope' => 'BROAD_INTERNAL_BI', 'requested_release_status' => 'INTERNAL_REVIEWED',
        ]))['entity_twin_state_change_proposal_id'];
        $stateChange->submit(['tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'entity_twin_state_change_proposal_id' => $changeId, 'governance' => $governance]);
        $sessionId = self::ok($stateChange->openReview([
            'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'entity_twin_state_change_proposal_id' => $changeId,
            'initiated_by_actor_type' => 'OPERATOR', 'initiated_by_actor_id' => $scope['operator_id'],
        ]))['review_session_id'];
        $assignmentId = self::ok($council->assignParticipant($sessionId, $chain['critic_valkyrie_id'], 'EVIDENCE_CRITIC', 'INDEPENDENT_DECLARED_REVIEWER'))['participant_assignment_id'];
        $council->recordAssessment($sessionId, $assignmentId, $chain['critic_valkyrie_id'], 'SUPPORT_PROCEED_TO_ARBITER', 'Reviewed.');
        $council->freezeResult($sessionId, 'PROCEED_TO_ARBITER', 'Council result.');
        self::ok((new TwinAdmission($db, $clock, $arbiter, $stateChange))->admit([
            'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'entity_twin_state_change_proposal_id' => $changeId,
            'arbiter_policy_set_id' => self::ok($policySets->seed(PolicySets::P02_TWIN_STATE_ADMISSION, $scope['tenant_id'], $scope['workspace_id']))['arbiter_policy_set_id'],
        ]));
        $twinRelease = new TwinRelease($db, $clock, $ledger, $twins, $access, $entitlements, new P02Adapter($db, new ReleaseOutput($db, $clock, $ledger)));
        $snapshot = self::ok($twinRelease->release(['tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'entity_twin_state_change_proposal_id' => $changeId]));

        return [
            'scope' => $scope, 'reader' => $reader,
            'snapshot_id' => (string) $snapshot['released_entity_twin_snapshot_id'],
            'output_id' => (string) $released['released_prospect_output_id'],
            'signal_id' => $signalId,
            'decision_record_id' => (string) $released['decision_record_id'],
        ];
    }

    private static function ok(\Allfather\Loopia\Core\Outcome $out): mixed
    {
        if (!$out->isOk()) {
            throw new \RuntimeException('product release fixture refused: ' . $out->reasonCode());
        }

        return $out->value();
    }
}
