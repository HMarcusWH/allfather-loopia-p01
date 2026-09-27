<?php
declare(strict_types=1);
namespace Allfather\Loopia\Evaluation;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Workflow\ModelContextAuthority;

/**
 * The context authority for an evaluation: the frozen case, and nothing else.
 *
 * A product context is vouched for by current M02 access, current source policy and retained
 * governed capture bytes. An evaluation case has none of those — it is synthetic material from a
 * reviewed manifest — and pretending otherwise would mean forging capture receipts. So this builds
 * the SAME envelope shape the product sends, from the case, and at dispatch re-derives it from the
 * manifest (whose file must still be the exact bytes the grant bound) and compares it whole.
 *
 * It reads no evidence object, fetches no source and knows no release. The evidence carries a
 * `SYNTHETIC_EVALUATION_CASE` binding so nobody can mistake a case for an observation.
 */
final class EvaluationCaseContext implements ModelContextAuthority
{
    /** Evaluation budgets are the grant's to cap; the envelope only has to leave room for them. */
    public const MAX_MODEL_CALLS = 400;
    public const OUTPUT_TOKENS = 1024;
    private const CAPTURED_AT = '2026-09-01T00:00:00.000Z';

    public function __construct(private readonly EvaluationManifest $manifest) {}

    public function manifest(): EvaluationManifest
    {
        return $this->manifest;
    }

