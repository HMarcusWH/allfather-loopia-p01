# Bounded Monitor workbench

Implementation candidate, 27 September 2026. This document describes the new local composition; it does not record deployment, native model evaluation, approved live source access or production readiness. The previously qualified staging release is recorded separately in the deployment evidence registry. See [project boundary](../PROJECT_BOUNDARY.md), [scoped reconciliation](reconciliation/monitor-scoped-extension-2026-09-27.json), [PromptOps](PROMPTOPS_PORT.md) and [model boundary](BOUNDED_MODEL_BOUNDARY.md).

## What the workflow does

The workbench composes the existing governed owners into a manually advanced P01-to-P04 path: selected profile and source evidence → capture comparison → model interpretation → battlecard draft → independent model criticism → human review → P01 internal release → configured role routing proposal → a separate routing critic → another human review → P04 internal release. M05 evidence, M06/M01 dispatch, M07 review, M08 admission, M10 reliance and the applicable product release services retain their authority. The run journal records progress and artifacts; it cannot grant a release.

The four deployment-authored model roles are `P01_SIGNAL_CLASSIFIER`, `BATTLECARD_GENERATOR`, `MONITOR_REVIEW_CRITIC` and `MONITOR_ROUTING_CRITIC`. They are separate from the six canonical PromptOps fixture roles. All product prompt bindings remain `DRAFT`/`INACTIVE`, offline contract tested, and not model evaluated. The second critic reviews the exact proposed distribution summary, source release, configured target and current/frozen redaction policy; the first critic is not reused as that assessment.

The production composition is deliberately inert: `MonitorFactory::inactive()` uses a no-network capture transport, an empty fixed resolver and the exact disabled `OpenAIResponsesTransport(false)`, with native execution disabled. No environment toggle, browser control or configuration file grants activation. Fixture composition is explicitly injected for TEST workspaces. No scheduler calls this runner, and the implementation adds no email, CRM mutation, public publishing, customer visibility or consequential external action.

## Prepare a bounded mission

Setup is a separate operator act using existing services and permissions. The workbench does not create identities, memberships, contracts, missions, source rights, credentials or target policies. Before configuring it, establish:

1. An active `COMPETITION_MONITORING` mission in the chosen active workspace, with an explicit objective, source allowlist and prohibitions. FIXTURE mode requires a TEST workspace.
2. An active monitored source and current admissible source card/terms policy, plus an HTTPS URL within the approved source scope. This composition retains body text only for standard retention with permitted caching and derived summaries; it refuses `no_cache`, short and minimized retention. Existing fingerprint-only evidence is not reconstructed into text.
3. One to four admitted M05 uploaded profile captures, with matching private bytes and explicit source selection. Owner-supplied company information stays `TENANT_PROVIDED`; it is not independently verified or promoted into a company twin.
4. Explicit active capture, author and critic mission assignments/capability contracts. Author and critic identities must differ. Exact bounded model descriptors require `runtime_host_binding.administer`; registering them grants no assignment or right to invoke them.
5. A configured P04 organization-role target and redaction policy, with the intended role explicitly selected. Role intent never creates a recipient membership. The P01/P04 policy IDs and release-governance bindings must already exist.
6. A tenant-scoped credential identity if an explicitly authorized execution path will use it. Credential storage/rotation requires `tenant.administer` and private application configuration; the workbench has no credential endpoint. Do not put key values in mission JSON, URLs, command arguments, screenshots or receipts.

Freeze the approved identifiers and limits in a private JSON document outside `public_html`. Its top-level keys are `scope`, `definition`, and `execution_mode`. `scope` identifies the operator, tenant and workspace. `definition` contains exactly: `mission_id`, `monitored_source_id`, `capture_url`, `capture_governance`, `model_governance`, `release_governance`, `context_selection`, `credential_id`, `target_org_role`, `p01_policy_id`, `p04_policy_id`. Use the existing service-generated identifiers; do not invent identifiers or copy a fixture's authority into staging. The context selection includes `selected_target_org_role`, equal to the definition target; the server loads its current redaction policy into the request. A broad audience label does not select an organization role.

The private CLI validates the actual setup permissions and current bindings:

```sh
php ops/monitor/configure.php --env=/private/runtime.env --configuration=/private/approved-monitor.json
```

The actor must already have `mission.create`, `mission.activate`, and `distribution.configure_targets`. Configuration is immutable per mission: an identical repeat succeeds, a changed definition refuses. Its receipt explicitly grants no activation. Neither a `NATIVE` mode label nor a saved credential activates the factory, source transport or prompt pack.

## Workbench operation

Open `/console/monitor` from Competition Monitor and select the workspace through the existing session flow. The browser cannot choose a different tenant/operator, model, provider, tool, endpoint or credential. The Kernel applies the normal authentication, account lifecycle and CSRF checks; each service call also checks current permissions.

