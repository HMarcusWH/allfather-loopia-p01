# Staging operations evidence — 3 October 2026

This filing records exact release `2ada973c520a32a2a4d03e79d9c1e3a55b10ee6a` on
`staging.allfatherbi.com`, fingerprint
`6460ca1b44ab3147966037163e27e4f2c7e2e6a961cf06c4ec9ee0cdb084a21b`.
Its evidence commit is not the deployed application release. The current-state entrypoint is
[MVP_RELEASE_STATE.md](MVP_RELEASE_STATE.md).

## Filed artifacts

All artifacts below are sanitized observations under
[`deployment-evidence/2026-10-03/2ada973c/`](deployment-evidence/2026-10-03/2ada973c/).
Source digests bind the retained local observations; local source payloads are not duplicated into
the registry. The registry contains pointers and hashes only. Older records are preserved.

| Artifact | Observation and limit |
| --- | --- |
| `hosted-conformance.json` | Exact-main GitHub run37128711802; both strict database lanes8,942/0/0. CI only. |
| `host-conformance.json` | Actual Loopia PHP8.5.9/MariaDB10.11.19, complete strict8,942/0/0, separate qualification DB. |
| `staging-deployment.json` | Exact prepared copy under the owner-approved fence/maintenance sequence; all30 migrations, no drift/no-op proof; inert serving resumed. |
| `host-probe.json` | Actual-host baseline/network READY; scheduler transport UNVERIFIED; capability is not activation. |
| `monitor-attempt01-unqualified.json` | Preserved external wrapper parser failure. The smoke CLI executed; its full verdict was not retained. No application failure or PASS is inferred. |
| `staging-smoke.json` | Anonymous23/0, authenticated N/O118/0, corrected Monitor02 50/0 with6 specific checks, cleanup0sessions, request-ID proof. |
| `browser-observations.json` | Inert browser inspection, expected empty/denied states, unwired details and stale support wording retained. Not full P09 acceptance. |
| `p10-backup-restore.json` | Actual encrypted native backup, off-host copy and clean-target restore of219tables/2artifacts; supplemental30migration/272trigger proof. |
| `credential-and-activation-boundary.json` | Credential encrypted after first backup; no provider call/grant. P11 not exercised; first restore excludes this newer credential. |

The first Monitor parser recognized only single-quoted literal labels and rejected the smoke
client's generated asset labels after the client completed. Its original report kept an incorrect
`NOT_RUN` default; a preserved reconciliation record explicitly corrects that to
`REPORT_RECEIVED_NOT_QUALIFIED`. The fix admits only the four exact stylesheet/script labels in
the pinned source. A fresh account pair and run ID were used after review. Both attempts ended
with supported retirement and zero live sessions; the earlier evidence failure is not erased.

## What P10 established

The source was the newly deployed current release under actual BACKUP maintenance. The older PR16
tree could not provide these new lease/manifest contracts, which is why the owner approved upgrading
inertly under a temporary static fence first. The bounded legacy drain observation remains distinct
from the later real maintenance lease.

The native dump's trigger ownership was preserved on a separate isolated MariaDB10.11.19 target
with matching authenticated principal and a fresh local password. The protected release's original
restore script verified the selected backup's database, private artifacts and history summary. A
reviewed external capture shim retained only sanitized verifier fields; the original script still
deleted temporary plaintext. The local restored runtime and scheduler were never started.

The supplemental readback found219tables,272triggers,30migration hashes matching the exact release,
all trigger definers matching the authenticated principal, and zero current/future execution grants.
Actual UPDATE and DELETE attempts on one existing `access_evaluation_records` row were refused by
the exact append-only triggers with SQLSTATE45000/native1644. Each transaction rolled back. The
whole table retained28rows and the same content digest before and after.

That backup contained no M10 stream, Monitor run, retained-output render model or provider
credential row. Therefore no populated mission/history replay or real P11 rewrap/older-key recovery
is claimed. A later encrypted temporary provider credential is outside the first snapshot. Any
subsequent recovery exercise must name its own backup and result.

There is no BACKUP/RESTORE registry kind in the current schema. These P10 observations remain
supplemental artifacts; no `ROLLBACK_DRILL`, `ROAD_TEST_001` or activation record is invented.

## Remaining boundaries

The shared-host risk state is unchanged: historical E.1 FAIL_READABLE and E.3 UNVERIFIED, with
owner-directed inert staging continuation. There is no production isolation exception. No current
release rollback drill, model evaluation success, populated mission, ROAD_TEST_001 result,
customer-visible release or external action is claimed. The funded/provider setup and tenant-admin
role-version repair are separate work; preparatory content inspection is not a real governed model
request or execution receipt. All `upstream.lock.json` values remain unchanged.

## Historical pointer correction

The exact base already contained a hash mismatch in `PR30_HOSTED_CONFORMANCE_PASS`: its registry
pointer was `62b484b158b9c34af6c6e18739e3bd74994a5e105662b7b3083b0c067c9efb02`, while the tracked
PR30 receipt bytes hash to `f110bbc6c80cef77df8b261787a48089f6b909a6e4dfdd623e9551a8a8c5d8ba`.
This filing corrects that pointer only. The existing artifact is byte-identical to the base and
continues to identify PR30 `0b8c17e9`, with both lanes8,942/0/0; its contents, date and result are
unchanged. All21 registry pointers were checked against their actual artifact bytes.
