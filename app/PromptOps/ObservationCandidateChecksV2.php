<?php
declare(strict_types=1);
namespace Allfather\Loopia\PromptOps;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Provider\StructuredOutput;

/** U01 PROPOSAL: mechanical checks; NOT factual proof, approval, authorization, or release. */
final class ObservationCandidateChecksV2
{
    public static function validate(array $candidate, array $prompt, array $context): Outcome
    {
        try {
            $fresh = PromptBundleRegistry::resolve(
                is_string($prompt['role'] ?? null) ? $prompt['role'] : '',
                is_string($prompt['pack_version'] ?? null) ? $prompt['pack_version'] : '',
            );
            $trusted = $fresh->isOk()
                && CanonicalContracts::stableJson($fresh->value()) === CanonicalContracts::stableJson($prompt);
        } catch (\Throwable) {
            $trusted = false;
        }
        if (!$trusted) return Outcome::refuse('PROMPT_CANDIDATE_UNTRUSTED_BINDING');

        $envelope = $context['envelope'] ?? null;
        $evidence = is_array($envelope) ? ($envelope['evidence'] ?? null) : null;
        if (!is_array($evidence) || !array_is_list($evidence) || $evidence === []) {
            return Outcome::refuse('PROMPT_CANDIDATE_EVIDENCE_MISSING');
        }
        $basis = [];
        foreach ($evidence as $e) {
            if (!is_array($e) || !is_string($e['evidence_id'] ?? null)
                || $e['evidence_id'] === '' || isset($basis[$e['evidence_id']])
                || !in_array($e['basis'] ?? null, ['TENANT_PROVIDED','CAPTURED_SOURCE_OBSERVATION'], true)
                || (isset($e['tenant_id']) && $e['tenant_id'] !== ($envelope['tenant_id'] ?? null))
                || (isset($e['workspace_id']) && $e['workspace_id'] !== ($envelope['workspace_id'] ?? null))) {
                return Outcome::refuse('PROMPT_CANDIDATE_EVIDENCE_AMBIGUOUS');
            }
            $basis[$e['evidence_id']] = $e['basis'];
        }
        try {
            $schema = json_decode(json_encode($prompt['output_schema'], JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
            $object = json_decode(json_encode($candidate, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);
            if (!is_array($schema) || !StructuredOutput::matches($object, $schema)) {
                return Outcome::refuse('PROMPT_CANDIDATE_V2_SHAPE_INVALID');
            }
        } catch (\Throwable) {
            return Outcome::refuse('PROMPT_CANDIDATE_V2_SHAPE_INVALID');
        }

        $refs = static function (mixed $ids, string $expected) use ($basis): bool {
            if (!is_array($ids) || !array_is_list($ids) || $ids === []
                || count(array_unique($ids)) !== count($ids)) return false;
            foreach ($ids as $id) {
                if (!is_string($id) || !isset($basis[$id]) || $basis[$id] !== $expected) return false;
            }
            return true;
        };
        foreach ($candidate['observed_changes'] as $observation) {
            if (!$refs($observation['citations'], 'CAPTURED_SOURCE_OBSERVATION')) {
                return Outcome::refuse('PROMPT_CANDIDATE_OBSERVATION_CITATION_INVALID');
            }
        }
        foreach ($candidate['relevance_hypotheses'] as $hypothesis) {
            if (!$refs($hypothesis['basis_citations'], 'CAPTURED_SOURCE_OBSERVATION')
                || !$refs($hypothesis['tenant_context_refs'], 'TENANT_PROVIDED')) {
                return Outcome::refuse('PROMPT_CANDIDATE_RELEVANCE_CITATION_INVALID');
            }
        }

        $status = $candidate['status'];
        $attention = $candidate['attention_recommendation']; // advisory ONLY
        $significance = $candidate['significance']; // BUSINESS, not structural magnitude
        if ($candidate['source_injection_detected'] === true && $status !== 'HELD') {
            return Outcome::refuse('PROMPT_CANDIDATE_INJECTION_REQUIRES_HOLD');
        }
        if ($status === 'NO_MATERIAL_CHANGE') {
            // v2 DIFFERENCE: cited descriptions permitted under nonmaterial status.
            if ($significance !== 'NONE' || $candidate['relevance_hypotheses'] !== []
                || $attention !== 'RETAIN_ONLY'
                || $candidate['signal_class'] !== 'MARKET_CONTEXT_SIGNAL') {
                return Outcome::refuse('PROMPT_CANDIDATE_V2_STATUS_INCONSISTENT');
            }
        } elseif ($status === 'FINDING') {
            if ($candidate['observed_changes'] === []
                || !in_array($significance, ['MINOR','MAJOR'], true)
                || $attention === 'HOLD') {
                return Outcome::refuse('PROMPT_CANDIDATE_V2_STATUS_INCONSISTENT');
            }
        } elseif ($status === 'HELD') {
            if ($significance !== 'UNDETERMINED' || $attention !== 'HOLD'
                || $candidate['observed_changes'] !== [] || $candidate['relevance_hypotheses'] !== []
                || $candidate['limitations'] === []
                || $candidate['signal_class'] !== 'MARKET_CONTEXT_SIGNAL') {
                return Outcome::refuse('PROMPT_CANDIDATE_V2_STATUS_INCONSISTENT');
            }
        } else {
            return Outcome::refuse('PROMPT_CANDIDATE_V2_STATUS_INCONSISTENT');
        }
        return Outcome::ok([
            'candidate'=>$candidate, 'reference_checks_passed'=>true,
            'semantic_truth_verified'=>false, 'human_review_required'=>true,
            'proposal_only'=>true, 'execution_authorized'=>false,
            'release_authorized'=>false, 'notification_authorized'=>false,
            'source_rights_rechecked'=>false,
        ]);
    }
}
