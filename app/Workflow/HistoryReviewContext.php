<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\Outcome;

/**
 * Detached history-basis inspection. ModelContextAuthority methods deliberately
 * refuse until U03 historical-use and U06 disclosure purposes are adopted.
 * No existing ContextEnvelope source-rights or request gates are relaxed.
 */
final class HistoryReviewContext implements ModelContextAuthority
{
    public const VERSION='li05c.history-review-context-inert.v1';

    /** A metadata-only preview; NEVER disclose source identifiers or captured bytes. */
    public function preview(array $manifest): Outcome
    {
        $valid=HistoryReviewBasis::verify($manifest);
        if (!$valid->isOk()) return Outcome::refuse('LI05C_PREVIEW_BASIS_UNVERIFIED');
        return Outcome::ok([
            'schema_version'=>self::VERSION,
            'scope_derived_from_owner_session'=>false,
            'period'=>$manifest['period'],
            'knowledge_cutoff_utc'=>$manifest['knowledge_cutoff_utc'],
            'selection_integrity'=>$manifest['selection_integrity'],
            'selected_count'=>$manifest['selected_count'],
            'excluded_counts_by_reason'=>$manifest['excluded_counts_by_reason'],
            'coverage_state'=>$manifest['coverage']['state'],
            'basis_digest'=>$manifest['manifest_digest'],
            'U03_historical_reuse_admitted'=>false,
            'U06_model_context_authorized'=>false,
            'customer_output_authorized'=>false,
        ]);
    }

    /** @param array<string,mixed> $context */
    public function revalidate(array $context): Outcome
    {
        return Outcome::refuse('LI05C_U03_U06_HISTORICAL_MODEL_PURPOSE_NOT_ADOPTED');
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $package @param array<string,mixed> $priorCandidate */
    public function validateRoutingPackage(array $context,array $package,array $priorCandidate): Outcome
    {
        return Outcome::refuse('LI05C_HISTORICAL_ROUTING_NOT_ADOPTED');
    }
}