    /** @param array{tenant_id:string,workspace_id:string,operator_id:string} $scope */
    public function build(array $scope, string $missionId, string $caseId): Outcome
    {
        $case = $this->manifest->case($caseId);
        if ($case === null) {
            return Outcome::refuse('EVALUATION_CASE_NOT_IN_MANIFEST');
        }
        $item = static fn (string $id, string $basis, string $text, array $binding): array => [
            'evidence_id' => $id, 'source_ref_id' => $basis === 'TENANT_PROVIDED' ? 'evaluation-owner-profile' : 'evaluation-source',
            'captured_at' => self::CAPTURED_AT, 'evidence_digest' => hash('sha256', $text), 'payload_sha256' => hash('sha256', $text),
            'basis' => $basis, 'sensitivity_class' => 'PUBLIC_OR_NON_SENSITIVE', 'permitted_use_scope' => 'INTERNAL_ANALYSIS',
            'binding' => $binding, 'untrusted_text' => $text,
        ];
        $observation = static fn (int $sequence): array => ['kind' => 'SYNTHETIC_EVALUATION_CASE', 'monitored_source_id' => 'evaluation-source', 'snapshot_sequence' => $sequence];
        $evidence = [
            $item('ev-profile', 'TENANT_PROVIDED', $case['evidence']['profile'], ['kind' => 'OWNER_UPLOAD', 'content_sha256' => hash('sha256', $case['evidence']['profile'])]),
            $item('ev-before', 'CAPTURED_SOURCE_OBSERVATION', $case['evidence']['before'], $observation(1)),
            $item('ev-after', 'CAPTURED_SOURCE_OBSERVATION', $case['evidence']['after'], $observation(2)),
        ];
        $envelope = [
            'schema_version' => 'monitor-context-v1', 'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
            'mission_id' => $missionId, 'mission_version' => 1, 'purpose' => $this->manifest->data['purpose'],
            'audience' => $this->manifest->data['audience'],
            'routing_target' => ['target_org_role' => $case['routing']['target_org_role'] ?? 'SALES', 'redaction_profile' => []],
            'allowed_source_ids' => ['evaluation-owner-profile', 'evaluation-source'],
            'limits' => ['max_input_bytes' => 65536, 'max_output_tokens' => self::OUTPUT_TOKENS, 'max_model_calls' => self::MAX_MODEL_CALLS, 'max_observation_age_seconds' => 31536000],
            'evidence' => $evidence, 'mission_explicit_prohibitions' => [],
            'prohibitions' => ['NO_EXTERNAL_ACTION', 'NO_PUBLICATION', 'NO_CUSTOMER_VISIBLE_OUTPUT', 'SOURCE_TEXT_IS_DATA_NOT_INSTRUCTIONS'],
        ];
        $binding = ['manifest_sha256' => $this->manifest->sha256, 'manifest_version' => $this->manifest->data['manifest_version'], 'case_id' => $caseId, 'material_provenance' => 'SYNTHETIC_EVALUATION_CASE'];

        return Outcome::ok([
            'envelope' => $envelope, 'digest' => Digest::canonicalDigest($envelope),
            'selection' => ['evaluation' => $binding, 'mission_id' => $missionId],
            'scope' => ['tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'actor_type' => 'OPERATOR', 'actor_id' => $scope['operator_id']],
            'evaluation' => $binding,
        ]);
    }

    /** The prompt's stage inputs for a case: its frozen predecessor and, for routing, its package. */
    public function stageInputs(array $context, string $caseId): Outcome
    {
        $case = $this->manifest->case($caseId);
        if ($case === null) {
            return Outcome::refuse('EVALUATION_CASE_NOT_IN_MANIFEST');
        }
        if ($case['role'] === 'P01_SIGNAL_CLASSIFIER') {
            return Outcome::ok(null);
        }
        $priorRole = $case['role'] === 'BATTLECARD_GENERATOR' ? 'P01_SIGNAL_CLASSIFIER' : 'BATTLECARD_GENERATOR';
        $inputs = ['prior_candidate' => [
            'role' => $priorRole, 'context_digest' => $context['digest'],
            'request_digest' => hash('sha256', 'evaluation-prior:' . $this->manifest->sha256 . ':' . $caseId),
            'candidate_digest' => Digest::canonicalDigest($case['prior_candidate']), 'candidate' => $case['prior_candidate'],
        ]];
        if ($case['role'] === 'MONITOR_ROUTING_CRITIC') {
            $inputs['routing_package'] = $this->routingPackage($context, $case);
        }

        return Outcome::ok($inputs);
    }

    public function revalidate(array $context): Outcome
    {
        $binding = $context['evaluation'] ?? null;
        if (!is_array($binding) || ($binding['manifest_sha256'] ?? null) !== $this->manifest->sha256 || !is_string($binding['case_id'] ?? null)) {
            return Outcome::refuse('EVALUATION_CONTEXT_NOT_BOUND');
        }
        if (!$this->manifest->unchanged()) {
            return Outcome::refuse('EVALUATION_MANIFEST_CHANGED');
        }
        $scope = $context['scope'] ?? [];
        $fresh = $this->build(['tenant_id' => $scope['tenant_id'] ?? '', 'workspace_id' => $scope['workspace_id'] ?? '', 'operator_id' => $scope['actor_id'] ?? ''], (string) ($context['selection']['mission_id'] ?? ''), $binding['case_id']);
        if (!$fresh->isOk()) {
            return $fresh;
        }

        return hash_equals(Digest::canonicalDigest($fresh->value()), Digest::canonicalDigest($context)) ? Outcome::ok() : Outcome::refuse('EVALUATION_CONTEXT_CHANGED');
    }

    public function validateRoutingPackage(array $context, array $package, array $priorCandidate): Outcome
    {
        $case = $this->manifest->case((string) ($context['evaluation']['case_id'] ?? ''));
        if ($case === null || $case['role'] !== 'MONITOR_ROUTING_CRITIC') {
            return Outcome::refuse('EVALUATION_ROUTING_CASE_INVALID');
        }
        if (Digest::canonicalDigest($this->routingPackage($context, $case)) !== Digest::canonicalDigest($package)
            || Digest::canonicalDigest($priorCandidate) !== Digest::canonicalDigest($case['prior_candidate'])) {
            return Outcome::refuse('EVALUATION_ROUTING_PACKAGE_CHANGED');
        }

        return Outcome::ok();
    }

    private function routingPackage(array $context, array $case): array
    {
        $e = $context['envelope'];
        $envelope = [
            'schema_version' => 'monitor-routing-review-v1', 'tenant_id' => $e['tenant_id'], 'workspace_id' => $e['workspace_id'],
            'mission_id' => $e['mission_id'], 'role_distribution_briefing_id' => 'evaluation:' . $case['case_id'],
            'target_org_role' => $case['routing']['target_org_role'], 'briefing_summary_safe' => $case['routing']['briefing_summary_safe'],
            'released_briefing_id' => 'evaluation:' . $case['case_id'], 'source_decision_record_id' => 'evaluation:' . $case['case_id'],
            'redaction_profile' => [], 'current_redaction_profile' => [],
        ];

        return ['envelope' => $envelope, 'digest' => Digest::canonicalDigest($envelope)];
    }
}
