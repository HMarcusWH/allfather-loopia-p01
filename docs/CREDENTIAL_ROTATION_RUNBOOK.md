# Provider master-key rotation and recovery runbook

P11 makes provider-credential encryption version-aware and adds a bounded rewrap procedure.

It does **not** change a provider API key, admit model execution, deploy code, alter an owner decision,
or rotate the real staging master key merely because this code is merged.

## 1. Configuration modes

Exactly one master-key configuration mode is allowed.

Legacy mode:

    APP_MASTER_KEY=<32-byte key, base64 or 64-hex>

Legacy rows are version 1 and new writes use version 1.

Versioned mode:

    APP_MASTER_KEY_VERSIONS=1,2
    APP_MASTER_KEY_ACTIVE_VERSION=2
    APP_MASTER_KEY_V1=<retained old key>
    APP_MASTER_KEY_V2=<new active key>

Every declared version must have exactly one key. The active version must be in the declared set.
Stray/malformed APP_MASTER_KEY_V<n> settings refuse. Legacy and versioned mode cannot coexist.

The active version is the **only** rewrap target. The operator cannot pass a --to-version override.

## 2. What rewrap changes

Master-key rewrap preserves:

- provider_credential_id;
- provider;
- label;
- ACTIVE / ROTATED / REVOKED lifecycle status;
- provider-secret plaintext.

It changes:

- ciphertext;
- nonce;
- authentication tag;
- key_version;
- credential_digest.

credential_digest is an HMAC of the provider secret under the master key. Therefore a v1 -> v2
rewrap intentionally produces a new digest even when the provider API key itself is unchanged.

Existing execution grants are immutable and retain their old credential digest. They therefore become
stale after rewrap and remain historical. Rotation never edits, suspends, re-admits or replaces them.
A later successor grant must be proposed and separately admitted if execution is desired again.

Replacing the **provider API key itself** is a different operation. CredentialStore::save creates a
new provider credential identity and existing frozen mission/grant bindings require explicit
successors.

## 3. Before rotation

Use the exact qualified release and a private env file outside public_html.

The operator used for the command must hold tenant.administer in the workspace supplied to the CLI.
Rotation itself is **tenant-wide**: it examines every encrypted provider credential and every relevant
execution grant in the tenant, including grants in other workspaces.

Stand down all current or future-admitted execution grants that reference credentials selected for
rewrap. A future-dated grant whose latest lifecycle event is ADMITTED still blocks rotation because it
could become executable later without another owner act.

Expired, suspended and revoked history does not block.

Do not put master keys or provider secrets on argv or stdin. The rotation CLI reads key material only
from the private env file.

## 4. Inspect status

    php ops/provider/rotate-master-key.php status       --env=/private/staging.env       --tenant-id=<tenant>       --workspace-id=<workspace>       --operator-id=<operator>

Status reports only safe metadata:

- keyring mode;
- available key-version numbers;
- active key version;
- counts by credential key version/lifecycle state;
- number of rows pending rewrap;
- blocking execution-grant IDs, if any.

It does not print ciphertext, credential digests, master keys or provider secrets.

## 5. Plan one bounded batch

Default and maximum batch size is 25 rows:

    php ops/provider/rotate-master-key.php plan       --env=/private/staging.env       --tenant-id=<tenant>       --workspace-id=<workspace>       --operator-id=<operator>       --limit=25

The plan binds the exact ordered credential IDs, provider, lifecycle status, old key version, keyed
credential digest, sealed-payload digest, tenant and configured active target version.

Record and inspect the returned plan_digest.

A plan grants nothing and changes nothing.

## 6. Apply the exact inspected plan

    php ops/provider/rotate-master-key.php apply       --env=/private/staging.env       --tenant-id=<tenant>       --workspace-id=<workspace>       --operator-id=<operator>       --limit=25       --plan-digest=<exact-sha256>

Apply takes the same tenant-row mutex used by provider credential replacement and execution-grant
admission, then re-reads the candidate rows and execution authority.

It refuses if:

- the inspected plan changed;
- an affected current/future-admitted grant exists anywhere in the tenant;
- an old key version is unavailable;
- old ciphertext/AAD does not authenticate;
- decrypted plaintext does not match the stored old credential_digest;
- the target rewrap cannot open back to the exact same plaintext;
- any selected row changed before the transaction acquired its locks.

For each selected row the command:

1. decrypts under the exact recorded old key version;
2. verifies the old keyed digest;
3. seals under the configured active version;
4. reopens the new ciphertext and compares plaintext privately;
5. recomputes the keyed digest under the new version;
6. updates only ciphertext/nonce/tag/key_version/credential_digest;
7. appends one immutable provider_credential_rotation_events row.

The whole bounded batch commits atomically. A refused/failing batch leaves no partial row updates or
rotation receipts.

Re-running the same already-committed plan digest returns the completed batch idempotently.

## 7. Continue until no live rows use the old version

Repeat status -> plan -> inspect -> apply until pending_rewrap_count is zero.

Only then is it meaningful to remove the old key from the **live runtime keyring**.

Removing v1 from the live keyring and destroying v1 are different acts.

## 8. Recovery-key retention

P10 backups intentionally contain encrypted provider rows but no application master key.

If a retained backup contains key_version=1 rows, a protected v1 recovery key must remain available
for that backup's retention period even after all live rows use v2.

P11 recovery proof is performed under the same P11 release:

1. keep v1 configured and active;
2. create a P10 backup containing a v1 credential;
3. switch active version to v2 and rewrap the live database;
4. restore the pre-rewrap backup to an empty disposable target;
5. prove the restored v1 credential opens when v1 recovery material is supplied;
6. prove the same restored row refuses under a v2-only keyring.

Do not add APP_MASTER_KEY_V<n> values to the backup archive, manifest, deployment-evidence registry or
repository.

## 9. Historical grants after rewrap

Because credential_digest changes, an immutable historical grant that named the old digest is no
longer current.

That is expected. Do not edit definition_json or grant history to make it match the new digest.

If execution is later needed:

1. retain the frozen mission if the credential ID itself did not change;
2. prepare a new grant definition carrying the current credential digest;
3. inspect its new grant digest;
4. obtain/apply the separate owner decision for that successor grant.

If the provider API key itself was replaced and therefore has a new credential ID, use the normal
successor mission-configuration path before proposing the new grant.

## 10. Evidence and authority boundary

provider_credential_rotation_events is an append-only operational receipt. It stores IDs, versions and
digests only. A completed recovery/rotation rehearsal may later be referenced by a separate reviewed
`KEY_ROTATION_DRILL` deployment-evidence record; the row receipts are inputs to that evidence, not
the registry record itself.

It is not:

- M10 release authority;
- an owner decision;
- deployment evidence;
- permission to execute a provider call;
- a backup of master-key material.

This implementation PR must not change deployment-evidence.json or upstream.lock.json and must not
perform a real staging rewrap.
