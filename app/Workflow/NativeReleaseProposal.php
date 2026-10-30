<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Product\P01\HistoricalFindingAdapter;
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * AN-03: a source-bound, multi-evidence candidate for the actual release owners.
 * A candidate is neither a CompanySignal nor an M04/M10/M16 permission.
 */
final class NativeReleaseProposal
{
    public const VERSION = 'an03.native-multi-evidence-release-proposal.v1';
    private const STATE = 'CANDIDATE_U03_U06_U07_NOT_ADOPTED';

    public static function fromFinding(array $finding): Outcome
    {
        if (!HistoricalFindingAdapter::verify($finding)->isOk()) {
            return Outcome::refuse('AN03_OWNER_PROPOSAL_FINDING_INVALID');
        }
        $proposal = [
            'version' => self::VERSION,
            'status' => self::STATE,
            'scope' => $finding['scope'],
            'finding_digest' => $finding['finding_digest'],
            'basis_digest' => $finding['basis_digest'],
            'review_run_digest' => $finding['review_run_digest'],
            'analyst_candidate_digest' => $finding['analyst_candidate_digest'],
            'critic_candidate_digest' => $finding['critic_candidate_digest'],
            'review_chain_event_digests' => $finding['review_chain_event_digests'],
            'known_at_cutoff_utc' => $finding['known_at_cutoff_utc'],
            'dependency_graph_digest' => $finding['dependency_graph']['graph_digest'],
            'primary_references' => $finding['primary_references'],
            'claim' => [
                'statement_code' => $finding['statement_code'],
                'support_ref_ids' => $finding['claim_support_ref_ids'],
                'contrary_ref_ids' => $finding['contrary_ref_ids'],
                'materiality' => 'NOT_ASSESSED',
                'empirically_confirmed' => false,
            ],
            'required_owner_stages' => HistoryReviewRelease::OWNER_STAGES,
            'historical_use_decision' => 'UNKNOWN_U03',
            'model_disclosure_decision' => 'UNKNOWN_U06',
            'multi_evidence_owner_contract' => 'CONTRACT_BLOCKED_U07',
            'current_m10_reliance' => 'UNKNOWN',
            'proposed_audience' => 'TENANT_INTERNAL_ONLY',
            'p04_recipient_selection' => 'NOT_PROPOSED',
            'human_factual_decision_digest' => null,
            'human_delivery_decision_digest' => null,
            'native_m10_record_id' => null,
            'released' => false,
            'customer_visible' => false,
            // Preserve the original, hashed point-in-time candidate for verification.
            'historical_finding' => $finding,
        ];
        $proposal['proposal_digest'] = Digest::canonicalDigest($proposal);
        return Outcome::ok($proposal);
    }

    /** Exact reconstruction blocks caller-supplied claim, citation and grant changes. */
    public static function verify(array $proposal): Outcome
    {
        if (!is_array($proposal['historical_finding'] ?? null)
            || !Vocabulary::scope($proposal['scope'] ?? null)
            || !is_string($proposal['proposal_digest'] ?? null)) {
            return Outcome::refuse('AN03_OWNER_PROPOSAL_INVALID');
        }
        $expected = self::fromFinding($proposal['historical_finding']);
        if (!$expected->isOk() || $expected->value() !== $proposal) {
            return Outcome::refuse('AN03_OWNER_PROPOSAL_REHASH_OR_AUTHORITY_FORGERY');
        }
        return Outcome::ok([
            'status' => 'VERIFIED_CANDIDATE_ONLY',
            'source_rights_authenticated' => false,
            'human_decisions_authenticated' => false,
            'native_release_authorized' => false,
        ]);
    }
}
