<?php
declare(strict_types=1);
namespace Allfather\Loopia\PromptOps;
use Allfather\Loopia\Core\Outcome;

/** Mechanical candidate checks AFTER schema validation. This cannot prove natural-language truth. */
final class CandidateChecks
{
    public static function validate(array $candidate, array $resolvedPrompt, array $context): Outcome
    {
        // Exact proposal-specific validator; unchanged v1 checker follows below.
        if (($resolvedPrompt['pack_version'] ?? null) === PromptBundleRegistry::OBSERVATION_V2_PACK) {
            return ObservationCandidateChecksV2::validate($candidate, $resolvedPrompt, $context);
        }
        try {
            $fresh = ProductPromptPack::resolve(is_string($resolvedPrompt['role'] ?? null) ? $resolvedPrompt['role'] : '');
            $trusted = $fresh->isOk()
                && CanonicalContracts::stableJson($fresh->value()) === CanonicalContracts::stableJson($resolvedPrompt);
        } catch (\Throwable) { $trusted = false; }
        if (!$trusted) { return Outcome::refuse('PROMPT_CANDIDATE_UNTRUSTED_BINDING'); }
        $evidence = $context['envelope']['evidence'] ?? null;
        if (!is_array($evidence) || !array_is_list($evidence) || $evidence === []) {
            return Outcome::refuse('PROMPT_CANDIDATE_EVIDENCE_MISSING');
        }
        $byId = [];
        foreach ($evidence as $e) {
            if (!is_array($e) || !is_string($e['evidence_id'] ?? null) || isset($byId[$e['evidence_id']])
                || !in_array($e['basis'] ?? null, ['TENANT_PROVIDED', 'CAPTURED_SOURCE_OBSERVATION'], true)) {
                return Outcome::refuse('PROMPT_CANDIDATE_EVIDENCE_AMBIGUOUS');
            }
            $byId[$e['evidence_id']] = $e;
        }
        $checkRefs = static function (mixed $refs, ?string $basis = null, bool $required = true) use ($byId): bool {
            if (!is_array($refs) || !array_is_list($refs) || ($required && $refs === [])) { return false; }
            foreach ($refs as $id) {
                if (!is_string($id) || !isset($byId[$id]) || ($basis !== null && $byId[$id]['basis'] !== $basis)) { return false; }
            }
            return true;
        };
        $role = $resolvedPrompt['role'];
        $heldValue = in_array($role, ['MONITOR_REVIEW_CRITIC', 'MONITOR_ROUTING_CRITIC'], true) ? ($candidate['verdict'] ?? null) : ($candidate['status'] ?? null);
        if (($candidate['source_injection_detected'] ?? null) === true && !in_array($heldValue, ['HELD', 'HOLD'], true)) {
            return Outcome::refuse('PROMPT_CANDIDATE_INJECTION_REQUIRES_HOLD');
        }
        if ($role === 'P01_SIGNAL_CLASSIFIER') {
            $changes = $candidate['observed_changes'] ?? null; $hypotheses = $candidate['relevance_hypotheses'] ?? null;
            if (!is_array($changes) || !is_array($hypotheses)) { return Outcome::refuse('PROMPT_CANDIDATE_SHAPE_INVALID'); }
            foreach ($changes as $change) {
                if (!is_array($change) || !$checkRefs($change['citations'] ?? null, 'CAPTURED_SOURCE_OBSERVATION')) {
                    return Outcome::refuse('PROMPT_CANDIDATE_OBSERVATION_CITATION_INVALID');
                }
            }
            foreach ($hypotheses as $hypothesis) {
                if (!is_array($hypothesis) || !$checkRefs($hypothesis['basis_citations'] ?? null, 'CAPTURED_SOURCE_OBSERVATION')
                    || !$checkRefs($hypothesis['tenant_context_refs'] ?? null, 'TENANT_PROVIDED')) {
                    return Outcome::refuse('PROMPT_CANDIDATE_RELEVANCE_CITATION_INVALID');
                }
            }
            $status = $candidate['status'] ?? null; $significance = $candidate['significance'] ?? null;
            if (($status === 'FINDING' && ($changes === [] || !in_array($significance, ['MAJOR', 'MINOR'], true)))
                || ($status === 'NO_MATERIAL_CHANGE' && ($significance !== 'NONE' || $changes !== [] || $hypotheses !== []))
                || ($status === 'HELD' && ($significance !== 'UNDETERMINED' || $changes !== [] || $hypotheses !== []
                    || empty($candidate['limitations'])))
                || !in_array($status, ['FINDING', 'NO_MATERIAL_CHANGE', 'HELD'], true)) {
                return Outcome::refuse('PROMPT_CANDIDATE_STATUS_INCONSISTENT');
            }
        } elseif ($role === 'BATTLECARD_GENERATOR') {
            // A broad audience label is not an organization-role selection or a membership grant.
            // ContextEnvelope owns the current, scoped policy proof for this selected target.
            $recommended = $candidate['recommended_internal_roles'] ?? null;
            $selected = $context['envelope']['routing_target']['target_org_role'] ?? null;
            if (!is_array($recommended) || !array_is_list($recommended) || count($recommended) > 1
                || ($recommended !== [] && (!is_string($selected)
                    || !in_array($selected, ['EXECUTIVE_CEO', 'EXECUTIVE_CFO', 'MARKETING_CMO', 'SALES', 'CUSTOMER_SUCCESS'], true)
                    || $recommended !== [$selected]))) {
                return Outcome::refuse('PROMPT_CANDIDATE_ROUTING_TARGET_MISMATCH');
            }
            $claims = $candidate['claims'] ?? null;
            if (!is_array($claims)) { return Outcome::refuse('PROMPT_CANDIDATE_SHAPE_INVALID'); }
            foreach ($claims as $claim) {
                if (!is_array($claim) || !$checkRefs($claim['citations'] ?? null,
                    ($claim['kind'] ?? null) === 'OBSERVATION' ? 'CAPTURED_SOURCE_OBSERVATION' : null)) {
                    return Outcome::refuse('PROMPT_CANDIDATE_CLAIM_CITATION_INVALID');
                }
            }
            foreach (['why_it_matters_safe', 'customer_impact_hypothesis_safe', 'talk_track_safe', 'recommended_response_safe', 'rejected_claims_safe'] as $field) {
                if (!$checkRefs($candidate['content_citations'][$field] ?? null)) {
                    return Outcome::refuse('PROMPT_CANDIDATE_CONTENT_CITATION_INVALID');
                }
            }
            if (($candidate['status'] ?? null) === 'DRAFT' && $claims === []) {
                return Outcome::refuse('PROMPT_CANDIDATE_STATUS_INCONSISTENT');
            }
            if (($candidate['status'] ?? null) === 'HELD' && ($claims !== [] || !empty($candidate['recommended_internal_roles'])
                || empty($candidate['uncertainties']))) { return Outcome::refuse('PROMPT_CANDIDATE_STATUS_INCONSISTENT'); }
        } else {
            $findings = $candidate['findings'] ?? null;
            if (!is_array($findings) || $findings === [] || ($candidate['requires_human_review'] ?? null) !== true) {
                return Outcome::refuse('PROMPT_CANDIDATE_HUMAN_REVIEW_REQUIRED');
            }
            $blocking = false; $clean = false;
            foreach ($findings as $finding) {
                if (!is_array($finding) || !$checkRefs($finding['citations'] ?? null, null, false)) {
                    return Outcome::refuse('PROMPT_CANDIDATE_REVIEW_CITATION_INVALID');
                }
                $blocking = $blocking || ($finding['severity'] ?? null) === 'BLOCK';
                $clean = $clean || ($finding['code'] ?? null) === 'CLEAN';
                if (($finding['code'] ?? null) === 'CLEAN' && ($finding['severity'] ?? null) !== 'INFO') {
                    return Outcome::refuse('PROMPT_CANDIDATE_REVIEW_INCONSISTENT');
                }
            }
            if (($blocking && (($candidate['verdict'] ?? null) === 'ACCEPT_FOR_HUMAN_REVIEW' || $clean))
                || (($candidate['verdict'] ?? null) === 'REVISE' && !$blocking)) {
                return Outcome::refuse('PROMPT_CANDIDATE_REVIEW_INCONSISTENT');
            }
        }
        return Outcome::ok(['candidate' => $candidate, 'reference_checks_passed' => true,
            'semantic_truth_verified' => false, 'human_review_required' => true]);
    }
}
