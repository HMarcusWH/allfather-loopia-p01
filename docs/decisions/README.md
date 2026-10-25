# Owner execution decisions

This directory holds **reviewed owner decisions** that authorize exact execution grants and prompt
approvals. As of 2026-10-24 the repository records **four separately scoped Plus 46 TEST decisions**: four original nano-policy MODEL_EVALUATION prompt approvals; one exhausted 18-attempt nano-policy grant; four new **Luna-policy** MODEL_EVALUATION prompt approvals; and one new exact **Luna DEVELOPMENT seven-attempt/zero-source grant digest**. All four decisions cite immutable upstream owner records in `HMarcusWH/Allfatherbusinessintelligence`. None of these files alone sends a provider request, admits a grant, starts a run or activates product features. Only a private, authorized CLI may admit the exact currently proposed grant; a separate deliberate `start`/`preview`/`execute` sequence and the normal M02/M03/M04/M06/M01 gates remain necessary. Never reuse old nano-policy approval/grant identities against the Luna policy.

A decision is a JSON document added by a reviewed commit — never written by the application.
`app/Workflow/OwnerDecisionRegistry.php` reads it, and every admission check re-reads it: editing,
narrowing or deleting the file withdraws what it authorized on the next check.

```json
{
  "schema_version": "1.0",
  "kind": "OWNER_EXECUTION_DECISION",
  "decision_id": "OWNER-2026-10-01-EVAL-01",
  "decided_by": "the owner, by name and role",
  "decided_at": "2026-10-01T09:00:00Z",
  "canonical_authority_ref": "the canonical decision or contract this relies on",
  "tenant_id": "<uuid>",
  "workspace_id": "<uuid>",
  "purposes": ["MODEL_EVALUATION"],
  "authorizes": {
    "prompt_approvals": [
      {"purpose": "MODEL_EVALUATION", "role": "P01_SIGNAL_CLASSIFIER",
       "pack_version": "2026-09-27.monitor.v1",
       "prompt_version_id": "<prompt-id>@2026-09-27.monitor.v1",
       "binding_id": "loopia.monitor.P01_SIGNAL_CLASSIFIER@2026-09-27.monitor.v1",
       "prompt_sha256": "<hex>", "schema_sha256": "<hex>", "model_policy_digest": "<hex>"}
    ],
    "execution_grants": ["<grant digest printed by ops/monitor/authorization.php plan>"],
    "monitor_schedules": ["optional: <schedule digest printed by ops/monitor/schedule.php plan>"]
  },
  "disclosure_scope": "what may be sent to the provider, and what may not",
  "retention_scope": "what is retained, where, and for how long",
  "notes": "optional"
}
```

Purposes are `MODEL_EVALUATION`, `MANUAL_MONITOR` and `SCHEDULED_OBSERVATION`. `monitor_schedules`
is optional and is read only for `SCHEDULED_OBSERVATION`: admitting a scheduled-observation grant's
capture budget does not admit a recurrence, and a schedule needs its own listed digest (see
[`../MONITOR_SCHEDULING.md`](../MONITOR_SCHEDULING.md)). A decision authorizes
exactly the digests and identities it lists — nothing broader — and it does not replace canonical
authority: where canonical does not yet cover an operation, the decision belongs upstream first.
See [`../MONITOR_EXECUTION_AUTHORIZATION.md`](../MONITOR_EXECUTION_AUTHORIZATION.md).

> **CI qualification note, 2026-10-25:** These four source-controlled model-evaluation decisions still participate in exact owner verification. They **do not by themselves start any model call**, but their mere presence intentionally makes `ops/deploy/preflight.php` refuse the `INERT_COPY` release profile. The ordinary **CODE_ONLY** source qualification may pass with them retained and reports `deployment_readiness=NOT_ASSESSED`. Do not remove or rewrite owner decisions to obtain a green CI badge; the manual-pilot authority path is separate.
