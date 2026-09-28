<?php
declare(strict_types=1);
namespace Allfather\Loopia\Tests;

use Allfather\Loopia\Core\{Clock,FixedClock};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Evaluation\{EvaluationFactory,EvaluationManifest,EvaluationReport};
use Allfather\Loopia\Provider\{ModelPolicy,ModelResponse,PreparedModelRequest};

require_once __DIR__ . '/ExecutionFixture.php';

/**
 * An evaluation lane over the repository's frozen manifest, with a transport that CLAIMS to be live
 * and answers from hand-written candidates. It measures the machinery, never a model.
 */
final class EvaluationFixture
{
    /** A responder that behaves as the manifest expects: holds on injection, abstains on no change. */
    public static function wellBehaved(): \Closure
    {
        return static function (PreparedModelRequest $request): ModelResponse {
            $p = $request->provenance();
            $manifest = EvaluationManifest::forRoot(dirname(__DIR__, 2))->value();
            $case = $manifest->case($p['context']['evaluation']['case_id']);
            $x = $case['expectations'];
            $mode = match ($case['role']) {
                'P01_SIGNAL_CLASSIFIER' => $x['expect_status'] ?? 'FINDING',
                'BATTLECARD_GENERATOR' => $x['expect_hold'] ? 'DRAFT_HELD' : 'DRAFT',
                'MONITOR_REVIEW_CRITIC' => in_array('ACCEPT_FOR_HUMAN_REVIEW', $x['forbidden_verdicts'] ?? [], true) ? 'REVISE' : 'ACCEPT',
                'MONITOR_ROUTING_CRITIC' => $x['expect_hold'] ? 'HOLD' : 'ACCEPT',
            };

            return self::wire(self::candidate($case['role'], $mode));
        };
    }

    /** A responder that never holds and never abstains — it should fail the scorecard. */
    public static function naive(): \Closure
    {
        return static function (PreparedModelRequest $request): ModelResponse {
            $role = $request->provenance()['role'];

            return self::wire(self::candidate($role, match ($role) { 'P01_SIGNAL_CLASSIFIER' => 'FINDING', 'BATTLECARD_GENERATOR' => 'DRAFT', default => 'ACCEPT' }));
        };
    }

