# AN-01 — Historical-analysis admission and exact candidate attempt accounting

**Wave 08 / BUILD-16.** Depends on HS-02, OB-01 and IV-01. Semantic owner: existing M02/M03/M04/M06/M01, PromptOps and provider admission. U03, U06 and U08 remain unratified. This is a source-only candidate, not permission to use a provider or a production execution grant.

## Preserved native ownership

ExecutionGrantStore and ExecutionAdmission retain their exact existing closed purposes: MODEL_EVALUATION, MANUAL_MONITOR and SCHEDULED_OBSERVATION. The proposed HISTORICAL_REVIEW_MANUAL_UNADOPTED is deliberately *not* registered in either class, and cannot borrow or reinterpret a previous grant. A syntactically valid HistoryReviewAdmission candidate captures exact tenant/workspace/P01 question, review and attempt IDs, source basis hash, prompt bundle, model policy, credential reference digest, parent grant digest, application fingerprint, request digest, validity window and bounded parent/per-review/input/output limits. It contains no source bodies, provider secrets or authority.

HistoryReviewAdmission::authorize always refuses. U06 must later adopt actual roles, prompt and provider disclosure; U08 must separately approve unattended behavior. Existing scheduled observer grant still permits **zero** model calls. New historical analysis cannot recycle manual-monitor or model-evaluation budgets. There are no actual M06 budget reservations or provider dispatch from this package.

## Disposable proposal family 310

HistoryReviewAttemptStore uses the existing Database transaction semantics but is constructible only under a CLI/disposable TEST marker. Proposed 310 has an immutable parent budget row, attempt candidate records and reconciliation candidate receipts, with six append-only triggers. The parent row's tenant/workspace/grant digest and max-attempt ceiling are immutable. Reservation locks that parent row FOR UPDATE before counting all its child attempts, and separately limits attempts for each review ID. Reusing the exact request+attempt identity returns NO_WRITE; the same request with a different attempt identity refuses. It never refunds based on worker expiry, a claimed zero response or uncertain provider usage, and never emits an execution permit. The parent and child caps are demonstration ceilings, not adopted commercial policy.

Expired or not-yet-valid candidate windows are refused. Cross-tenant and wrong request/digest bindings refuse. All results contain explicit model_call_authorized=false and customer_output_authorized=false; family310 stays outside database/migrations.

## Reconciliation discipline

HistoryReviewReconciliation separates confirmed NOT_SENT, TRANSMISSION_OUTCOME_UNKNOWN and caller-reported retained response metadata, preserving distinct usage UNKNOWN/KNOWN_ZERO/KNOWN_NONZERO without fabricating paid spend or success. The uncertain outcome is an explicit UNKNOWN_OUTCOME_NO_REDISPATCH_HOLD. The attempt ID and request digest are immutable; there is no retry code. A reported retained response hash is still unauthenticated without the original provider owner's artifact and cannot support findings. Reconciliation records append metadata-only statuses, never changes or refunds an existing reservation.

Operator candidate scripts offer only CLI-before-autoload status and a guarded synthetic preview. They do not accept real source URL, actor, credential, arbitrary file path, or spend amount.

## Verification and separate gates

Unit/security/foundation tests cover closed existing provider purposes, absent runtime factory, strict request/budget window, fake owner flags, unchanged digest, unknown-result hold and no source/model egress. Disposable MariaDB 10.6/10.11 tests exercise exact parent and review caps, idempotency, blind-retry denial, limit-version conflict, foreign scope and append-only SQL. Post-build RT-01/RT-02/V01–V11 will test real owners and host readiness. U03/U06/U08 and any production activation remain pending independently; a green source CI is not an approved funded historical-analysis lane.
