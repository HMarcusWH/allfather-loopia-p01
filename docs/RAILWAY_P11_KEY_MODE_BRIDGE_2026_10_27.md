# R03 — Railway staging P11 keyring bridge (code-only candidate)

Prepared: 27 October 2026. State: SOURCE_ONLY / NO_SECRET_CHANGES / NO_DB_REWRAP / NO_GRANTS.

## Current mismatch

The PHP KeyRing already supports legacy APP_MASTER_KEY or versioned APP_MASTER_KEY_VERSIONS, APP_MASTER_KEY_ACTIVE_VERSION, APP_MASTER_KEY_V1 and APP_MASTER_KEY_V2. The Railway entrypoint previously required only the legacy key and wrote only that key to private .env. That prevented the normal P11 versioned keyring from being used on Railway.

## Repair

- ops/railway/render-private-env.php is CLI-only, outside public_html. It reads process settings privately, uses the existing KeyRing::fromEnv validator, and re-parses the generated .env with normal Env to prove exact round-trip.
- Ambiguous modes, incomplete declared versions, malformed key names, short/invalid actual key material, multiline values, quote/trim changes and wrong APP_ENV are refused. Refusal messages carry no secret content.
- Creates only an exclusive mode 0600 .env.prepare. The entrypoint then sets root:www-data mode 0640 and atomically renames it to private .env. There is no HTTP route and no access outside the app's private tree.
- Both legacy and versioned modes remain available; only one may be configured. Empty versioned placeholders do not force a versioned mode.
- Entry point unsets DB/master-key/scheduler secrets before executing Apache, including dynamically discovered master-key variable names without shell eval or logging their values.
- A focused foundation test runs the actual renderer using synthetic keys and isolated temporary directories. The Dockerfile executes it as an image-build gate and the ordinary GitHub CI runs it in its foundation suite.

## Separate operational cutover (not performed)

1. Independently confirm incident relevance and review Railway secret variable NAMES and rotation ages privately; do not collect or print actual values.
2. Confirm P10/P11 off-host backups and custody of old key versions before changing any active encryption.
3. Run reviewed P11 status and plan in the correct tenant/workspace and identify admitted current/future grants across the tenant.
4. Only after exact reviewed host release, set both old and new versioned master-key values and the explicit versions/active selector, removing the legacy APP_MASTER_KEY variable. Verify every required row remains decryptable and grants stay inert.
5. Rewrap encrypted provider rows only by a separate owner-approved transactional P11 apply operation with exact plan digest. Provider API key replacement, Railway bucket tokens and DB password changes require independent plans.
6. Restore a retained-v1 backup to an empty target and verify v1 decrypt and v2-only refusal. Do not destroy retained v1 recovery material while any backup requires it.

No production, U03 historical-use, scheduler, model call, provider key, Railway variable, source capture, deployment or database migration is activated by this PR.

Merge gate: independent review and complete exact-head code CI. A real Railway runtime deployment and key cutover require separate qualification. 