| Step shown in the run | Operator action and result |
| --- | --- |
| Configured mission | Create a run with its fixed configuration and idempotency identity. |
| `NEW` | Advance one capture. A first observation ends as `BASELINE`; a subsequent observation becomes `CAPTURED`. |
| `CAPTURED` | Advance the deterministic P01 comparison. No change ends as `NO_CHANGE`; a changed pair proceeds to `DIFFED`. |
| `DIFFED` | Inspect the exact classifier request, then continue with its request digest. |
| `ANALYZED` | Inspect the battlecard request and its bound predecessor before continuing. |
| `DRAFTED` | Inspect the independent draft-critic request before continuing. |
| `REVIEW_REQUIRED` | Read source/profile basis, request, candidate and critic; write a human assessment and choose HOLD, REJECT or APPROVE. Approval invokes existing P01 admission/release checks. |
| `P01_RELEASED` | Advance to prepare the exact P04 routing proposal for the configured target. |
| `ROUTING_PREPARED` | Inspect the fourth request, including its exact routing package, and continue with that request digest. |
| `ROUTING_REVIEW` | Perform a new human review of the routing and critic. Approval invokes existing P04 admission/release checks. |
| `COMPLETE` | Inspect the journal and current M10 reliance. Completion is historical progress, not a permanent release grant. |

Viewing a run never repeats capture or model execution. Request previews include the fixed instructions, structured schema, exact API body and provenance; they contain selected private source/profile text and belong only in the protected workbench/artifact store. Model-stage POSTs must carry the digest of the displayed request. If context or request changes, reload and inspect it again. A request-preparation refusal is displayed and offers no advance control.

Human review requires an explicit decision, a fresh candidate digest and the operator's own assessment (at least 20 trimmed characters; at most 4,000 bytes). There is no preselected approval or generated human assessment. HOLD terminates as `BLOCKED`; REJECT terminates as `STOPPED`. Model acceptance is advisory and does not substitute for this human act. Current context and routing authority must still pass release-time checks. STOP preserves all recorded history.

Read/list uses `mission.read`; detail additionally requires `evidence.read`. Start requires `mission.read`, `mission.transition`, `evidence.read`; advance also requires `tool.invoke`. Review requires `company_signal.review`, `evidence.read`, `mission.transition`, and routing additionally requires `distribution.route`. Existing downstream owners retain their own gates. Missing authority is a refusal, not an empty success, and the UI does not grant missing roles.

## Budgets, replay and failures

Context limits bound input bytes, output tokens, observation age and model attempts. At most four admitted reserved model attempts are permitted per mission lifetime, not per run. A full successful path uses all four. A smaller approved budget can intentionally stop the path earlier; reserved attempts remain consumed after terminal provider/credential failures. Starting another run does not reset the budget. Recurring observation requires a separately approved bounded mission/grant decision; there is no automatic renewal.

The run journal serializes advancement and checks revision/event digests. Source captures, model attempts/results and private artifact bytes retain their own identifiers and digests. An idempotent model replay does not call the provider again; an ambiguous interrupted attempt is blocked for reconciliation. Do not make a new retry identity merely to bypass that refusal. Preserve the support reference, run ID, request digest and fixed reason code, then reconcile the authoritative M01/M06/provider receipts. No automatic retry or crash-recovery success is implied.

`BASELINE`, `NO_CHANGE`, `BLOCKED`, `FAILED` and `STOPPED` are terminal outcomes, not successful internal releases. A run can release P01 before its later routing is held; inspect each current release independently. A completed run remains in history if its source or release is later revoked or superseded. The workbench displays current ledger reliance separately from historical journal integrity.

## What the evidence proves

Controller and prompt unit tests prove local contracts and escaped rendering. Integration fixtures use real database/services with explicitly synthetic source transport and manually authored model responses, exercising governed invocation, candidate handling and release decisions. FIXTURE labels remain on the run and attempt provenance; generic provider telemetry alone is not evidence of a real API call. Local HTTP or fixture passes are not Loopia Apache/host qualification.

Offline schema, citation membership and digest checks do not establish factual entailment, source-injection detection quality, useful Swedish business reasoning or model reliability. Native evaluation requires separately reviewed prompt activation, approved source scope, transport/credential authority, explicit owner GO, bounded real observations and actual provider receipts. This candidate performs none of those activation steps. Scheduler activation, production isolation, production secrets and customer-visible authority remain separate work.

Primary implementation references: `app/Workflow/MonitorService.php`, `MissionConfiguration.php`, `MonitorFactory.php`, `RunJournal.php`, `SourceCaptureStage.php`, `ReleaseStages.php`, `ContextEnvelope.php`; `app/Provider/ModelExecutor.php`; `app/Http/Controllers/MonitorController.php`; `ops/monitor/configure.php`. See the focused tests in `tests/unit/monitor_controller.php`, `tests/integration/monitor_workflow.php` and `tests/integration/bounded_model_stages.php`; their recorded outputs, not this manual, establish measured pass counts.
