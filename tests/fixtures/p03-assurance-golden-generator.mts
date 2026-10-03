// P03 assurance parity vectors (P08).
//
// Run from a canonical checkout AT THE PINNED COMMIT, after building @allfather/substrate-core:
//
//   pnpm --filter @allfather/substrate-core build
//   CANONICAL_COMMIT=<40-hex> TZ=UTC node --import tsx <loopia>/tests/fixtures/p03-assurance-golden-generator.mts <loopia>/tests/fixtures/p03_assurance_vectors.json
//
// TZ=UTC is required: canonical reads an offset-less stored instant in the host's local time zone.
//
// Every input is synthetic and DECLARATIVE: each case names the rows it needs (catalogue/card review
// state, capture and terms-review instants, signal status/age, queue-item trigger classes, profile
// category/status/scope). This script builds those rows in the canonical SQLite schema, runs the
// FROZEN canonical audit, and records what the canonical implementation actually wrote. The PHP
// conformance test rebuilds the SAME declared rows in MariaDB and must reproduce the same governed
// transcript. Expected values come from canonical code, never from the PHP port.
//
// Fixture rows are written directly rather than through the canonical catalogue projection, because
// that projection stamps its terms-review row with the wall clock and a parity vector must not depend
// on when it was generated. The audits under test only READ these rows.
import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const SOURCE = 'packages/allfather-control-substrate/src/p03/assurance.ts';
const imp = (p: string) => import(pathToFileURL(resolve(p)).href);
const { openAfbDatabase } = await imp('packages/allfather-control-substrate/src/db/open.ts');
const { seedDemoScope } = await imp('packages/allfather-control-substrate/src/m02/seed.ts');
const { createAuditProfile } = await imp('packages/allfather-control-substrate/src/m11/assurance.ts');
const { runCatalogueFreshnessAudit, runSignalPairingFreshnessAudit, runBuyerIntentInvariantAudit } = await imp(SOURCE);

const T0 = '2026-06-01T00:00:00.000Z';
const AS_OF = '2026-07-01T00:00:00.000Z';
const DAY = 86_400_000;

type Scope = 'home' | 'sibling' | 'foreign';
type ProfileSetup = { category: string; status?: string; scope?: Scope };
type CatalogueSetup = {
  scope?: 'home' | 'foreign';
  workspace?: 'home' | null;
  catalogue_status?: string;
  review_status?: string;
  review_expires_at?: string | null;
  error_after_ms?: number | null;
  recheck_terms_after_ms?: number | null;
  captures?: { captured_at: string; evidence_type?: string; other_source?: boolean }[];
  terms_reviews?: string[];
};
type HypothesisSetup = { scope?: Scope; signal_status?: string; signal_created_at?: string };
type QueueItemSetup = { label: string; scope?: Scope; signal_class: string; interpretation_kind: string; queue_status?: string };
type Case = {
  name: string;
  family: 'CATALOGUE_FRESHNESS' | 'SIGNAL_PAIRING_FRESHNESS' | 'BUYER_INTENT_INVARIANT';
  setup: { profile: ProfileSetup; catalogue?: CatalogueSetup | null; hypothesis?: HypothesisSetup | null; queue_items?: QueueItemSetup[] };
  input: Record<string, unknown>;
};

const cat = (over: Partial<CatalogueSetup> = {}): CatalogueSetup => ({ scope: 'home', workspace: 'home', catalogue_status: 'ACTIVE', review_status: 'approved', review_expires_at: null, error_after_ms: null, recheck_terms_after_ms: null, captures: [], terms_reviews: [], ...over });
const CF = (name: string, catalogue: CatalogueSetup | null, input: Record<string, unknown> = {}, profile: ProfileSetup = { category: 'CATALOGUE_FRESHNESS' }): Case => ({ name, family: 'CATALOGUE_FRESHNESS', setup: { profile, catalogue }, input: { asOf: AS_OF, ...input } });
const hyp = (over: Partial<HypothesisSetup> = {}): HypothesisSetup => ({ scope: 'home', signal_status: 'RELEASED', signal_created_at: '2026-06-30T00:00:00.000Z', ...over });
const SP = (name: string, hypothesis: HypothesisSetup | null, input: Record<string, unknown> = {}, profile: ProfileSetup = { category: 'SIGNAL_PAIRING_FRESHNESS' }): Case => ({ name, family: 'SIGNAL_PAIRING_FRESHNESS', setup: { profile, hypothesis }, input: { asOf: AS_OF, signalPairingFreshnessWindowMs: 7 * DAY, ...input } });
const BI = (name: string, queue_items: QueueItemSetup[], profile: ProfileSetup = { category: 'BUYER_INTENT_INVARIANT' }, input: Record<string, unknown> = {}): Case => ({ name, family: 'BUYER_INTENT_INVARIANT', setup: { profile, queue_items }, input: { asOf: AS_OF, ...input } });
const qi = (label: string, signal_class: string, interpretation_kind: string, over: Partial<QueueItemSetup> = {}): QueueItemSetup => ({ label, scope: 'home', signal_class, interpretation_kind, queue_status: 'OPEN', ...over });

