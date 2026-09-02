# LOOPIA_PROBE_001

## Purpose

Verify the real Loopia Företag account before any production P01 implementation depends on host assumptions.

This is an Ops-plane validation artifact. Passing the probe does not promote runtime authority.

## Required checks

- exact PHP version;
- required PHP extensions: PDO, pdo_mysql, mysqli, curl, openssl, json;
- MariaDB server version;
- transactions and rollback;
- InnoDB row locking / bounded lease behavior;
- foreign keys and constraint enforcement;
- private writable filesystem outside the public document root;
- atomic file create/rename behavior;
- OpenSSL encryption primitives;
- outbound HTTPS via cURL;
- DNS resolution behavior relevant to SSRF controls;
- redirect behavior;
- timeout behavior;
- response-size enforcement capability;
- Loopia scheduler invocation behavior;
- concurrent scheduler/HTTP invocation behavior;
- safe secret/config loading;
- effective runtime/execution limits.

## Fail-closed rule

Any load-bearing capability that cannot be demonstrated is **MISSING**, not assumed.

The probe must emit a machine-readable evidence report before implementation is allowed to rely on the capability.
