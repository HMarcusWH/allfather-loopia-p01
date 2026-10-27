# LI-07G — Railway R5-0 read-only backup-list acquisition

**Prepared:** 27 October 2026. **Claim ceiling:** \`OPERATOR_CLI_METADATA_ONLY / NOT_A_COMPLETED_BACKUP_CERTIFICATE / NO_RESTORE / U03_PENDING\`.

This complements [LI-07F](LI07F_R5_RAILWAY_HOST_NATIVE_RECOVERY_GATE.md), [Issue #97](https://github.com/HMarcusWH/allfather-loopia-p01/issues/97) and canonical [U03 PR #382](https://github.com/HMarcusWH/Allfatherbusinessintelligence/pull/382). It does **not** replace an independent review of the Railway Backups UI, actual data recovery or the six required owner decisions.

## What is now available

The Railway [official Public API volume guide](https://docs.railway.com/integrations/api/manage-volumes) documents read-only GraphQL fields:

- \`project.volumes.volumeInstances\` — discover the **environment-specific volume-instance ID** under each project-level volume.
- \`volumeInstance\` — verify its parent volume, service name, mount and capacity.
- \`volumeInstanceBackupList\` and \`volumeInstanceBackupScheduleList\` — list provider backup objects and schedules without creating/restoring/deleting them.

\`volumeId\` and \`volumeInstanceId\` are **different identifiers**. Do not send a project-level volume ID to an instance-specific backup lookup or guess an instance from its display name.

The repo ships \`ops/qa/railway/r5_readonly_inventory.py\`, a Python 3.10+ operator CLI collector using only these exact **queries** via \`railway api\`. It makes **no** calls to Railway via a source-bundled API key. Use a separately authenticated official Railway CLI context on the operator's own computer. It queries the *exact* project, environment, source service, expected role, mount and volume-instance relationship; it refuses ambiguous/missing instances and unreported paginated results.

Its outputs distinguish:

- **Private provider metadata** — exact backup IDs and schedules, preserved as a new \`0600\` file **outside the Git repository** for the Ops reviewer; never publish it.
- **Sanitized summary** — backup counts, listing/expiry dates, ID SHA-256 commitments and source role; also written outside the repo.
- **No proof** of snapshot completion or physical restoration, correct pairing, private M05 byte recovery, vendor/source authorization, U03 or live 270/280/290 activation. Those fields remain false even when both volume backup lists are populated.

If the provider lists no backup, an expired backup, unsupported schema/query, wrong tenant/service/volume association, or a partial page, the collector refuses or produces an explicit \`HOLD\`. A successful readback is \`R5_BACKUP_INVENTORY_ONLY_NOT_RESTORED\` at most, **not** \`R5_RAILWAY_PAIRED_HOST_RESTORE_VERIFIED\`.

## Operator use — read-only, separate trusted machine

1. Verify the exact script in the reviewed PR and the host identity currently shown by Railway. Run the [offline tests](../ops/qa/railway/test_r5_readonly_inventory.py) first:

   \`\`\`bash
   python3 -m unittest discover -s ops/qa/railway -p 'test_r5_readonly_inventory.py' -v
   \`\`\`

2. Authenticate the local Railway CLI through its own login flow if not already authenticated. **Never** paste the CLI token or rendered environment variables into chat/GitHub.

3. Prepare one private directory **outside** the repository; file names must not already exist. Invoke:

   \`\`\`bash
   mkdir -p "$HOME/private/allfather/r5"
   chmod 700 "$HOME/private/allfather/r5"

   python3 ops/qa/railway/r5_readonly_inventory.py \
     --project-id='<REVIEWED_RAILWAY_PROJECT_UUID>' \
     --environment-id='<REVIEWED_STAGING_ENVIRONMENT_UUID>' \
     --db-service-id='<REVIEWED_ALLFATHER_MARIADB_SERVICE_UUID>' \
     --app-service-id='<REVIEWED_ALLFATHER_PHP_SERVICE_UUID>' \
     --private-out="$HOME/private/allfather/r5/provider-backups-private.json" \
     --summary-out="$HOME/private/allfather/r5/provider-backups-summary.json"
   \`\`\`

4. Independently compare the private provider records against each service's Railway **Backups** tab, especially completion/validity, retained dates and *actual* volume occupancy. The query's \`currentSizeMB\` is capacity information, **not** measured used bytes; backup \`usedMB\` is **not** a substitute for current mounted used bytes.

5. File only the sanitized reviewed result in the protected Ops evidence register. Require the Ops owner's separate decision about whether restoration is safely possible with a separate target. The ordinary Railway Restore flow **stages a volume mount replacement and redeploy** on the original service; never run it from this collector or as an R5-0 read operation. See [Railway volume backups](https://docs.railway.com/volumes/backups).

## Testing/assurance notes

Tests in \`ops/qa/railway/test_r5_readonly_inventory.py\` use *in-memory fake query responses*, not network traffic. The isolated [R5 inventory fixture workflow](../.github/workflows/r5-readonly-inventory-fixtures.yml) uses \`contents: read\`, no Railway token, no hosted service secret, and no deploy/restore. These tests qualify the **collector logic only**, never the existence of an actual staging backup.

The Railway connection available in ChatGPT can inventory mounted volumes and current deployments but does not expose the provider's per-volume-backup objects. The operator-side query is a deliberately separate evidence channel, not an excuse to fabricate positive status from the ChatGPT project inventory.