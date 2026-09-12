# Runtime Storage

`storage/` is private runtime state. It is a sibling of `public_html/`, carries a deny-all
`.htaccess` as defence in depth, and is **not owned by the destructive root deployment sync**.

The staging root `rsync --delete` excludes `/storage/***`. Deployments and rollbacks copy only the
committed `storage/.htaccess` and this README separately, without `--delete`, so runtime-generated
state survives code replacement.

Current deterministic runtime file:

- `request.log` — append-only support-reference index written by `RequestLog`; request id, method,
  path and status only. No query string, body, account, tenant or workspace identifiers.

Future private runtime artifacts belong here only when their owning design says so. They must not be
committed and must not be made web-addressable.

Security properties:

- no WordPress/shared public storage;
- no secrets committed to Git;
- HTTP access must return 403/404;
- Loopia host qualification must prove the private filesystem location works;
- cross-site web-PHP isolation must be independently closed before secrets are installed;
- code deploy/rollback must preserve the directory's runtime contents.
