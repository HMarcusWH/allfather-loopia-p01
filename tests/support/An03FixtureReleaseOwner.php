<?php

declare(strict_types=1);

namespace Allfather\Loopia\Tests;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Workflow\{HistoryReviewRelease,HistoricalFindingReleaseOwnerPort};

/** CLI-only, no-database/no-provider deterministic owner substitute. */
final class An03FixtureReleaseOwner implements HistoricalFindingReleaseOwnerPort
{
    public array $stageCalls = [];

    public function __construct(
        private readonly ?string $refuseAt = null,
        private readonly ?string $alterAt = null,
        private readonly ?string $alterField = null,
    ) {}

    public function recheckCurrentSourceUse(array $p, ?array $prior): Outcome { return $this->stage('M05_SOURCE_USE', $p, $prior); }
    public function readFactualHumanDecision(array $p, ?array $prior): Outcome { return $this->stage('HUMAN_FACTUAL', $p, $prior); }
    public function submitM04MultiEvidenceProposal(array $p, ?array $prior): Outcome { return $this->stage('M04_PROPOSAL', $p, $prior); }
    public function recordM07IndependentReview(array $p, ?array $prior): Outcome { return $this->stage('M07_REVIEW', $p, $prior); }
    public function evaluateM08Admissibility(array $p, ?array $prior): Outcome { return $this->stage('M08_ADMISSIBILITY', $p, $prior); }
    public function prepareM10CurrentRelianceRecord(array $p, ?array $prior): Outcome { return $this->stage('M10_CANDIDATE', $p, $prior); }
    public function readDeliveryHumanDecision(array $p, ?array $prior): Outcome { return $this->stage('HUMAN_DELIVERY', $p, $prior); }
    public function prepareM16InternalRepresentation(array $p, ?array $prior): Outcome { return $this->stage('M16_CANDIDATE', $p, $prior); }

    private function stage(string $name, array $proposal, ?array $previous): Outcome
    {
        $this->stageCalls[] = $name;
        if ($this->refuseAt === $name) {
            return Outcome::refuse('AN03_FIXTURE_OWNER_' . $name . '_HOLD');
        }
        $human = in_array($name, ['HUMAN_FACTUAL', 'HUMAN_DELIVERY'], true);
        $purpose = $name === 'HUMAN_FACTUAL' ? 'FACTUAL_REVIEW'
            : ($name === 'HUMAN_DELIVERY' ? 'DELIVERY_REVIEW' : null);
        $actor = $human ? 'fixture_human_' . strtolower((string) $purpose) : null;
        $at = $human ? ($purpose === 'FACTUAL_REVIEW' ? '2026-10-29T12:04:00Z' : '2026-10-29T12:05:00Z') : null;
        $humanDecision = $human ? [
            'actor_type' => 'HUMAN_FIXTURE', 'actor_id' => $actor,
            'purpose' => $purpose, 'decision' => 'APPROVE_SYNTHETIC_ONLY',
            'finding_digest' => $proposal['finding_digest'],
            'basis_digest' => $proposal['basis_digest'],
            'proposal_digest' => $proposal['proposal_digest'],
            'scope' => $proposal['scope'], 'decided_at_utc' => $at,
        ] : null;
        if ($humanDecision !== null) {
            $humanDecision['decision_digest'] = Digest::canonicalDigest($humanDecision);
        }
        $receipt = [
            'version' => HistoryReviewRelease::FIXTURE_STAGE_VERSION,
            'stage' => $name,
            'proposal_digest' => $proposal['proposal_digest'],
            'basis_digest' => $proposal['basis_digest'],
            'scope' => $proposal['scope'],
            'source_ref_ids' => array_column($proposal['primary_references'], 'ref_id'),
            'prior_receipt_digest' => $previous['receipt_digest'] ?? null,
            'result' => 'SYNTHETIC_STAGE_ACCEPTED',
            'human_decision' => $humanDecision,
            'fixture_only' => true,
            'actual_owner_authorization' => false,
            'actual_m10_record_written' => false,
        ];
        if ($this->alterAt === $name && $this->alterField !== null) {
            if ($this->alterField === 'human_actor' && $human) {
                $receipt['human_decision']['actor_type'] = 'MODEL_CRITIC';
            } elseif ($this->alterField === 'human_purpose' && $human) {
                $receipt['human_decision']['purpose'] = 'UNRELATED';
            } elseif ($this->alterField === 'drop_contrary') {
                array_pop($receipt['source_ref_ids']);
            } elseif ($this->alterField === 'forge_m10') {
                $receipt['actual_m10_record_written'] = true;
            } elseif ($this->alterField === 'wrong_scope') {
                $receipt['scope']['workspace_id'] = 'foreign-workspace';
            } elseif ($this->alterField === 'wrong_prior') {
                $receipt['prior_receipt_digest'] = str_repeat('f', 64);
            } elseif ($this->alterField === 'wrong_basis') {
                $receipt['basis_digest'] = str_repeat('c', 64);
            }
        }
        // Rehashing an invalid stage does not make it owner-authenticated.
        $receipt['receipt_digest'] = Digest::canonicalDigest($receipt);
        return Outcome::ok($receipt);
    }
}