const CASES: Case[] = [
  // ---------------------------------------------------------------- CATALOGUE_FRESHNESS
  CF('catalogue-fresh-passes', cat()),
  CF('catalogue-suspended', cat({ catalogue_status: 'SUSPENDED' })),
  CF('catalogue-retired-and-review-expired', cat({ catalogue_status: 'RETIRED', review_status: 'expired' })),
  CF('catalogue-review-draft', cat({ review_status: 'draft' })),
  CF('catalogue-review-blocked', cat({ review_status: 'blocked' })),
  CF('catalogue-review-past-expiry', cat({ review_expires_at: '2026-06-15T00:00:00.000Z' })),
  CF('catalogue-review-expiry-in-future', cat({ review_expires_at: '2026-08-01T00:00:00.000Z' })),
  CF('catalogue-error-window-never-captured', cat({ error_after_ms: DAY })),
  CF('catalogue-error-window-stale-capture', cat({ error_after_ms: DAY, captures: [{ captured_at: T0 }] })),
  CF('catalogue-error-window-latest-capture-fresh', cat({ error_after_ms: DAY, captures: [{ captured_at: '2026-01-01T00:00:00.000Z' }, { captured_at: '2026-06-30T12:00:00.000Z' }] })),
  CF('catalogue-capture-age-equal-to-window-is-fresh', cat({ error_after_ms: DAY, captures: [{ captured_at: '2026-06-30T00:00:00.000Z' }] })),
  CF('catalogue-caller-window-overrides-card', cat({ error_after_ms: DAY, captures: [{ captured_at: '2026-06-20T00:00:00.000Z' }] }), { freshnessWindowMs: 30 * DAY }),
  CF('catalogue-caller-window-without-card-window', cat({ captures: [{ captured_at: T0 }] }), { freshnessWindowMs: DAY }),
  CF('catalogue-non-upload-capture-ignored', cat({ error_after_ms: DAY, captures: [{ captured_at: '2026-06-30T12:00:00.000Z', evidence_type: 'TEST_FIXTURE_EVIDENCE' }] })),
  CF('catalogue-other-source-capture-ignored', cat({ error_after_ms: DAY, captures: [{ captured_at: '2026-06-30T12:00:00.000Z', other_source: true }] })),
  CF('catalogue-terms-recheck-never-reviewed', cat({ recheck_terms_after_ms: 30 * DAY })),
  CF('catalogue-terms-recheck-recent', cat({ recheck_terms_after_ms: 30 * DAY, terms_reviews: ['2026-01-01T00:00:00.000Z', '2026-06-20T00:00:00.000Z'] })),
  CF('catalogue-terms-recheck-overdue', cat({ recheck_terms_after_ms: 30 * DAY, terms_reviews: ['2026-01-01T00:00:00.000Z'] })),
  CF('catalogue-every-reason-in-order', cat({ catalogue_status: 'SUSPENDED', review_status: 'draft', review_expires_at: '2026-06-15T00:00:00.000Z', error_after_ms: DAY, recheck_terms_after_ms: DAY })),
  CF('catalogue-operator-requested', cat({ catalogue_status: 'SUSPENDED' }), { requestedByActorType: 'OPERATOR' }),
  CF('catalogue-tenant-level-from-sibling-workspace', cat({ workspace: null }), {}, { category: 'CATALOGUE_FRESHNESS', scope: 'sibling' }),
  // Pins CURRENT canonical behaviour, recorded as an upstream finding rather than repaired locally: the
  // catalogue audit compares tenant only, so a workspace-OWNED catalogue is auditable from a sibling
  // workspace's profile. A canonical repair regenerates this vector and the port follows it.
  CF('catalogue-workspace-owned-from-sibling-workspace', cat({ catalogue_status: 'SUSPENDED' }), {}, { category: 'CATALOGUE_FRESHNESS', scope: 'sibling' }),
  // Stored instants are read with canonical `Date.parse`, so each ES date-time-string form a CHAR(24)
  // column can hold is pinned: date-only and year-month forms are UTC, `T24:00` is the next midnight,
  // a day past month end rolls over, extra fraction digits are truncated, `:60` seconds are NaN (an
  // unreadable expiry or capture never fires; an unreadable terms review fails closed). An
  // offset-less date-time is HOST-LOCAL in canonical; these vectors are generated with TZ=UTC, and the
  // host dependence is recorded upstream (UF-005) rather than reproduced.
  CF('catalogue-stored-expiry-date-only-past', cat({ review_expires_at: '2026-06-15' })),
  CF('catalogue-stored-expiry-date-only-future', cat({ review_expires_at: '2026-08-01' })),
  CF('catalogue-stored-expiry-year-month', cat({ review_expires_at: '2026-06' })),
  CF('catalogue-stored-expiry-day-rollover', cat({ review_expires_at: '2026-02-30' })),
  CF('catalogue-stored-expiry-hour-24', cat({ review_expires_at: '2026-06-14T24:00Z' })),
  CF('catalogue-stored-expiry-leap-second-unreadable', cat({ review_expires_at: '2026-06-15T23:59:60Z' })),
  CF('catalogue-stored-expiry-garbage-unreadable', cat({ review_expires_at: 'not-a-date' })),
  CF('catalogue-stored-capture-offsetless-utc-host', cat({ error_after_ms: DAY, captures: [{ captured_at: '2026-06-29T00:00:00' }] })),
  CF('catalogue-stored-capture-short-fraction', cat({ error_after_ms: DAY, captures: [{ captured_at: '2026-06-30T12:00:00.5Z' }] })),
  CF('catalogue-stored-capture-minute-offset', cat({ error_after_ms: DAY, captures: [{ captured_at: '2026-06-30T12:00+02:00' }] })),
  CF('catalogue-stored-capture-unreadable', cat({ error_after_ms: DAY, captures: [{ captured_at: 'garbage' }] })),
  CF('catalogue-stored-terms-review-date-only', cat({ recheck_terms_after_ms: 30 * DAY, terms_reviews: ['2026-06-20'] })),
  CF('catalogue-stored-terms-review-unreadable', cat({ recheck_terms_after_ms: 30 * DAY, terms_reviews: ['garbage'] })),
  // Discriminating vectors: each verdict flips if the instant is read any other way — the offset
  // ignored, the milliseconds dropped (strtotime), or a non-ES string read by a lenient parser.
  CF('catalogue-stored-capture-offset-decides', cat({ error_after_ms: DAY, captures: [{ captured_at: '2026-06-30T01:00+02:00' }] })),
  CF('catalogue-stored-capture-milliseconds-decide', cat({ error_after_ms: DAY + 600, captures: [{ captured_at: '2026-06-29T23:59:59.5Z' }] })),
  CF('catalogue-stored-terms-review-milliseconds-decide', cat({ recheck_terms_after_ms: 30 * DAY + 600, terms_reviews: ['2026-05-31T23:59:59.5Z'] })),
  CF('catalogue-stored-terms-review-iso-week-unreadable', cat({ recheck_terms_after_ms: 30 * DAY, terms_reviews: ['2026W26'] })),
  CF('catalogue-stored-terms-review-beyond-timeclip', cat({ recheck_terms_after_ms: 30 * DAY, terms_reviews: ['+275761-01-01'] })),
  CF('catalogue-stored-terms-review-hour-24-fraction', cat({ recheck_terms_after_ms: 30 * DAY, terms_reviews: ['2026-06-14T24:00:00.0001'] })),
  CF('catalogue-stored-expiry-before-timeclip', cat({ review_expires_at: '-271822-01-01' })),
  CF('catalogue-stored-capture-before-timeclip', cat({ error_after_ms: DAY, captures: [{ captured_at: '-271822-01-01' }] })),
  // "Latest" is SQLite BINARY string order: '…12:00:00Z' sorts after '…12:00+02:00' (':' > '+'),
  // although the latter is earlier as an instant read in a different order. Judged at 11:00Z the
  // next day, the row chosen decides the verdict.
  CF('catalogue-latest-capture-is-binary-string-order', cat({ error_after_ms: DAY, captures: [{ captured_at: '2026-06-30T12:00:00Z' }, { captured_at: '2026-06-30T12:00+02:00' }] }), { asOf: '2026-07-01T11:00:00.000Z' }),
  CF('catalogue-latest-terms-review-is-binary-string-order', cat({ recheck_terms_after_ms: 30 * DAY, terms_reviews: ['2026-06-01T12:00:00Z', '2026-06-01T12:00+02:00'] }), { asOf: '2026-07-01T11:00:00.000Z' }),
  CF('catalogue-wrong-category-profile', cat(), {}, { category: 'SIGNAL_PAIRING_FRESHNESS' }),
  CF('catalogue-inactive-profile', cat(), {}, { category: 'CATALOGUE_FRESHNESS', status: 'SUSPENDED' }),
  CF('catalogue-target-missing', null),
  CF('catalogue-foreign-tenant', cat({ scope: 'foreign' })),

  // ---------------------------------------------------------------- SIGNAL_PAIRING_FRESHNESS
  SP('pairing-released-in-window-passes', hyp()),
  SP('pairing-stale', hyp({ signal_created_at: T0 })),
  SP('pairing-age-equal-to-window-is-fresh', hyp({ signal_created_at: '2026-06-24T00:00:00.000Z' })),
  SP('pairing-signal-superseded', hyp({ signal_status: 'SUPERSEDED' })),
  SP('pairing-signal-withdrawn-and-stale', hyp({ signal_status: 'WITHDRAWN', signal_created_at: T0 })),
  SP('pairing-operator-requested', hyp({ signal_status: 'REJECTED' }), { requestedByActorType: 'OPERATOR' }),
  SP('pairing-sibling-workspace', hyp({ scope: 'sibling' })),
  SP('pairing-foreign-tenant', hyp({ scope: 'foreign' })),
  SP('pairing-target-missing', null),
  SP('pairing-stored-created-date-only-stale', hyp({ signal_created_at: '2026-06-01' })),
  SP('pairing-stored-created-date-only-fresh', hyp({ signal_created_at: '2026-06-30' })),
  SP('pairing-stored-created-unreadable', hyp({ signal_created_at: '2026W01' })),
  SP('pairing-stored-created-milliseconds-decide', hyp({ signal_created_at: '2026-06-23T23:59:59.5Z' }), { signalPairingFreshnessWindowMs: 7 * DAY + 600 }),
  SP('pairing-stored-created-before-timeclip', hyp({ signal_created_at: '-271822-01-01' })),
  SP('pairing-wrong-category-profile', hyp(), {}, { category: 'BUYER_INTENT_INVARIANT' }),
  SP('pairing-inactive-profile', hyp(), {}, { category: 'SIGNAL_PAIRING_FRESHNESS', status: 'REVOKED' }),

  // ---------------------------------------------------------------- BUYER_INTENT_INVARIANT
  BI('buyer-intent-empty-scope-passes', []),
  BI('buyer-intent-every-trigger-class-fact-passes', [
    qi('pricing', 'PRICING_CHANGE_SIGNAL', 'FACT_OBSERVED'),
    qi('launch', 'PRODUCT_LAUNCH_SIGNAL', 'FACT_OBSERVED'),
    qi('hiring', 'HIRING_OR_EXPANSION_SIGNAL', 'FACT_OBSERVED'),
    qi('regulatory', 'REGULATORY_OR_COMPLIANCE_SIGNAL', 'FACT_OBSERVED'),
  ]),
  BI('buyer-intent-class-and-interpretation-violations', [
    qi('ok', 'PRICING_CHANGE_SIGNAL', 'FACT_OBSERVED'),
    qi('bad-class', 'MARKET_CONTEXT_SIGNAL', 'FACT_OBSERVED'),
    qi('bad-interpretation', 'PRICING_CHANGE_SIGNAL', 'HYPOTHESIS'),
  ]),
  BI('buyer-intent-every-non-trigger-class', [
    qi('positioning', 'POSITIONING_CHANGE_SIGNAL', 'FACT_OBSERVED'),
    qi('campaign', 'CAMPAIGN_CHANGE_SIGNAL', 'FACT_OBSERVED'),
    qi('partnership', 'PARTNERSHIP_SIGNAL', 'FACT_OBSERVED'),
    qi('negative-feedback', 'NEGATIVE_FEEDBACK_SIGNAL', 'FACT_OBSERVED'),
  ]),
  BI('buyer-intent-closed-queue-items-still-scanned', [qi('withdrawn', 'MARKET_CONTEXT_SIGNAL', 'HYPOTHESIS', { queue_status: 'EXIT_WITHDRAWN' })]),
  BI('buyer-intent-out-of-scope-items-ignored', [
    qi('home-ok', 'PRODUCT_LAUNCH_SIGNAL', 'FACT_OBSERVED'),
    qi('sibling-bad', 'MARKET_CONTEXT_SIGNAL', 'HYPOTHESIS', { scope: 'sibling' }),
    qi('foreign-bad', 'MARKET_CONTEXT_SIGNAL', 'HYPOTHESIS', { scope: 'foreign' }),
  ]),
  BI('buyer-intent-operator-requested', [qi('bad', 'PARTNERSHIP_SIGNAL', 'FACT_OBSERVED')], { category: 'BUYER_INTENT_INVARIANT' }, { requestedByActorType: 'OPERATOR' }),
  BI('buyer-intent-inactive-profile', [qi('bad', 'PARTNERSHIP_SIGNAL', 'HYPOTHESIS')], { category: 'BUYER_INTENT_INVARIANT', status: 'SUSPENDED' }),
  BI('buyer-intent-wrong-category-profile', [], { category: 'CATALOGUE_FRESHNESS' }),
];

