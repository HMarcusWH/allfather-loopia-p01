# CR-02 — Typed section fingerprints and bounded semantic difference

**Package:** CR-02 / BUILD-07, Wave 03. **Source trace:** Crawler PR-03 and LI-04. **Prerequisite:** CR-01. **Owner:** P01 derived-provenance. **Date:** 2026-10-28. **State:** BUILT_INERT.

## Source and interface

Existing P01 Capture / M05 primary source evidence -> CR-01 DocumentProjection.v1 (synthetic, derived) -> CR-02 SectionFingerprint.v1 and SemanticDelta.v1 -> future OB-03/CR-04 consumers. These are separate from existing P01 ChangeDetection and TextProvenance raw and line-hash statuses; both original owner source files are Git-blob pinned by a new foundation test and unchanged. The original raw HTML SHA-256, URL-plus-body canonical digest and normalized-section text SHA-256 are **different objects**; do not equate their identities.

Input is two exact CR-01 document projections: same tenant/workspace/monitored source/ref/card/card digest and private fixture retention; distinct original capture and evidence IDs; forward observed-at and nondecreasing known-at clocks; exact original raw-byte digest; the HTML5 parser/normalizer version, typed section locator and normalized text hash. Source-card versions must agree. No actual M05 source rights are established by these self-declared, synthetic projection carriers.

Output is versioned **cr02.semantic-delta.v1**, algorithm **cr02.same-selector-unique-content-alignment.v1**, fingerprint **cr02.section-fingerprint.v1**: exact original before/after capture and evidence references, source-card digest, raw-byte SHA-256, normalized section digests, hashed sensitive token families, typed sections, derived selectors (not raw byte offsets), explicit unknown/partial coverage, fixed alignment effort bounds and a canonical output digest. No raw source text, HTML excerpt or full source URL is emitted. Derived hash checking is not primary M05 authentication.

## Three distinct fingerprints

1. Original **raw source HTML byte SHA-256**, carried from CR-01 unchanged. CR-02 has no primary-byte store and does not re-fetch.
2. **Normalized section text SHA-256**, plus a fingerprint that retains normalizer and truncation version. Do not remove price digits, negation, offer terms, contract wording or dates.
3. **Navigation order** digest over ordered link-label SHA-256s, separately from an order-independent sorted multiset (including duplicate labels). Identical labels reordered yield **ORDER_ONLY**, not PRICE_CHANGED; changed labels yield NAV_ITEMS_CHANGED.

Price, negation, offer, contract and date indicators are provisional, hashed **excerpt-token** comparisons, not a business materiality classifier. A literal change outside the bounded excerpt remains TEXT_CHANGED and UNKNOWN/partial rather than a falsely specific signal.

## Alignment, unknowns and bounds

- Same derived HTML5 DOM selector is the strong *candidate* alignment, not a primary byte locator.
- Different selectors may be weakly paired only on **unique** exact section type + normalized body fingerprint on BOTH unmatched sides. This yields POSSIBLE_SECTION_MOVE and WEAK_SECTION_ALIGNMENT, never verified movement or a new primary identity.
- Unpaired/ambiguous sections become UNPAIRED_BEFORE_UNKNOWN / UNPAIRED_AFTER_UNKNOWN, not confirmed product launch, removal or materiality.
- At most **64 sections per projection, 4,096 comparisons, 128 output delta records** and limited sensitive-token matches. Parser recovery and excerpt truncation remain explicit unknown evidence dimensions.
- Report independent structural classification (ORDER_ONLY, TYPED_CHANGE, NO_TYPED_CHANGE or UNKNOWN_ALIGNMENT) and **evidence coverage** (UNKNOWN_OR_PARTIAL when parser repair details, section truncation or weak/missing alignment remain). A raw source digest change with no typed section change is NOT evidence of no primary change; carry RAW_SOURCE_BYTES_DIFFER_WITHOUT_TYPED_SECTION_CHANGE.
- Commercial significance is always **NOT_ASSESSED**. Model, source-use, human, attention, notification, production, customer-release and persistence authority remain FALSE.

## Tests and host compatibility

Synthetic golden corpus: **11 fixed before/after pairs** covering navigation order, price digit, negation, offer availability, contract terms, date, weak selector move, changed nav label set, raw-only script change, truncated-excerpt price and unmatched added section. The PHP 8.2 contract runner uses exact synthetic CR-01-shaped projections; it does NOT claim PHP 8.2 parses HTML5.

An additional **CR02 Native Semantic Delta** GitHub Actions workflow on PHP **8.5** must parse ALL those HTML pairs through actual CR-01 native HTML5 and then feed those projections through CR-02, proving the real positive path. It also runs the pure positive/negative/no-egress tests. On PHP 8.2 the native-only test proves a specific unsupported-parser refusal, not a skipped or fabricated success.

Focused commands:
- php tests/run.php --layer=unit --filter=cr02_semantic_delta --strict
- php tests/run.php --layer=unit --filter=cr02_delta_native --strict
- php tests/run.php --layer=security --filter=cr02_semantic_delta_refusals --strict
- php tests/run.php --layer=foundation --filter=cr02_semantic_boundary --strict

**CI merge requirements:** All five existing GitHub checks, on the exact PR test-merge, plus the independent native PHP8.5 job, must each complete SUCCESS. Build QA alone is not real on-host source-use, model quality, release or V01–V11 operational acceptance. **CR-04** must later establish genuine M05 owner rights and native capture adapter; OB-03 receives deltas only as derived evidence. No CR-02 migrations, retained data or Railway changes.
