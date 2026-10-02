# Production operations runbook (completion plan P12)

> **NOT IN FORCE.** No production deployment exists or is authorized. This runbook collects the
> procedures a production deployment would run, so the gap between "authorized" and "operable" is
> known in advance. Every step that names production is unreachable until the gates in
> [`PRODUCTION_PROMOTION_PLAN.md`](PRODUCTION_PROMOTION_PLAN.md) §2 have closed, including a recorded
> owner GO. Where a tool exists today it is named; where it does not, the step says so.

## 1. Before every production release

1. Prepare the release from the exact reviewed Git object (`ops/ci/qualify-release.sh`), never from a
   working tree.
2. Run the prerequisite report and keep its output with the release record:

   ```sh
   php ops/deploy/production-preflight.php --release-ref=<40-hex sha> --env=<private production env>
   ```

   Anything other than `READY_FOR_OWNER_DECISION` stops the release. That verdict is not a GO; the
   recorded owner decision is checked separately, by a person, every time.
3. Confirm the staging preflight's inert posture checks still hold for the same release
   (`ops/deploy/preflight.php --mode=staging` on the staging copy): scheduler inert unless a schedule
   is admitted, no stray decision files, assets staged from the same build.

## 2. Identities, credentials and streams

- Production tenants, workspaces, operators, service identities, provider credentials and M10 ledger
  streams are **new** objects. Never convert a TEST/DEMO identity or stream; never copy a staging
  `.env`.
- Provider credentials are entered only through `ops/provider/credential.php` (write-only, never echoed).
- The master key uses the versioned mode from
  [`CREDENTIAL_ROTATION_RUNBOOK.md`](CREDENTIAL_ROTATION_RUNBOOK.md) from day one, with the recovery
  material held outside the host.
- Model execution, schedules and prompt approvals each need their own owner decision under
  `docs/decisions/` and are admitted through `ops/monitor/authorization.php` and
  `ops/monitor/schedule.php admit`. None carries over from staging.

## 3. Backups, restore and rotation

- Backups and full-restore rehearsal follow [`BACKUP_RESTORE_RUNBOOK.md`](BACKUP_RESTORE_RUNBOOK.md)
  (`ops/backup/export.sh`, `restore-rehearsal.sh`, `verify-restore.php`), with an off-host encrypted
  destination and a disposable restore target the operator owns.
- Key rotation follows [`CREDENTIAL_ROTATION_RUNBOOK.md`](CREDENTIAL_ROTATION_RUNBOOK.md).
- **Gap:** the evidence registry has no kind for a production full-restore or rotation drill, so the
  preflight cannot pass its `RECOVERY_READINESS` check yet. Adding those kinds is a reviewed change to
  `app/Ops/DeploymentEvidence.php` and its foundation tests, made together with the first real drill.

## 4. Stand-down and incidents

- Stop all Monitor activity in a workspace: `ops/monitor/stand-down.php` (suspends grants and, without
  `--grant-id`, every live schedule), then confirm with `ops/rollback/verify-stand-down.php`.
- A single schedule can also be suspended or revoked from `/console/monitor/schedule`; admission never
  happens there.
- M11 incidents opened by assurance sweeps (`ops/assurance/p03.php sweep`) are read and managed by
  operators holding `incident.read` / `incident.manage`.
- Customer-facing incident communication, support channels and retention/disclosure duties depend on
  the offering documents required by the promotion plan; none exist yet.

## 5. Rollback

A code rollback follows `ops/rollback/README.md`. It is not a data restore: restoring data is the
backup runbook's full-restore path, on an empty target, with the quiescence it describes.

## 6. Hosting

Production may not inherit the staging owner-directed deviation. The host must have passing
cross-site isolation evidence (E.1 and E.3) or be a newly qualified isolated boundary. An SSH login on
shared Loopia hosting is account-wide and must not be described as site-isolated.