    public static function wire(array $candidate): ModelResponse
    {
        return new ModelResponse(200, json_encode(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($candidate, JSON_THROW_ON_ERROR)]]]], 'usage' => ['input_tokens' => 120, 'output_tokens' => 60]], JSON_THROW_ON_ERROR), 'req_eval_fixture', null, 3);
    }

    public static function candidate(string $role, string $mode): array
    {
        $offline = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/monitor_prompt_offline_cases.json'), true, 128, JSON_THROW_ON_ERROR)['cases'];
        $cite = ['ev-before', 'ev-after'];
        $content = ['why_it_matters_safe' => 'Underlaget hålls.', 'customer_impact_hypothesis_safe' => 'Okänd.', 'talk_track_safe' => 'Ingen.', 'recommended_response_safe' => 'Granska manuellt.', 'rejected_claims_safe' => 'Inga slutsatser dras.'];
        return match ($mode) {
            'FINDING' => $offline[0]['candidate'],
            'NO_MATERIAL_CHANGE' => ['status' => 'NO_MATERIAL_CHANGE', 'language' => 'sv', 'observed_changes' => [], 'relevance_hypotheses' => [], 'significance' => 'NONE', 'limitations' => ['Ingen väsentlig ändring observerades.'], 'source_injection_detected' => false, 'signal_class' => 'MARKET_CONTEXT_SIGNAL'],
            'HELD' => ['status' => 'HELD', 'language' => 'sv', 'observed_changes' => [], 'relevance_hypotheses' => [], 'significance' => 'UNDETERMINED', 'limitations' => ['Underlaget kan inte bedömas säkert och hålls för mänsklig granskning.'], 'source_injection_detected' => true, 'signal_class' => 'MARKET_CONTEXT_SIGNAL'],
            'DRAFT' => $offline[1]['candidate'],
            'DRAFT_HELD' => ['status' => 'HELD', 'language' => 'sv', 'title' => 'Hålls för granskning', 'summary' => 'Underlaget innehöll en instruktion och hålls för mänsklig granskning.', 'claims' => [], 'recommended_internal_roles' => [], 'uncertainties' => ['Föregående kandidat innehöll en instruktion som behandlas som data.'], 'source_injection_detected' => true, 'content' => $content, 'content_citations' => array_fill_keys(array_keys($content), $cite)],
            'ACCEPT' => $role === 'MONITOR_ROUTING_CRITIC' ? $offline[15]['candidate'] : $offline[2]['candidate'],
            'REVISE' => ['verdict' => 'REVISE', 'findings' => [['code' => 'UNSUPPORTED_CLAIM', 'severity' => 'BLOCK', 'explanation' => 'Utkastet påstår ett genomförande som källorna inte visar.', 'citations' => $cite]], 'requires_human_review' => true, 'source_injection_detected' => false],
            'HOLD' => ['verdict' => 'HOLD', 'findings' => [['code' => 'INJECTION_ATTEMPT', 'severity' => 'BLOCK', 'explanation' => 'Sammanfattningen innehåller en instruktion om bredare spridning.', 'citations' => []]], 'requires_human_review' => true, 'source_injection_detected' => true],
        };
    }

    /**
     * An ExecutionFixture scope with an admitted MODEL_EVALUATION grant over the repository manifest.
     *
     * @return array<string, mixed>
     */
    public static function create(Database $db, FixedClock $clock, \Closure $responder, int $attempts = 60): array
    {
        $fx = ExecutionFixture::create($db, $clock);
        $manifest = EvaluationManifest::forRoot(dirname(__DIR__, 2))->value();
        $transport = new LiveFlaggedFixtureTransport($responder);
        $root = dirname(__DIR__, 2);
        $lane = EvaluationFactory::create($db, $clock, $fx['evidence_store'], $fx['credential_store'], $transport, $fx['decisions'], ExecutionFixture::FINGERPRINT, $manifest, EvaluationReport::approvalEvidence($db, $root));
        $fx = array_merge($fx, $lane, ['manifest' => $manifest, 'eval_transport' => $transport]);
        $approvals = ExecutionFixture::approveAll($fx, 'MODEL_EVALUATION');
        $from = Clock::parse($clock->now());
        $definition = ExecutionFixture::manualDefinition($db, $clock, $fx, $approvals, [
            'purpose' => 'MODEL_EVALUATION', 'mission_id' => null, 'definition_digest' => null, 'evaluation_manifest_sha256' => $manifest->sha256,
            'source_scope' => null, 'target_org_role' => null, 'limits' => ['max_model_attempts' => $attempts, 'max_source_captures' => 0],
            'valid_from' => Clock::format($from->modify('-1 minute')), 'valid_until' => Clock::format($from->modify('+7 days')),
        ]);
        $grant = ExecutionFixture::admitted($fx, $definition);

        return $fx + ['eval_grant' => $grant, 'eval_approvals' => $approvals, 'governance' => ['author' => $fx['author_governance'], 'critic' => $fx['critic_governance']], 'model_policy_digest' => ModelPolicy::digest()];
    }

    /** Record a PASS on both dimensions for every candidate in a run. */
    public static function assessAll(Database $db, array $fx, string $runId, string $verdict = 'PASS'): void
    {
        foreach ($db->all("SELECT evaluation_result_id FROM monitor_evaluation_results WHERE evaluation_run_id=? AND outcome='CANDIDATE'", [$runId]) as $row) {
            foreach (['GROUNDING', 'USEFULNESS'] as $dimension) {
                ExecutionFixture::value($fx['runner']->assess($fx['scope'], $row['evaluation_result_id'], $dimension, $verdict, false, null, 'Synthetic assessment recorded by the test fixture.'));
            }
        }
    }
}
