# Monitor execution authorization

Completion plan package **P01**. What must be true before the Monitor may fetch a live source or
call a model, where that permission comes from, and what it can never do.

## The contract in one paragraph

Existing owners still decide everything they decided before: M02 permissions, M03 tool rights,
M04 missions and assignments, M06/M01 governed dispatch, M07/M08/M10 review and release. On top of
them, a **NATIVE** Monitor run needs an **admitted execution grant** — an immutable definition that
binds purpose, tenant and workspace, the frozen mission configuration, the human principal, the
exact approved prompts, the model policy, the credential, the source and target, a validity window,
budgets and the application release — and every prompt it runs needs a **prompt approval** for that
exact content. Both are admitted only by a **reviewed owner decision** committed under
[`docs/decisions/`](decisions/README.md). A database row, a CLI command, a preflight mode or a model
output cannot mint that authority. The repository ships no decision, so nothing can be admitted.

## Objects

| Object | Table | Immutable? | What changes it |
| --- | --- | --- | --- |
| Owner decision | file under `docs/decisions/` | per commit | a reviewed commit; editing or removing it withdraws what it authorized on the next check |
| Prompt approval | `monitor_prompt_approvals` | yes | `WITHDRAWN` event in `monitor_prompt_approval_events`; a later owner decision/evaluation creates a new approval record |
| Execution grant | `monitor_execution_grants` | yes | `ADMITTED`, `SUSPENDED`, `REVOKED` events in `monitor_execution_grant_events`; `EXPIRED` is computed |
| Budget spent | `monitor_grant_consumptions` | yes | nothing — spent stays spent |
| Admission trail | `monitor_admission_decisions` | yes | every checkpoint appends one row, allow or refuse |

Purposes: `MODEL_EVALUATION` (named draft prompts against a frozen case manifest; no source, no
mission, no release — see P02), `MANUAL_MONITOR` (one frozen NATIVE mission configuration, all four
prompts approved for product use, each approval citing its evaluation), and `SCHEDULED_OBSERVATION`
(capture and compare only; no model attempt — see P07). Ceilings per purpose are in
`ExecutionGrantStore::CEILINGS`; a grant may ask for less, never more.

## Content is not permission

`ProductPromptPack` holds immutable content in its authored `INACTIVE`/`DRAFT` state and never
changes. `PromptSelectionResolver` is the only thing that produces an executable prompt: the same
bytes, marked `ACTIVE`, bound to the approval and admitted grant that permit them. Approval identity
includes the exact pack version, prompt-version id and binding id as well as prompt/schema/model-policy
digests; unchanged bytes moved onto another binding therefore need a new reviewed authorization.
`verify()` re-derives the selection from stored approvals and grants and compares it whole, so a caller that
flips `activation_status`, names a real grant, or recomputes a hash gets nothing.
`MonitorService`, `PreparedModelRequest` and `ModelExecutor` all go through this resolver. Prompt
content identity is intentionally separate from an approval's authorization identity: the same
immutable prompt bytes may receive a later approval under a new reviewed owner decision or accepted
evaluation, while a withdrawn approval record itself remains terminal. The grant and approval
identities are inside the request provenance, and therefore inside the digest the
operator inspects: a changed grant is a different request. M06/M01 bind that digest
(`model-request://<digest>`), which is how the grant reaches their receipts.

## Checkpoints

`ExecutionAdmission::check()` is asked at every point where going further reaches the world or
relies on a result, and records its answer:

| Checkpoint | Where | Also spends |
| --- | --- | --- |
| `SOURCE_CAPTURE` | `MonitorService::advance` at `NEW`, before any fetch | one `SOURCE_CAPTURE` unit, reserved first |
| `SOURCE_BEFORE_DISPATCH` | inside the governed P01/M01 fetch callback, before DNS or HTTP begins; P01 also re-reads the current source/card/review policy there | — |
| `MODEL_BEFORE_RESERVE` | `ModelExecutor::execute`, before M06 is asked | — |
| (reservation) | inside the attempt's transaction, under the grant row lock | one `MODEL_ATTEMPT` unit |
| `MODEL_BEFORE_DISPATCH` | immediately before the transport is called | — |
| `MODEL_AFTER_RETURN` | after the provider returns, before the candidate is accepted | — |
| `RELEASE_P01`, `RELEASE_ROUTING` | before each human-approved release | — |

At both release checkpoints, admission also re-resolves every prompt approval named by the manual grant. A prompt withdrawal or an evaluation that no longer qualifies therefore blocks release of an older candidate; historical bytes remain historical evidence, not current permission.

A refusal before anything was spent leaves the run waiting, not failed. Source capture is checked
once before its budget reservation and again inside the M01 callback immediately before DNS/HTTP, so
a stand-down in that gap sends nothing while the reserved unit remains truthfully spent. Revocation during a call
cannot recall bytes already sent: the attempt stays spent, the result row records the revocation,
and the response never becomes a candidate. Stand-down never refunds budget, deletes artifacts,
alters reviews or retracts releases — retraction remains the M10 correction/withdrawal operation.

## Operating it

Everything is the private CLI [`ops/monitor/authorization.php`](../ops/monitor/authorization.php);
there is no browser path, and `tests/foundation/execution_authority_boundary.php` asserts that.

```bash
php ops/monitor/authorization.php plan    --env=<private env> --tenant-id=… --workspace-id=… --operator-id=… --grant=<definition.json>
# → the grant digest an owner decision must list. Commit the decision by review, then:
php ops/monitor/authorization.php propose --grant=<definition.json> …
php ops/monitor/authorization.php admit   --grant-id=… --decision=docs/decisions/<file>.json --decision-sha256=<hex> …
php ops/monitor/authorization.php suspend --grant-id=… --reason=OPERATOR_STAND_DOWN …
```

Administering approvals and grants needs `tenant.administer` and `mission.activate`; standing a
grant down needs `tenant.administer` or `mission.transition`. The model policy
(`app/Provider/ModelPolicy.php`) has one identity; changing any value is a new policy every approval
and grant refuses until re-reviewed. `store=false` is requested of the provider, but it is not the
provider-side retention posture — that is an account setting reviewed separately.

## What this does not do

It does not activate anything. The deployed web composition uses
`MonitorFactory::forWorkspace()`. With no ADMITTED `MANUAL_MONITOR` grant it constructs the inert
source/model leaves; with an admitted grant it may construct the live leaves, but every capture,
model and release checkpoint still revalidates that exact grant, the current prompt approval and
its accepted evaluation evidence. No owner execution decision ships in this candidate, so merging
code alone admits nothing. It is not canonical law either: where canonical authority does not yet
cover an operation, the decision belongs upstream first.