// ------------------------------------------------------------------ fixture construction

let counter = 0;
const id = (prefix: string) => `${prefix}-${String(++counter).padStart(4, '0')}-0000-0000-0000-000000000000`.slice(0, 36);

function scopes(db: any) {
  const home = seedDemoScope(db);
  db.prepare(`INSERT INTO workspaces (workspace_id, tenant_id, workspace_key, schema_version, display_name, workspace_type, environment_class, active_v1_capability_ceiling, status, created_at) VALUES (?, ?, 'sibling', '1', 'Sibling', 'TEST', 'TEST', 'READ_ONLY', 'ACTIVE', ?)`).run('sibling-ws', home.tenantId, T0);
  db.prepare(`INSERT INTO tenants (tenant_id, tenant_key, schema_version, display_name, tenant_type, status, created_at) VALUES ('foreign-tenant', 'foreign', '1', 'Foreign', 'TEST', 'ACTIVE', ?)`).run(T0);
  db.prepare(`INSERT INTO workspaces (workspace_id, tenant_id, workspace_key, schema_version, display_name, workspace_type, environment_class, active_v1_capability_ceiling, status, created_at) VALUES ('foreign-ws', 'foreign-tenant', 'foreign', '1', 'Foreign', 'TEST', 'TEST', 'READ_ONLY', 'ACTIVE', ?)`).run(T0);
  return {
    home: { tenant: home.tenantId as string, workspace: home.workspaceId as string },
    sibling: { tenant: home.tenantId as string, workspace: 'sibling-ws' },
    foreign: { tenant: 'foreign-tenant', workspace: 'foreign-ws' },
  };
}

