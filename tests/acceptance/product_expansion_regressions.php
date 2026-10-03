<?php

declare(strict_types=1);

/**
 * P08 — the named P02/P03/P05 expansion concerns, re-run against CURRENT code.
 *
 * The canonical parity baseline (docs/reconciliation/canonical-parity-baseline-2026-09-27.md)
 * reproduced several shortcomings that gate broader product use. This suite runs each named scenario
 * and pins what the code does TODAY, classified one of three ways:
 *
 *   HOLDS                       the protection the scenario asks about is present
 *   KNOWN_DEFECT                reproduced; the repair belongs upstream first, then a port. The
 *                               assertion pins the CURRENT behaviour, so a repair has to flip it
 *                               on purpose rather than drift past it unnoticed
 *   NO_SEMANTIC_EVALUATION      not a bug in a check, but a check that does not exist: deterministic
 *                               markers cannot establish meaning, and nothing here claims they do
 *
 * Nothing in this file repairs anything. A green run says the register in
 * docs/P03_ASSURANCE_PORT.md §5 still describes the code; it does not say the products are ready to
 * expand. P02 foreign-scope working-state writes are NOT re-run here and remain recorded open there.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P01\SourceCards;
use Allfather\Loopia\Product\P03\Assurance as P03Assurance;
use Allfather\Loopia\Product\P03\Catalogue;
use Allfather\Loopia\Product\P05\MarketingPatterns;
use Allfather\Loopia\Substrate\M08\RuleEngine;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

// ============================================================ P05 lexical coverage (pure)

$lexical = [
    // [copy, comparative?, forbidden?, classification]
    ['Faster than the rival suite, at half the price.', true, false, 'HOLDS'],
    ['Unlike competitor X, we ship weekly.', true, false, 'HOLDS'],
    ['Fake scarcity: only 5 minutes left!', false, true, 'HOLDS'],
    ['Target pregnant shoppers with this offer.', false, true, 'HOLDS'],
    // Swedish comparative markers: "faster than the competitor", "compared with", "better than".
    ['Snabbare än konkurrenten, till halva priset.', false, false, 'KNOWN_DEFECT'],
    ['Jämfört med andra leverantörer är vi billigare.', false, false, 'KNOWN_DEFECT'],
    ['Bättre än alternativet på marknaden.', false, false, 'KNOWN_DEFECT'],
    // Swedish urgency / trait targeting: "only 5 minutes left", "target pregnant".
    ['Bara 5 minuter kvar – agera nu!', false, false, 'KNOWN_DEFECT'],
    ['Rikta erbjudandet mot gravida kunder.', false, false, 'KNOWN_DEFECT'],
];
foreach ($lexical as [$copy, $comparative, $forbidden, $classification]) {
    Assert::same(
        [$comparative, $forbidden],
        [MarketingPatterns::hasComparativeClaim($copy), MarketingPatterns::hasForbiddenPattern($copy)],
        "P05 lexical [{$classification}]: {$copy}",
    );
}

// NO_SEMANTIC_EVALUATION: a NEGATED comparison still triggers the citation requirement (over-trigger
// is the safe direction), and a comparison stated without any marker word is invisible. Neither is
// fixable by adding words; both need semantic evaluation that does not exist here.
Assert::true(MarketingPatterns::hasComparativeClaim('We are not better than any rival.'), 'P05 [NO_SEMANTIC_EVALUATION]: negation still over-triggers');
Assert::false(MarketingPatterns::hasComparativeClaim('Others take a week; we take a day.'), 'P05 [NO_SEMANTIC_EVALUATION]: an unmarked comparison is invisible');

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());

    return;
}
$clock = Harness::clock();
$scope = Seed::tenantWorkspace($db, $clock, 'expansion');

// ============================================================ P05 title scan

// KNOWN_DEFECT: the admission context the two P05 content rules read carries the draft BODY only. A
// comparative or forbidden claim placed in the TITLE is never evaluated by the gate. The draft row is
// injected with foreign-key checks suspended because the question is only what the context loader
// reads, not whether a governed path could reach this row.
$proposalId = Ids::new();
$db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
try {
    $db->insert('marketing_asset_drafts', [
        'marketing_asset_draft_id' => Ids::new(), 'schema_version' => '1', 'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'], 'asset_kind' => 'COPY_VARIANT', 'proposal_id' => $proposalId,
        'released_briefing_id' => Ids::new(),
        'title_safe' => 'Better than the rival — fake scarcity edition', 'draft_content_safe' => 'A neutral body.',
        'asset_status' => 'IN_REVIEW', 'asset_digest' => str_repeat('f', 64), 'created_at' => $clock->now(),
    ]);
} catch (\PDOException $error) {
    Assert::fail('the title-scan scenario could not seed its draft: ' . $error->getMessage());
} finally {
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
}
$context = RuleEngine::loadP05Context($db, $proposalId);
Assert::true($context !== null, 'P05 title scan: the context loads');
Assert::false(array_key_exists('title_safe', $context ?? []), 'P05 title scan [KNOWN_DEFECT]: the gate context carries no title');
Assert::false(
    MarketingPatterns::hasComparativeClaim((string) ($context['draft_content_safe'] ?? ''))
        || MarketingPatterns::hasForbiddenPattern((string) ($context['draft_content_safe'] ?? '')),
    'P05 title scan [KNOWN_DEFECT]: so a comparative, forbidden title passes both content rules',
);

// ============================================================ P03 owner-upload licence mapping

// KNOWN_DEFECT: canonical projects a tenant's catalogue upload as `customer_upload` /
// `customer_owned_tenant_scoped` / LICENSED_INTERNAL_ONLY and refuses a public mapping. Here the
// catalogue owner checks ownership and approval only, so a TENANT_OWNED card carrying a PUBLIC web
// licence is accepted as catalogue authority.
$card = (new SourceCards($db, $clock))->project([
    'tenant_id' => $scope['tenant_id'],
    'source_name' => 'Tenant price list',
    'source_url_safe' => 'https://tenant.example/prices',
    'source_type' => 'manual',
    'allowed_use_class' => 'derived_summary_allowed',
    'license_class' => 'OPEN_CC0',
    'review_status' => 'approved',
    'data_ownership' => 'TENANT_OWNED',
]);
$registered = (new Catalogue($db, $clock))->registerCatalogue(
    $scope['tenant_id'], $scope['workspace_id'], 'public-mapped', 'Public-mapped', $card['source_ref_id'], $card['source_license_card_id']
);
Assert::true($registered->isOk(), 'P03 owner-upload mapping [KNOWN_DEFECT]: a public-licence tenant card still backs a catalogue');

// HOLDS: the three canonical P03 post-outcome audits now exist (P08) — the parity baseline's
// "assurance profiles are not ported" finding is closed by tests/conformance/p03_assurance.php.
Assert::same(
    ['CATALOGUE_FRESHNESS', 'SIGNAL_PAIRING_FRESHNESS', 'BUYER_INTENT_INVARIANT'],
    P03Assurance::CATEGORIES,
    'P03 assurance [HOLDS]: all three canonical audit families are ported',
);

// ============================================================ the register stays truthful

// The register in docs/P03_ASSURANCE_PORT.md §5 is the claim this suite backs. Every scenario that
// is not repaired must stay listed and read OPEN, so a green run can never be read as "the broader
// SME blockers are closed". A row may leave OPEN only in the same change that flips its pinned
// assertion above, after the repair has landed upstream and been ported.
$register = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/P03_ASSURANCE_PORT.md');
// Parse the §5 table into cells, so the check reads what a reader reads: one row per scenario, a
// classification that is not HOLDS, a status cell that is exactly **OPEN**, and no second row for
// the same scenario saying something else.
$section = substr($register, (int) strpos($register, '## 5. P02/P03/P05 expansion register'));
$sectionEnd = strpos($section, "\n## ", 1);
$section = $sectionEnd === false ? $section : substr($section, 0, $sectionEnd);
$rows = [];
foreach (explode("\n", $section) as $line) {
    if (!str_starts_with($line, '| ') || str_starts_with($line, '| Scenario') || str_starts_with($line, '| ---')) {
        continue;
    }
    $cells = array_map('trim', explode('|', trim($line, " |")));
    $rows[] = $cells;
}
Assert::true(count($rows) >= 7, 'the expansion register table parses');
foreach ([
    'P05 Swedish comparative markers',
    'P05 Swedish urgency and trait targeting',
    'P05 title scan',
    'P05 citation entailment',
    'P03 owner-upload licence mapping',
    'P03 catalogue audit from a sibling workspace',
    'P02 foreign-scope working-state writes',
    'P05 negated comparison',
    'P05 unmarked comparison',
    'P03 structured ICP size discrimination',
] as $openRow) {
    $matching = array_values(array_filter($rows, static fn (array $cells): bool => str_starts_with($cells[0], $openRow)));
    Assert::same(1, count($matching), "the expansion register has exactly one row for '{$openRow}'");
    $cells = $matching[0] ?? ['', '', '', ''];
    Assert::same(4, count($cells), "'{$openRow}' has scenario, classification, status and behaviour cells");
    Assert::true(($cells[1] ?? '') !== '' && !str_contains(strtoupper($cells[1] ?? ''), 'HOLDS'), "'{$openRow}' is not classified HOLDS");
    Assert::same('**OPEN**', $cells[2] ?? null, "'{$openRow}' reads exactly OPEN");
    Assert::false((bool) preg_match('/\b(repaired|fixed|resolved|closed)\b/i', ($cells[1] ?? '') . ' ' . ($cells[3] ?? '')), "'{$openRow}' does not describe itself as repaired");
}
$findings = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/UPSTREAM_FINDINGS.md');
$uf004 = substr($findings, (int) strpos($findings, '## UF-004'));
$next = strpos($uf004, "\n## ", 1);
$uf004 = $next === false ? $uf004 : substr($uf004, 0, $next);
Assert::true(str_starts_with($uf004, '## UF-004'), 'UF-004 is recorded');
Assert::same(1, preg_match_all('/^\*\*Status:\*\* .*$/m', $uf004, $statusLines), 'UF-004 has exactly one status line');
Assert::same(
    '**Status:** REPORTED — parity kept; pinned by vector `catalogue-workspace-owned-from-sibling-workspace`.',
    $statusLines[0][0] ?? null,
    'UF-004 stays recorded as reported upstream with parity kept, not resolved',
);
