<?php

declare(strict_types=1);

namespace Allfather\Loopia\PromptOps;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Outcome;

/**
 * OB-01: executable, pure, deterministic v2 candidate composition from
 * synthetic development fixtures ONLY.
 *
 * This is a fixture interpreter, NOT a provider call, a trained semantic
 * evaluator, current source-use certification, business truth or release.
 * It creates no database/HTTP/LLM object and cannot authorize the old Monitor
 * runtime to use the unratified U01 prompt. The sole output is an advisory
 * candidate inspected by the EXISTING v2 schema/citation checker.
 *
 * All commercial "significance" inputs are labelled synthetic proposals,
 * never independent factual or human assessment.
 */
final class ObservationMaterialityV2
{
    public const VERSION = 'ob01.observation-composition.synthetic.v1';
    public const FIXTURE_SCOPE = 'OB01_OFFLINE_DEVELOPMENT_ONLY';
    private const INPUT_KEYS = [
        'after_evidence_id', 'after_text', 'assessment_reason', 'attention',
        'before_evidence_id', 'before_text', 'case_kind', 'language',
        'proposed_significance', 'schema_version', 'source_consistency',
        'structural_magnitude', 'synthetic_scope', 'tenant_context_ref_id',
        'tenant_id', 'workspace_id',
    ];

    private const CASE_KINDS = [
        'NAV_ORDER', 'COPYRIGHT_YEAR', 'PRICE_CHANGE', 'NEGATION_CHANGE',
        'SCHEDULE_OR_DATE', 'CONTRADICTION', 'SOURCE_INJECTION', 'UNAVAILABLE',
    ];
    private const SOURCE_STATUSES = ['RECONCILED', 'CONTRADICTORY', 'INJECTION_SUSPECTED', 'UNAVAILABLE'];
    private const MAGNITUDES = ['NONE', 'MINOR', 'MATERIAL', 'UNKNOWN'];