function catalogueRows(db: any, s: { tenant: string; workspace: string }, c: CatalogueSetup, labels: Map<string, string>) {
  const sourceRefId = id('srcref');
  const cardId = id('card');
  const catalogueId = id('catalogue');
  db.prepare(`INSERT INTO source_references (source_ref_id, schema_version, tenant_id, workspace_id, source_type, lawful_or_permitted_use_scope, content_sensitivity_class, status, registered_at) VALUES (?, '1', ?, NULL, 'UPLOADED_ARTIFACT', 'customer_owned_tenant_scoped/internal_fit_matching', 'INTERNAL_DEMO', 'ACTIVE_FOR_CAPTURE', ?)`).run(sourceRefId, s.tenant, T0);
  db.prepare(`INSERT INTO source_license_cards (source_license_card_id, schema_version, tenant_id, source_ref_id, source_name, source_type, allowed_use_class, license_class, robots_policy_checked, terms_policy_checked, pii_risk_class, sensitive_data_risk_class, review_status, approved_by, approved_at, review_expires_at, error_after_ms, recheck_terms_after_ms, data_ownership, processing_purpose, created_at) VALUES (?, '1', ?, ?, 'Synthetic catalogue', 'customer_upload', 'customer_owned_tenant_scoped', 'LICENSED_INTERNAL_ONLY', 1, 1, 'low', 'low', ?, 'synthetic-approver', ?, ?, ?, ?, 'TENANT_OWNED', 'internal fit-matching', ?)`).run(cardId, s.tenant, sourceRefId, c.review_status, T0, c.review_expires_at ?? null, c.error_after_ms ?? null, c.recheck_terms_after_ms ?? null, T0);
  db.prepare(`INSERT INTO customer_product_catalogues (customer_product_catalogue_id, schema_version, tenant_id, workspace_id, catalogue_key, display_name, source_ref_id, source_license_card_id, catalogue_status, created_at) VALUES (?, '1', ?, ?, ?, 'Synthetic catalogue', ?, ?, ?, ?)`).run(catalogueId, s.tenant, c.workspace === null ? null : s.workspace, catalogueId, sourceRefId, cardId, c.catalogue_status, T0);
  let otherSourceRefId: string | null = null;
  for (const capture of c.captures ?? []) {
    let ref = sourceRefId;
    if (capture.other_source) {
      otherSourceRefId ??= id('srcref');
      if (ref !== otherSourceRefId) {
        db.prepare(`INSERT OR IGNORE INTO source_references (source_ref_id, schema_version, tenant_id, workspace_id, source_type, lawful_or_permitted_use_scope, content_sensitivity_class, status, registered_at) VALUES (?, '1', ?, NULL, 'UPLOADED_ARTIFACT', 'customer_owned_tenant_scoped/internal_fit_matching', 'INTERNAL_DEMO', 'ACTIVE_FOR_CAPTURE', ?)`).run(otherSourceRefId, s.tenant, T0);
      }
      ref = otherSourceRefId;
    }
    db.prepare(`INSERT INTO evidence_objects (evidence_id, schema_version, tenant_id, workspace_id, source_ref_id, evidence_type, captured_content_reference_safe, content_hash, capture_method, captured_at, permitted_use_scope, evidence_status, sensitivity_class) VALUES (?, '1', ?, ?, ?, ?, 'synthetic://catalogue', ?, 'UPLOAD', ?, 'customer_owned_tenant_scoped/internal_fit_matching', 'CAPTURED', 'INTERNAL_DEMO')`).run(id('evidence'), s.tenant, s.workspace, ref, capture.evidence_type ?? 'UPLOADED_SOURCE_CAPTURE', 'c'.repeat(64), capture.captured_at);
  }
  for (const reviewedAt of c.terms_reviews ?? []) {
    db.prepare(`INSERT INTO source_terms_reviews (source_terms_review_id, schema_version, source_license_card_id, robots_policy_checked, terms_policy_checked, reviewed_by, reviewed_at) VALUES (?, '1', ?, 1, 1, 'synthetic-reviewer', ?)`).run(id('terms'), cardId, reviewedAt);
  }
  labels.set(catalogueId, 'catalogue');
  return { catalogueId };
}

