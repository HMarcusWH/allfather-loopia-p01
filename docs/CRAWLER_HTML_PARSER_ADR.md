# CR-01 — HTML5 parser and bounded document projection ADR

**Prepared:** 28 October 2026. **Decision:** native `Dom\\HTMLDocument` for HTML5 living-standard parsing on PHP >=8.4, with **explicit unsupported-capability refusal** on PHP 8.2/8.3 and on hosts without ext-dom or mbstring. No HTML4 `DOMDocument::loadHTML` fallback, no additional Composer package, no remote parser, and no article extractor in this package. The PHP >=8.2 *codebase minimum* remains unchanged.

**Authority and claims:** CR-01 is a pure interpretation of independently supplied synthetic private evidence bytes. It does not implement live source rights, P01/M05 evidence retrieval, M10 release, customer views, outbound links or model inference. CR-04 owns future native P01/M05 binding; a caller-provided declaration will never authenticate a real source. The interface requires `SYNTHETIC_PRIVATE_EVIDENCE_FIXTURE` and `M05_PRIVATE_FIXTURE` and refuses a caller trying to change those to live rights.

## Version and exact input/output

`DocumentProjectionContract::VERSION = cr01.document-projection.v1`, parser `php-dom-html5-native.v1`, normalizer `cr01.utf8-whitespace.v1`, fixed limits `cr01.limits.v1`.

**Input `CapturedSourceEnvelope` subset:** exact tenant/workspace, registered source and source-card IDs, capture/evidence ID, source-card SHA-256, **raw byte SHA-256**, observed/known-at canonical UTC clocks, UTF-8 `text/html`, synthetic/private-retention flags, plus separately passed bytes. Both are required; missing, extra or cross-scope fields fail closed. The raw SHA-256 is of the *original bytes* and is **not** silently equated to the legacy canonical P01 URL+body hash or an M05 record.

**Output `DocumentProjection.v1`:** source/capture/card/evidence identities and raw digest, parser/normalizer/limits versions, ordered sections, warnings, node counts, and a digest of the derived projection. Every section has a type from the closed vocabulary (NAVIGATION, PRICING, PRODUCT_SERVICE, CAREERS, NEWS_PRESS, STRUCTURED_META, FOOTER_LEGAL, UNCLASSIFIED), an ordered DOM path, immutable capture-derived origin digest, **source-card and raw-source-byte digests**, text digest, bounded normalized excerpt, optional order-sensitive navigation-link digests, truncation state, and explicit **null raw byte start/length with `source_offset_verified=false`**. No derived DOM locator or repaired HTML claims an original raw source span.

## Backend and deployment

Official PHP documentation says `Dom\\HTMLDocument::createFromString` implements the HTML living-standard parser from **PHP 8.4+**. PHP's legacy `DOMDocument::loadHTML` is an HTML4 parser whose behavior differs on modern markup. See:

- https://www.php.net/manual/en/dom-htmldocument.createfromstring.php
- https://www.php.net/manual/en/domdocument.loadhtml.php

Railway Docker image is currently `php:8.5.12-apache-bookworm`; this candidate change explicitly builds ext-dom using `libxml2-dev` and confirms parser availability and native **positive**/refusal fixtures during image build. A second, independent GitHub Actions `native HTML5 PHP 8.5` job tests the actual positive parsing path. Existing mandatory GitHub code qualification remains PHP 8.2 and MUST prove a **governed parser-unavailable refusal**, not a fake parse PASS. Both lanes are needed before merging CR-01.

For actual Loopia PHP 8.2 shared hosting, CR-01 reports `CR01_HTML5_PARSER_CAPABILITY_UNSUPPORTED` after verifying signed-input structure and bytes. It cannot be promoted as a live HTML5 parser there without a separately reviewed, locked compatible implementation and a new parity test/approval. No PHP floor is silently raised by `composer.json`.

## Finite limits and known residual risks

```text
input bytes                    131072
markup openers                  3500
DOM elements                    5000
DOM depth                         48
DOM attributes                  6000
typed sections                    64
text nodes per section          1000
normalized text bytes          4096
excerpt bytes                    256
ordered navigation items          48
elapsed parser budget (ms)      1500
```

All are pinned source constants and have negative fixture coverage. The native HTML5 parser explicitly **does not accept LIBXML_NONET**; its factory parses provided bytes in memory and makes no network requests. The only accepted options are its own limited HTML5 parse options, and we use **0**. Existing P01 EgressGuard is unchanged. The elapsed-time check is **post-parse and periodic**, not an OS-enforced wall-clock preemption; native parser memory spikes within the finite input budget are not a formally bounded allocation guarantee. HTML5 error correction is not an auditable record of repairs; warnings state `HTML5_REPAIR_DETAILS_UNVERIFIED`. Only declared UTF-8 is accepted, invalid bytes and conflicting charsets refuse; foreign namespace/active elements and scripts are omitted. No remote resource loading, secondary HTTP dispatch, style/script execution or XPath/external entity evaluation is installed. External DTD declarations are refused. No unsupported libxml network options are passed, and the parser has no network I/O.

## Synthetic acceptance and final verification

Run:
```sh
php tests/run.php --layer=foundation --filter=cr01_html5_boundary --strict
php tests/run.php --layer=unit --filter=cr01_html5_projection --strict
php tests/run.php --layer=security --filter=cr01_html5_security --strict
```

The native 8.5 lane additionally requires **at least one real successful parsed HTML5 projection** with every section family, navigation-order stability, repaired markup warning, namespace/script exclusion and 64-section refusal. The PHP 8.2 lane proves the parser correctly refuses rather than falling back. Positive fixture source rights are synthetic-only, not a customer host observation.

Schema changes **none**; persistence and primary source bytes remain with M05. CR-02 will consume typed, bounded derived sections for semantic delta. Final end-to-end verified provenance, host recovery, authorized source reads and customer release remain V01/V03/V04 and later RT-02/V08/V11 gates.