    /**
     * @param array<string,mixed> $fixture synthetic case, not native source bytes
     * @param array<string,mixed> $context digested fixture evidence context
     * @return Outcome<array<string,mixed>>
     */
    public static function composeFixture(array $fixture, array $context): Outcome
    {
        $keys = array_keys($fixture);
        sort($keys, SORT_STRING);
        if ($keys !== self::INPUT_KEYS || ($fixture['schema_version'] ?? null) !== self::VERSION) {
            return Outcome::refuse('OB01_FIXTURE_CONTRACT_INVALID');
        }
        if ($fixture['synthetic_scope'] !== self::FIXTURE_SCOPE
            || !in_array($fixture['case_kind'], self::CASE_KINDS, true)
            || !in_array($fixture['source_consistency'], self::SOURCE_STATUSES, true)
            || !in_array($fixture['structural_magnitude'], self::MAGNITUDES, true)
            || !in_array($fixture['language'], ['sv', 'en'], true)) {
            return Outcome::refuse('OB01_NOT_AN_ADMITTED_FIXTURE_CASE');
        }
        foreach (['tenant_id', 'workspace_id', 'before_evidence_id', 'after_evidence_id'] as $key) {
            if (!is_string($fixture[$key]) || trim($fixture[$key]) === ''
                || strlen($fixture[$key]) > 160) {
                return Outcome::refuse('OB01_FIXTURE_IDENTITY_INVALID');
            }
        }
        if ($fixture['tenant_id'] === $fixture['workspace_id']
            || $fixture['before_evidence_id'] === $fixture['after_evidence_id']) {
            return Outcome::refuse('OB01_AMBIGUOUS_FIXTURE_IDENTITY');
        }
        foreach (['before_text', 'after_text', 'assessment_reason'] as $key) {
            if (!is_string($fixture[$key]) || strlen($fixture[$key]) > 512
                || str_contains($fixture[$key], "\0")
                || !mb_check_encoding($fixture[$key], 'UTF-8')) {
                return Outcome::refuse('OB01_FIXTURE_TEXT_INVALID');
            }
        }
        if ($fixture['tenant_context_ref_id'] !== null
            && (!is_string($fixture['tenant_context_ref_id'])
                || $fixture['tenant_context_ref_id'] === ''
                || strlen($fixture['tenant_context_ref_id']) > 160)) {
            return Outcome::refuse('OB01_TENANT_CONTEXT_ID_INVALID');
        }
        if (!in_array($fixture['proposed_significance'], ['NONE','MINOR','MAJOR','UNDETERMINED'], true)
            || !in_array($fixture['attention'], ['RETAIN_ONLY','REVIEW_LATER','REVIEW_NOW','HOLD'], true)) {
            return Outcome::refuse('OB01_FIXTURE_MATERIALITY_INVALID');
        }

        $envelope = $context['envelope'] ?? null;
        if (!is_array($envelope)
            || ($envelope['fixture_scope'] ?? null) !== self::FIXTURE_SCOPE
            || ($envelope['tenant_id'] ?? null) !== $fixture['tenant_id']
            || ($envelope['workspace_id'] ?? null) !== $fixture['workspace_id']
            || !is_array($envelope['evidence'] ?? null)
            || !array_is_list($envelope['evidence'])
            || count($envelope['evidence']) > 32) {
            return Outcome::refuse('OB01_CONTEXT_SCOPE_INVALID');
        }
        try {
            $digest = Digest::canonicalDigest($envelope);
        } catch (\Throwable) {
            return Outcome::refuse('OB01_CONTEXT_DIGEST_INVALID');
        }
        if (!is_string($context['digest'] ?? null)
            || !hash_equals($digest, $context['digest'])
            || array_diff(array_keys($context), ['envelope','digest']) !== []) {
            return Outcome::refuse('OB01_CONTEXT_DIGEST_MISMATCH');
        }
        $references = [];
        foreach ($envelope['evidence'] as $e) {
            if (!is_array($e)
                || !is_string($e['evidence_id'] ?? null) || $e['evidence_id'] === ''
                || strlen($e['evidence_id']) > 160 || isset($references[$e['evidence_id']])
                || ($e['tenant_id'] ?? null) !== $fixture['tenant_id']
                || ($e['workspace_id'] ?? null) !== $fixture['workspace_id']
                || !in_array($e['basis'] ?? null, ['CAPTURED_SOURCE_OBSERVATION','TENANT_PROVIDED'], true)) {
                return Outcome::refuse('OB01_CONTEXT_EVIDENCE_INVALID');
            }
            $references[$e['evidence_id']] = $e;
        }
        $before = $references[$fixture['before_evidence_id']] ?? null;
        $after = $references[$fixture['after_evidence_id']] ?? null;
        if ($before === null || $after === null
            || $before['basis'] !== 'CAPTURED_SOURCE_OBSERVATION'
            || $after['basis'] !== 'CAPTURED_SOURCE_OBSERVATION') {
            return Outcome::refuse('OB01_CAPTURED_PAIR_NOT_FOUND');
        }
        foreach ([[$before, 'before_text'], [$after, 'after_text']] as [$record, $key]) {
            if (!is_string($record['text_sha256'] ?? null)
                || !hash_equals(hash('sha256', $fixture[$key]), $record['text_sha256'])
                || !is_array($record['binding'] ?? null)
                || !is_string($record['binding']['monitored_source_id'] ?? null)
                || !is_int($record['binding']['snapshot_sequence'] ?? null)) {
                return Outcome::refuse('OB01_SNAPSHOT_TEXT_PROOF_MISMATCH');
            }
        }
        if ($before['binding']['monitored_source_id'] === ''
            || $before['binding']['monitored_source_id'] !== $after['binding']['monitored_source_id']
            || $before['binding']['snapshot_sequence'] < 0
            || $before['binding']['snapshot_sequence'] >= $after['binding']['snapshot_sequence']) {
            return Outcome::refuse('OB01_SNAPSHOT_ORDER_OR_SOURCE_MISMATCH');
        }
        $tenant = $fixture['tenant_context_ref_id'];
        if ($tenant !== null
            && (($references[$tenant]['basis'] ?? null) !== 'TENANT_PROVIDED'
                || $tenant === $fixture['before_evidence_id']
                || $tenant === $fixture['after_evidence_id'])) {
            return Outcome::refuse('OB01_TENANT_CONTEXT_NOT_BOUND');
        }

        // Content and selection MUST remain separate. An executable prompt
        // cannot be obtained by submitting an offline fixture or a forged
        // execution grant. No governed resolver or provider is constructed.
        $resolver = PromptSelectionResolver::inert();
        $version = PromptBundleRegistry::OBSERVATION_V2_PACK;
        $selection = $resolver->selectVersioned(PromptBundleRegistry::OBSERVATION_ROLE, $version, null);
        if (!$selection->isRefusal()
            || $selection->reasonCode() !== 'PROMPT_OBSERVATION_V2_U01_NOT_ADOPTED') {
            return Outcome::refuse('OB01_U01_SELECTION_BOUNDARY_CHANGED');
        }
        $prompt = $resolver->contentVersioned(PromptBundleRegistry::OBSERVATION_ROLE, $version);
        if (!$prompt->isOk()) {
            return $prompt;
        }
        $bound = $prompt->value();
        if (($bound['activation_status'] ?? null) !== 'INACTIVE'
            || ($bound['contract_status'] ?? null) !== 'PROPOSED_U01_NOT_RATIFIED'
            || ($bound['execution_authorized'] ?? null) !== false
            || ($bound['model_evaluated'] ?? null) !== false) {
            return Outcome::refuse('OB01_PROMPT_ADOPTION_NOT_PROVED');
        }

        $refs = [$fixture['before_evidence_id'], $fixture['after_evidence_id']];
        $beforeText = trim($fixture['before_text']);
        $afterText = trim($fixture['after_text']);
        $consistency = $fixture['source_consistency'];
        $suspicious = self::suspiciousSourceText($beforeText . "\n" . $afterText);
        $injection = $consistency === 'INJECTION_SUSPECTED' || $fixture['case_kind'] === 'SOURCE_INJECTION' || $suspicious;
        $held = $injection || $consistency !== 'RECONCILED'
            || in_array($fixture['case_kind'], ['CONTRADICTION','UNAVAILABLE'], true)
            || $beforeText === '' || $afterText === '';

        $observation = null;
        $hypothesis = [];
        if ($held) {
            $candidate = self::held($fixture['language'], $injection,
                $injection ? 'Untrusted source instructions require independent isolation.'
                    : ($consistency === 'CONTRADICTORY'
                        ? 'Contrary source evidence must be reconciled before interpretation.'
                        : 'Required current, admissible source evidence is unavailable or ambiguous.'));
        } else {
            if ($beforeText === $afterText) {
                return Outcome::refuse('OB01_NO_SOURCE_CHANGE');
            }
            $quiet = in_array($fixture['case_kind'], ['NAV_ORDER','COPYRIGHT_YEAR'], true);
            if ($quiet) {
                if ($fixture['proposed_significance'] !== 'NONE'
                    || $fixture['attention'] !== 'RETAIN_ONLY'
                    || $tenant !== null
                    || $fixture['assessment_reason'] !== '') {
                    return Outcome::refuse('OB01_QUIET_CANNOT_ESCALATE_MATERIALITY');
                }
            } elseif (!in_array($fixture['case_kind'], ['PRICE_CHANGE','NEGATION_CHANGE','SCHEDULE_OR_DATE'], true)
                || !in_array($fixture['proposed_significance'], ['MINOR','MAJOR'], true)
                || !in_array($fixture['attention'], ['REVIEW_LATER','REVIEW_NOW'], true)
                || $tenant === null
                || trim($fixture['assessment_reason']) === '') {
                return Outcome::refuse('OB01_REVIEW_HYPOTHESIS_INCOMPLETE');
            }

            $description = self::describe($fixture['case_kind'], $beforeText, $afterText);
            if ($description === null) {
                return Outcome::refuse('OB01_CHANGE_KIND_NOT_PROVED_BY_FIXTURE');
            }
            $observation = [
                'kind' => match ($fixture['case_kind']) {
                    'NAV_ORDER' => 'NAV_ORDER',
                    'COPYRIGHT_YEAR' => 'OTHER_DESCRIPTIVE',
                    'SCHEDULE_OR_DATE' => 'SCHEDULE_OR_DATE',
                    default => 'PRICE_OR_TERMS',
                },
                'description_safe' => $description,
                'citations' => $refs,
                'claim_type' => 'DESCRIPTIVE_OBSERVATION',
                'confidence' => 'MEDIUM',
            ];
            if (!$quiet) {
                $hypothesis = [[
                    'hypothesis' => 'Synthetic review hypothesis: ' . trim($fixture['assessment_reason']),
                    'basis_citations' => $refs,
                    'tenant_context_refs' => [$tenant],
                    'uncertainty' => 'This is a fixture-derived, unverified commercial interpretation requiring independent review.',
                ]];
            }
            $candidate = [
                'status' => $quiet ? 'NO_MATERIAL_CHANGE' : 'FINDING',
                'language' => $fixture['language'],
                'observed_changes' => [$observation],
                'relevance_hypotheses' => $hypothesis,
                'significance' => $quiet ? 'NONE' : $fixture['proposed_significance'],
                'attention_recommendation' => $quiet ? 'RETAIN_ONLY' : $fixture['attention'],
                'limitations' => ['Synthetic-only composition; actual source rights, commercial truth and attention not independently verified.'],
                'source_injection_detected' => false,
                'signal_class' => $quiet ? 'MARKET_CONTEXT_SIGNAL'
                    : ($fixture['case_kind'] === 'SCHEDULE_OR_DATE'
                        ? 'MARKET_CONTEXT_SIGNAL' : 'PRICING_CHANGE_SIGNAL'),
            ];
        }
        $checked = ObservationCandidateChecksV2::validate($candidate, $bound, $context);
        if (!$checked->isOk()) {
            return $checked; // no fallback to legacy v1
        }
        $validation = $checked->value();
        return Outcome::ok([
            'schema_version' => self::VERSION,
            'scope' => ['tenant_id' => $fixture['tenant_id'],
                'workspace_id' => $fixture['workspace_id']],
            'source_pair' => ['before_evidence_id' => $refs[0],
                'after_evidence_id' => $refs[1],
                'before_sha256' => $before['text_sha256'],
                'after_sha256' => $after['text_sha256'],
                'monitored_source_id' => $before['binding']['monitored_source_id']],
            'prompt_identity' => [
                'pack_version' => $bound['pack_version'],
                'prompt_sha256' => $bound['prompt_sha256'],
                'schema_sha256' => $bound['schema_sha256'],
                'validator' => 'ObservationCandidateChecksV2',
            ],
            'candidate' => $candidate,
            'candidate_digest' => Digest::canonicalDigest($candidate),
            'fixture_digest' => Digest::canonicalDigest($fixture),
            'context_digest' => $digest,
            'structural_magnitude_reported_by_fixture' => $fixture['structural_magnitude'],
            'business_materiality_proposal' => $candidate['significance'],
            'attention_suggestion_only' => $candidate['attention_recommendation'],
            'citation_checks_passed' => $validation['reference_checks_passed'],
            'semantic_truth_verified' => false,
            'current_source_rights_verified' => false,
            'synthetic_fixture_only' => true,
            'human_review_required' => true,
            'v1_runtime_selected' => false,
            'execution_authorized' => false,
            'provider_dispatch_authorized' => false,
            'release_authorized' => false,
            'notification_authorized' => false,
            'customer_output_authorized' => false,
            'owner_decision_status' => 'U01_U06_NOT_ADOPTED',
            'state' => 'ADVISORY_CANDIDATE_NOT_NATIVE_OWNER_RESULT',
        ]);
    }