function signalRow(db: any, s: { tenant: string; workspace: string }, signalClass: string, interpretation: string, status: string, createdAt: string) {
  const competitorId = id('competitor');
  db.prepare(`INSERT INTO competitors (competitor_id, schema_version, tenant_id, workspace_id, display_name, competitor_class, status, created_at) VALUES (?, '1', ?, ?, 'Synthetic rival', 'DIRECT', 'ACTIVE', ?)`).run(competitorId, s.tenant, s.workspace, T0);
  const signalId = id('signal');
  db.prepare(`INSERT INTO company_signals (company_signal_id, schema_version, tenant_id, workspace_id, competitor_id, signal_class, interpretation_kind, confidence_band, summary_safe, status, created_at) VALUES (?, '1', ?, ?, ?, ?, ?, 'HIGH', 'Synthetic signal', ?, ?)`).run(signalId, s.tenant, s.workspace, competitorId, signalClass, interpretation, status, createdAt);
  return signalId;
}

function hypothesisRows(db: any, s: { tenant: string; workspace: string }, h: HypothesisSetup, labels: Map<string, string>) {
  const { catalogueId } = catalogueRows(db, s, cat({ workspace: 'home' }), new Map());
  const productId = id('product');
  db.prepare(`INSERT INTO customer_products (customer_product_id, schema_version, tenant_id, customer_product_catalogue_id, product_key, display_name, product_offer_kind, attributes_json, product_status, created_at) VALUES (?, '1', ?, ?, 'p1', 'Synthetic product', 'PRODUCT', '[]', 'ACTIVE', ?)`).run(productId, s.tenant, catalogueId, T0);
  const signalId = signalRow(db, s, 'PRICING_CHANGE_SIGNAL', 'FACT_OBSERVED', h.signal_status!, h.signal_created_at!);
  const fitHypothesisId = id('fit');
  db.prepare(`INSERT INTO fit_hypotheses (fit_hypothesis_id, schema_version, tenant_id, workspace_id, customer_product_id, company_signal_id, fit_band, claim_kind, uncertainty_explicit, fit_assessment_digest, status, created_at) VALUES (?, '1', ?, ?, ?, ?, 'PROBABLE', 'REASON_TO_INVESTIGATE', 1, ?, 'ADMITTED', ?)`).run(fitHypothesisId, s.tenant, s.workspace, productId, signalId, 'd'.repeat(64), T0);
  labels.set(fitHypothesisId, 'fit_hypothesis');
  labels.set(signalId, 'signal');
  return { fitHypothesisId };
}

