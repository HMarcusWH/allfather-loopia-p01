# CR-04 — Source-bound derived crawler context for P01 (candidate only)

**Package:** CR-04 / Wave 08, source requirements Crawler PR-04/05, LI-02 and LI-04. Dependencies CR-02, OB-01, OB-03, HS-02 and IV-01 have been merged. U01/U02/U03/U06 remain owner approval gates. The source is **BUILT_INERT**: no native source-use or production model context is admitted.

## Existing owners and positive synthetic composition

Reuse the existing CR-01 HtmlDocumentProjection/DocumentProjectionContract, CR-02 SemanticDelta/SectionFingerprint, OB-02 ObservationAssessment and OB-03 ObservationFeatureSet instead of adding a spider or second content store. DerivedSectionEvidence::compose accepts two already-projected private synthetic captures, a bound P01 assessment and a limited set of capture-hash references; it runs SemanticDelta::compare and ObservationFeatureSet::compose itself. Both parent candidates must match exact tenant/workspace/source/license card/snapshot/evidence identities and immutable digests or the existing owner refuses.

The CR-02 synthetic fixture generator yields strict CR-01-shaped projections on PHP 8.2, where native Dom.HTMLDocument is not available. This does **not** prove the native HTML5 parser ran. Actual parser support remains PHP 8.4+ with ext-dom/mbstring and separate native tests. CR-04 does not turn the test-only input_kind or retention class into a true source-use entitlement.

## Bounded typed context and trust boundary

The output includes source/card/snapshot/evidence/hash lineage, original HTML raw SHA and **separate** P01 canonical content hash, parser/extractor/normalization and semantic-delta algorithm versions, known-at/observed-at clocks, typed section change enums, sensitive token kind codes, uncertainty codes and counts. Original HTML, JSON-LD, snippets, DOM selectors, raw byte offsets, credentials and model prompt bodies are **not exported**. Derived selectors become SHA-256 locator digests; they are never asserted to identify original byte offsets. Untrusted source instructions stay data, not system text or tool/role choices.

Metadata is capped at 128 delta rows and 16,384 canonical JSON bytes; the scope and all nested fields are contract checked before any caller could use the candidate. The product exposes no live provider conversion: forProviderContext unconditionally refuses while U01/U02/U03/U06 are unratified. There is no token-savings claim; provider_token_estimate remains null until separately measured under real provider QA.

Five identical HTML captures can be one exact byte class, not five independent publishing origins, events or verified corroborating witnesses. Structural change does not imply business materiality. Missing rights and known-at/capture coverage are HOLDs, not a factual NO_CHANGE.

## Isolation, permissions and qualification

CR-04 introduces no URLs, DNS, network, SourceCaptureStage execution, live owner context, M05 byte read, primary evidence write, model call, scheduler, notification, customer release or SQL migration. Existing legacy P01 raw/line comparison and application context factories are unchanged. Native owner read and current-rights revalidation must eventually be wired explicitly in RT-02 under HS-02, never invented from synthetic references.

Focused deterministic positive/refusal unit, security and foundation tests assert exact CR-02→OB-03 compositions, source/card mismatch rejection, forbidden provider flags and raw text absence. Real MariaDB and PHP syntax lanes remain mandatory in the package CI, but their synthetic success cannot qualify host, source licensing, human evaluation, model quality, cost efficiency or final V01–V11.
