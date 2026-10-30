# UI-02 / BUILD-25 — Historical review workbench, revision-locked commands, Plus46 read journey

**Prepared 30 October 2026.** Dependency set: UI-01, AN-02, AN-03, AN-04, AQ-02 on isolated `build/allfather-php-feature-complete-v2`. This is an inert source implementation requiring its own exact hosted CI and reviewed integration merge. **U03, U06, U07, U09 remain unadopted**. No production route, provider, source fetch, real human review, migration, Railway promotion or customer output is activated.

## 1. Existing owner reads, not duplicate stores

The existing owner-scoped ConsoleReadController and HTTP data-source already expose separate P01 released battlecards and source watchlist, P02 own-company drafts/entity and relationship snapshots, P03 declared ICP/customer offers/prospect queue, P04 role distribution, P05 internal drafts, and M10 decisions. UI-02 provides a versioned read-journey inventory that distinguishes owner-read-wired from unavailable/denied. Missing permission does **not** create dummy records or mean no product intelligence exists.

Plus46's current TEST memberships and source records are not modified by this package. No new membership, trial, account-bootstrap, first-login credential or personal profile is manufactured. Account 2 fresh-login acceptance remains a dedicated host/browser check with a separately existing owner-approved TEST operator.

## 2. Historical review read

`HistoryReviewAdapter.v1` takes authenticated session tenant/workspace/operator identity, strict competitor+question selectors, bounded page (1–50) and limit (1–10). It verifies exact AN-03 NativeReleaseProposal, AN-04 OutcomeAnnotation and AQ-02 InternalAttention contracts at the same scope and lineage; only then projects a closed safe metadata list: frozen basis and finding digest, knowledge cutoff, analyst and critic IDs, complete selected source/evidence/snapshot IDs, true SUPPORT/CONTRARY polarity, review HOLD, reported outcome, attention/suppression, unknown due, and exact versioned revision digest.

The read cannot expose raw source text, model prompt, provider response, copied credentials, source URL, another workspace's record, unapproved business finding or customer projection. UNKNOWN/UNAVAILABLE/DENIED/NOT_FOUND stay distinct. The owner-read port is implemented positively **only** by `tests/support/Ui02FixtureReviewOwner.php` under CLI, the explicit test marker, exact owner class and file path. `GET` has no business mutation (existing authentication/session or request audit bookkeeping is a separate pre-existing rule).

## 3. Commands and exact review revision

`HistoryReviewCommandCandidate.v1` supports only `REQUEST_HUMAN_REVIEW`, `DEFER_REVIEW`, `ACKNOWLEDGE_CANDIDATE`, with closed reason codes. A proposed plan must bind the signed-in session actor+scope, original basis/finding, exact `expected_review_revision`, action, reason, and `plan_digest`.

The scriptless `HistoryReviewController` has two explicit methods:
- `GET /console/history-review?competitor=...&question=...&page=...`: typed metadata read only.
- `POST /console/history-review`: `command_mode=plan` previews a digest; `command_mode=propose` requires exact reviewed revision, full plan digest, and CSRF matched to session. Form contains no tenant/workspace/operator selector.

The shared Kernel already enforces CSRF for all unsafe requests; controller checks again to protect future direct call sites. Stale revision, changed reason, missing CSRF, unknown role/owner, or foreign scope refuses **before** the test-only in-memory proposal. Identical proposals replay idempotently. This fixture work is not an actual M07/ M10 decision, a true human identity attestation, source use, schedule, provider or notification.

**Neither method is registered in the live Kernel/React route table until the owning M02/P01/M05/M07/M10 route/factory integration has separately adopted the U03/U06/U07/U09 decisions.** An unregistered source implementation must never be mistaken for a deployed feature. A genuine positive runtime path remains CONTRACT_BLOCKED/OWNER_PENDING; it cannot be fabricated with a stub returning success.

## 4. Browser and accessibility

`history-review.php` is server-rendered under existing strict no-script CSP with `View::e()`, labelled form controls, a bounded table of contrary evidence, support and critic states, and a form for exact reviewed plan. The unregistered React `HistoryReviewWorkbenchPreview` uses the safe `projectHistoryWorkbench` model; full-page source bodies or HTML never enter. It provides readable hierarchy, text-based states, semantic lists/details, labelled pagination and the already-wired P01–P05/M10 owner read journey.

Required deferred real-host acceptance: desktop at 1280×720, mobile at 390×844, keyboard focus and Enter/Space forms, no horizontal overflow, session-scoped Plus46 profile and a second distinct TEST operator fresh login, denied/empty/unknown/suspended states, no GET business changes, CSRF/refusal and stale-digest behavior, no raw credentials or source content in DOM or network, support references, no customer release. These are **not** CI assertions or claims of completed browser acceptance.

## 5. Test and owner boundaries

Positive synthetic fixture and negative tests cover exact source/ref polarity, incompatible scopes, forged current rights, CSRF, stale revision/digest, in-memory idempotency, HTML escaping, bounded paging, conservative product-read states, no native M10/P04/product writes on a disposable MariaDB, and static no-live-routing or dispatch. Frontend Node view-model tests and existing full build remain required.

Final implementation and authorization boundaries: real owner-backed POST M07/M10 human review and UI routing, true source rights, native M16/P04 release, actual Plus46 second-account acceptance, full RT-02 integration and V01–V11 qualification remain distinct and not admitted here.