function queueItem(db: any, s: { tenant: string; workspace: string }, q: QueueItemSetup, labels: Map<string, string>) {
  const signalId = signalRow(db, s, q.signal_class, q.interpretation_kind, 'RELEASED', T0);
  const itemId = id('queue');
  // Mirrors the canonical test's injection: a queue item that bypassed the cp5 flow and the M08 gate,
  // with synthetic hypothesis/verdict ids, is exactly what this defence-in-depth audit must catch.
  db.exec('PRAGMA foreign_keys = OFF;');
  db.prepare(`INSERT INTO priority_outreach_queue_items (priority_outreach_queue_item_id, schema_version, tenant_id, workspace_id, fit_hypothesis_id, triggering_company_signal_id, arbiter_verdict_id, queue_status, created_at) VALUES (?, '1', ?, ?, 'synthetic-fit-hypothesis', ?, 'synthetic-arbiter-verdict', ?, ?)`).run(itemId, s.tenant, s.workspace, signalId, q.queue_status, T0);
  db.exec('PRAGMA foreign_keys = ON;');
  labels.set(itemId, `queue:${q.label}`);
}

const AUDITED_TABLES = ['customer_product_catalogues', 'source_license_cards', 'source_references', 'evidence_objects', 'source_terms_reviews', 'company_signals', 'fit_hypotheses', 'priority_outreach_queue_items', 'customer_products', 'competitors', 'audit_profiles'];
function auditedDigest(db: any): string {
  const h = createHash('sha256');
  for (const t of AUDITED_TABLES) {
    h.update(t);
    for (const row of db.prepare(`SELECT * FROM ${t} ORDER BY 1`).all()) h.update(JSON.stringify(row));
  }
  return h.digest('hex');
}

const parse = (s: unknown) => JSON.parse(String(s));
const relabel = (labels: Map<string, string>) => (v: unknown) => (typeof v === 'string' && labels.has(v) ? labels.get(v)! : v);