    /** A deterministic limited signal, NOT a complete prompt-injection detector. */
    private static function suspiciousSourceText(string $text): bool
    {
        return preg_match(
            '/(?:ignore\s+(?:all\s+)?(?:previous|prior)\s+instructions|'
            . 'publish\s+automatically|send\s+(?:the\s+)?(?:keys|secrets|credentials)|'
            . 'reveal\s+(?:the\s+)?(?:api\s+key|password|system\s+prompt))/iu',
            $text,
        ) === 1;
    }

    private static function held(string $language, bool $injection, string $limitation): array
    {
        return [
            'status' => 'HELD',
            'language' => $language,
            'observed_changes' => [],
            'relevance_hypotheses' => [],
            'significance' => 'UNDETERMINED',
            'attention_recommendation' => 'HOLD',
            'limitations' => [$limitation],
            'source_injection_detected' => $injection,
            'signal_class' => 'MARKET_CONTEXT_SIGNAL',
        ];
    }

    private static function describe(string $kind, string $before, string $after): ?string
    {
        if ($kind === 'NAV_ORDER') {
            $old = self::navigationItems($before);
            $new = self::navigationItems($after);
            if ($old === null || $new === null || $old === $new) {
                return null;
            }
            $oldSorted = $old;
            $newSorted = $new;
            sort($oldSorted, SORT_STRING);
            sort($newSorted, SORT_STRING);
            if ($oldSorted !== $newSorted) {
                return null; // new product/removed item is not just a reorder
            }
            return 'Navigation order changed from "' . implode(', ', $old)
                . '" to "' . implode(', ', $new) . '".';
        }
        if ($kind === 'COPYRIGHT_YEAR') {
            if (preg_match('/(?:Copyright|©)\s+(20[0-9]{2})\b/u', $before, $b) !== 1
                || preg_match('/(?:Copyright|©)\s+(20[0-9]{2})\b/u', $after, $a) !== 1
                || $b[1] === $a[1]) {
                return null;
            }
            return 'Copyright year changed from ' . $b[1] . ' to ' . $a[1] . '.';
        }
        if ($kind === 'PRICE_CHANGE') {
            $pattern = '/(?<![A-Za-z0-9])([0-9]{1,9}(?:[.,][0-9]{1,2})?)\s*(SEK|USD|EUR)\b/iu';
            if (preg_match($pattern, $before, $b) !== 1
                || preg_match($pattern, $after, $a) !== 1
                || strtoupper($b[2]) !== strtoupper($a[2])
                || $b[1] === $a[1]) {
                return null;
            }
            return 'Published price notation changed from "' . $b[1] . ' '
                . strtoupper($b[2]) . '" to "' . $a[1] . ' ' . strtoupper($a[2]) . '".';
        }
        if ($kind === 'NEGATION_CHANGE') {
            $negative = static fn (string $text): bool
                => preg_match('/\b(?:not|inte|no)\b/iu', $text) === 1;
            if ($negative($before) === $negative($after)) {
                return null;
            }
            return 'Negation in source wording changed from "' . $before
                . '" to "' . $after . '".';
        }
        if ($kind === 'SCHEDULE_OR_DATE') {
            $dates = '/\b(?:20[0-9]{2}-[01][0-9]-[0-3][0-9]|'
                . '(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+[0-3]?[0-9])\b/iu';
            if (preg_match($dates, $before, $b) !== 1
                || preg_match($dates, $after, $a) !== 1
                || $b[0] === $a[0]) {
                return null;
            }
            return 'Published schedule/date text changed from "' . $b[0]
                . '" to "' . $a[0] . '".';
        }
        return null;
    }

    /** @return ?list<string> */
    private static function navigationItems(string $raw): ?array
    {
        $parts = preg_split('/\s*,\s*/u', trim($raw));
        if (!is_array($parts) || count($parts) < 2 || count($parts) > 16) {
            return null;
        }
        $out = [];
        foreach ($parts as $part) {
            if ($part === '' || strlen($part) > 80 || in_array($part, $out, true)) {
                return null;
            }
            $out[] = $part;
        }
        return $out;
    }
}