function runCase(c: Case) {
  const ordered = (refs: unknown[]) => (c.family === 'BUYER_INTENT_INVARIANT' ? [...refs].sort() : refs);
  counter = 0;
  const afb = openAfbDatabase();
  const db = afb.db;
  const s = scopes(db);
  const labels = new Map<string, string>();
  const profileScope = s[c.setup.profile.scope ?? 'home'];
  const profileId = createAuditProfile(db, { tenantId: profileScope.tenant, workspaceId: profileScope.workspace, profileKey: `p03-${c.setup.profile.category}`, auditCategory: c.setup.profile.category });
  if (c.setup.profile.status && c.setup.profile.status !== 'ACTIVE') {
    db.prepare(`UPDATE audit_profiles SET status = ? WHERE audit_profile_id = ?`).run(c.setup.profile.status, profileId);
  }
  labels.set(profileId, 'profile');

  let result: any;
  const requester = c.input.requestedByActorType ? { requestedByActorType: c.input.requestedByActorType } : {};
  const before = { digest: '' };
  if (c.family === 'CATALOGUE_FRESHNESS') {
    const target = c.setup.catalogue ? catalogueRows(db, s[c.setup.catalogue.scope ?? 'home'], c.setup.catalogue, labels).catalogueId : 'missing-catalogue';
    before.digest = auditedDigest(db);
    result = runCatalogueFreshnessAudit(db, { auditProfileId: profileId, customerProductCatalogueId: target, asOf: c.input.asOf, ...(c.input.freshnessWindowMs !== undefined ? { freshnessWindowMs: c.input.freshnessWindowMs } : {}), ...requester });
  } else if (c.family === 'SIGNAL_PAIRING_FRESHNESS') {
    const target = c.setup.hypothesis ? hypothesisRows(db, s[c.setup.hypothesis.scope ?? 'home'], c.setup.hypothesis, labels).fitHypothesisId : 'missing-fit-hypothesis';
    before.digest = auditedDigest(db);
    result = runSignalPairingFreshnessAudit(db, { auditProfileId: profileId, fitHypothesisId: target, signalPairingFreshnessWindowMs: c.input.signalPairingFreshnessWindowMs, asOf: c.input.asOf, ...requester });
  } else {
    for (const q of c.setup.queue_items ?? []) queueItem(db, s[q.scope ?? 'home'], q, labels);
    before.digest = auditedDigest(db);
    result = runBuyerIntentInvariantAudit(db, { auditProfileId: profileId, asOf: c.input.asOf, ...requester });
  }
  const audited_rows_unchanged = auditedDigest(db) === before.digest;
  const r = relabel(labels);

  const counts = Object.fromEntries(['audit_runs', 'audit_check_records', 'audit_findings', 'incident_evaluation_requests', 'incident_cases'].map((t) => [t, (db.prepare(`SELECT COUNT(*) AS n FROM ${t}`).get() as any).n as number]));
  const expected: Record<string, unknown> = { outcome: result.outcome, audited_rows_unchanged, row_counts: counts };
  if (result.outcome === 'REJECTED') {
    expected.reason_code = result.reasonCode;
  } else {
    const run = db.prepare(`SELECT * FROM audit_runs WHERE audit_run_id = ?`).get(result.auditRunId) as any;
    const check = db.prepare(`SELECT * FROM audit_check_records WHERE audit_check_record_id = ?`).get(result.auditCheckRecordId) as any;
    const finding = db.prepare(`SELECT * FROM audit_findings WHERE audit_finding_id = ?`).get(result.auditFindingId) as any;
    expected.finding_type = result.findingType;
    expected.failed = c.family === 'BUYER_INTENT_INVARIANT' ? result.violationCount > 0 : result.failed;
    if (c.family === 'BUYER_INTENT_INVARIANT') {
      expected.violation_count = result.violationCount;
      expected.violating_queue_items = [...result.violatingQueueItemIds].map(r).sort();
    } else {
      expected.reason_codes = result.reasonCodes;
    }
    expected.audit_run = {
      audit_profile_id: r(run.audit_profile_id), audit_category: run.audit_category,
      // Canonical's order is deterministic for the per-target families (and the M11 timeline cites
      // the first ref), so it is kept; buyer-intent violators come back in no defined order.
      target_object_refs: ordered(parse(run.target_object_refs).map(r)), requested_by_actor_type: run.requested_by_actor_type,
      status: run.status, summary: run.summary, scope: run.tenant_id === s.home.tenant && run.workspace_id === profileScope.workspace ? 'profile' : 'OTHER',
    };
    expected.check = { check_key: check.check_key, decision: check.decision, reason_codes: parse(check.reason_codes), severity_if_failed: check.severity_if_failed, safe_summary: check.safe_summary };
    expected.finding = {
      finding_type: finding.finding_type, finding_status: finding.finding_status, severity: finding.severity,
      confidence_classification: finding.confidence_classification, current_reliance_consequence: finding.current_reliance_consequence,
      safe_finding_summary: finding.safe_finding_summary, reason_codes: parse(finding.reason_codes),
      incident_evaluation_required: finding.incident_evaluation_required, decision_ledger_record_required: finding.decision_ledger_record_required,
    };
    if (result.incidentCaseId) {
      const kase = db.prepare(`SELECT * FROM incident_cases WHERE incident_case_id = ?`).get(result.incidentCaseId) as any;
      const req = db.prepare(`SELECT * FROM incident_evaluation_requests WHERE incident_evaluation_request_id = ?`).get(kase.incident_evaluation_request_id) as any;
      expected.incident = {
        trigger_owner_module_id: req.trigger_owner_module_id, trigger_reason_codes: parse(req.trigger_reason_codes),
        trigger_severity: req.trigger_severity, related_object_refs: ordered(parse(req.related_object_refs).map(r)),
        incident_class: kase.incident_class, severity: kase.severity, status: kase.status,
        current_release_or_operation_consequence: kase.current_release_or_operation_consequence, safe_summary: kase.safe_summary,
        scope: kase.tenant_id === profileScope.tenant && kase.workspace_id === profileScope.workspace ? 'profile' : 'OTHER',
        timeline: (db.prepare(`SELECT * FROM incident_timeline_entries WHERE incident_case_id = ? ORDER BY occurred_at, rowid`).all(result.incidentCaseId) as any[]).map((e) => ({
          entry_type: e.entry_type, safe_detail_summary: e.safe_detail_summary, related_object_ref: e.related_object_ref === null ? null : r(e.related_object_ref),
          recorded_by_actor_type: e.recorded_by_actor_type, recorded_by_actor_id: e.recorded_by_actor_id,
        })),
      };
    } else {
      expected.incident = null;
    }
  }
  afb.close();
  return { name: c.name, family: c.family, setup: c.setup, input: c.input, expected };
}

// How canonical `Date.parse` reads stored instants, string by string (TZ=UTC). `es_format` marks the
// strings in the ES date-time-string format, which the port must read identically; the others are
// accepted (or not) by the engine's implementation-defined legacy parser, which the port does not
// reproduce (UF-005) — the conformance suite pins that it reads them as unreadable.
// The ES format forbids the year -000000; V8 then falls back to its legacy parser.
const ES_FORMAT = /^(?!-000000)([+-]\d{6}|\d{4})(-\d{2}(-\d{2})?)?(T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})?)?$/;
const INSTANTS = [
  '2026-06-15T00:00:00.000Z', '2026-06-15', '2026-06', '2026', '+002026-06-15', '-000001-01-01', '0000-01-01',
  '2026-06-15T00:00', '2026-06-15T00:00:00', '2026-06-15T00:00Z', '2026-06-15T10:00:00.5Z', '2026-06-15T10:00:00.12Z',
  '2026-06-15T10:00:00.1234Z', '2026-06-15T10:00:00.999999Z', '2026-06-15T23:59:60Z', '2026-06-14T24:00:00Z',
  '2026-06-14T24:00Z', '2026-06-14T24:00:00.000Z', '2026-06-14T24:00:01Z', '2026-06-14T24:00:00.001Z',
  '2026-06-15T10:00+02:00', '2026-06-15T10:00:00-23:59', '2026-06-15T10:00:00+24:00', '2026-06-15T10:00:00+05:60',
  '2026-02-30', '2026-02-29', '2024-02-29', '2026-06-31', '2026-02-32', '2026-13-01', '2026-00-10', '2026-06-00',
  '2026-06-15T25:00:00Z', '2026-06-15T10:60:00Z', '2026-06-15T10Z', '2026-06-15T10:00:00.Z', '1969-12-31T23:59:59.999Z',
  '2026-06-15 10:00:00', '2026-06-15t10:00:00z', '2026-06-15T10:00:00+0200', '2026-6-5', '2026/06/15', 'June 15, 2026',
  '20260615', 'not-a-date', 'yesterday', '',
  // ES TimeClip: ±8.64e15 ms is the last representable instant; one past it is NaN.
  '+275760-09-13T00:00:00.000Z', '+275760-09-13T00:00:00.001Z', '-271821-04-20T00:00:00.000Z', '-271821-04-19T23:59:59.999Z',
  '+275761-01-01', '-271822-01-01',
  // Hour 24 is the next midnight only when every fraction digit is zero.
  '2026-06-14T24:00:00.0001Z', '2026-06-14T24:00:00.0000000000Z', '2026-06-15T10:00:00.1234567891Z',
  // Not ES (the year -000000 is forbidden), so legacy-parsed by V8; unreadable in the port.
  '-000000-04', '-000000-01-01', '2026W26', 'June 1, 2026',
];
const storedInstants = INSTANTS.map((value) => {
  const parsed = Date.parse(value);
  return { value, es_format: ES_FORMAT.test(value), canonical_ms: Number.isNaN(parsed) ? null : parsed };
});

if (new Date(2026, 0, 1).getTimezoneOffset() !== 0) {
  throw new Error('run with TZ=UTC: canonical reads offset-less stored instants in host-local time');
}
const commit = process.env.CANONICAL_COMMIT ?? '';
if (!/^[0-9a-f]{40}$/.test(commit)) {
  throw new Error('set CANONICAL_COMMIT to the exact 40-hex canonical commit this checkout is at');
}
const sourceSha256 = createHash('sha256').update(readFileSync(resolve(SOURCE))).digest('hex');
const cases = CASES.map(runCase);
writeFileSync(process.argv[2], JSON.stringify({
  canonical_commit: commit,
  source: SOURCE,
  source_sha256: sourceSha256,
  generator: 'tests/fixtures/p03-assurance-golden-generator.mts',
  as_of_default: AS_OF,
  stored_instants: storedInstants,
  cases,
}, null, 2) + '\n');
