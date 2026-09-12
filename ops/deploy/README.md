# Deployment

**There is no deployer here, and that is deliberate.** The copy is an operator running `rsync`,
because automating it would remove the one moment where somebody looks at what is about to be sent.
What this directory holds is the check that runs *before* the copy.

The full sequence is [`docs/BETA_RUNBOOK.md`](../../docs/BETA_RUNBOOK.md); the host layout and the
copy commands are [`docs/DEPLOYMENT_RUNBOOK.md`](../../docs/DEPLOYMENT_RUNBOOK.md) §3.

---

## `preflight.php`

    php ops/deploy/preflight.php \
      --env=<path to the private staging .env> \
      --release-ref=<full 40-character git SHA> \
      --mode=staging

Exit 0 and `"status": "READY_FOR_INERT_STAGING_COPY"` mean the release may be copied. Anything else
prints every problem it found — not the first one, because an operator fixing a release wants the
whole list rather than five passes.

It opens no connection to the host, copies nothing, and has no `--apply`.

### What it checks

**The configuration file itself, before it is read.** `--env` naming a path inside any `public_html`
is refused outright — exit 6, nothing loaded. A `.env` beneath a served root is a published secret,
and the shape that slipped through was `--env=public_html/assets/staging.env`: the layout check
permits `assets/`, and the private-file checks look for a few fixed names directly under
`public_html/`, so preflight would have loaded the file, validated the master key and database
password inside it, and answered READY about a release whose credentials Apache serves as a static
asset. Two rules, because this command can resolve only its own served root: the resolved path must
not be inside this release's `public_html/`, and it must contain no `public_html` segment at all —
on Loopia every site in the account is `~/<domain>/public_html`, and a sibling site's served root is
just as public. It is a refusal rather than a recorded problem: a NOT_READY verdict would be a
report about a secret that is being served, produced by reading it.

**Configuration.** `APP_ENV` resolves to `staging`; `APP_BASE_URL` is a bare `https://` origin;
`APP_RELEASE_REF` equals the release being deployed; `DB_NAME` and `DB_USER` are set;
`APP_MASTER_KEY` **decodes to 32 bytes**; `SUPPORT_CONTACT_TEXT` names a route to a human; and the
scheduler credential is **blank**.

Two of those deserve their reasoning stated. A present-but-31-byte master key is a configuration
error a deployment should meet before the copy rather than at the first credential it tries to seal.
And an inert staging deployment leaves the scheduler door *unreachable* rather than open: the
handler registry is empty, so a configured token would be a live door to a machine with nothing to
run.

The commonest wrong-but-healthy-looking deployment is new code with the previous release's
`APP_RELEASE_REF` still in `.env`. The support page then shows a reference that resolves to a build
nobody deployed, and the partner's report and the operator's build disagree about which code
produced it. That is why the ref is an argument here and is compared rather than read.

**The staged Operator Console.** `ConsoleAssetManifest` already proves every manifest path is local,
hashed, present and not symlinked — that is what the runtime needs. Preflight asks the question the
runtime does not: whether anything *else* is in the directory. A stale bundle from a previous build
or a leftover source map is served if it is there, and the manifest would never mention it. The
expected set is derived from the manifest rather than re-declared, so it cannot drift from
`frontend/console/stage-assets.mjs`, which enforces the same equality on the build side.

It also refuses a development bundle, which the hashed-name rules would not catch: a
`sourceMappingURL` comment, or React's development build.

**The served layout.** `public_html/` holds exactly the two entry points, `assets/` and its own
`.htaccess`; every private directory exists as a sibling and not inside it; no configuration or
governance file is in the served tree.

**Authority and evidence posture.** The four permanent authority locks are false,
`no_external_charge_executed` is true, `live_read_activated` is false — an inert staging copy is not
a live-read deployment — the evidence registry validates against the lock, and no claim is asserted
without a record supporting it.

**The scheduler is inert.** Both composition roots construct `SchedulerRunner` with an empty handler
registry, and `scheduler.php` is still POST-only.

**No external action is wired.** Nothing in `app/`, `public_html/`, `worker/` or `ops/` constructs
`CurlHttpsTransport` or `DnsHostResolver`. `tests/foundation/no_external_action_surface.php` asserts
this on every commit; preflight asks it of the tree that is about to be copied, which is a different
question from asking it of a tree on somebody else's machine.

**Whether this is the named release** — advisory. If `.git` is present it resolves HEAD and compares;
otherwise it reports `NOT_AVAILABLE`. `.git` is not part of a deployment, and this command belongs
both in a checkout, where it can answer, and on the host, where it cannot.

"Present" includes a **linked worktree or submodule**, where `.git` is a text file reading
`gitdir: <path>`; the pointer is followed, and so is the `commondir` beside it, because a worktree
keeps `HEAD` of its own but reads branch refs and `packed-refs` from the common directory. Without
that, the check answered `NOT_AVAILABLE` on those layouts and skipped the comparison entirely — so
an operator could name the intended SHA in both `--release-ref` and `APP_RELEASE_REF` while rsyncing
a checkout at some other commit, and the one check that could have caught it was the one that
silently opted out.

### The source fingerprint

Preflight prints `source_fingerprint`: a sha256 over the bytes of `app/`, `database/`,
`public_html/` and `worker/`, paths sorted so two machines agree. **Record it beside the release
SHA.** After the copy, `ops/rollback/verify-stand-down.php --expect-fingerprint=<that digest>`
compares it against the deployed tree and answers what is RUNNING.

That is a different question from the one `APP_RELEASE_REF` answers. A rollback or a deployment is
two steps — put the code there, update the configuration — and if the first fails while the second
succeeds, `APP_RELEASE_REF` names exactly the release you asked for while the old code serves, with
a valid console manifest from the previous build and an unchanged scheduler posture. Every signal
looks healthy. The digest is the one that does not.

`storage/` is excluded because it is runtime state that changes on every request; `.env` because it
is configuration, and a digest computed over secrets is a value derived from secrets travelling in a
report. `tests/`, `frontend/` and `docs/` are excluded because the deployment profile decides
whether they are copied at all, and a digest that varied with the profile could not be compared
between the checkout it was taken on and the host it is checked against.

It is not a signature and not a tamper seal — anyone who can change the files can recompute it. It
answers one question: is the code here the code that was preflighted.

### What it prints

Sanitized JSON. `APP_MASTER_KEY` and `DB_PASSWORD` are reported as `SET`, `MISSING` or `INVALID` —
never a value, never a length, never a prefix.

### Production mode does not exist

`--mode=staging` is the only mode. Production deployment is not authorized, and the pinned canonical
identity vocabulary defines no `CUSTOMER` tenant type, no `PRODUCTION` environment class and no
production workspace type — `ops/provision-staging.php` refuses `APP_ENV=production` before it opens
the database for exactly that reason. A preflight offering a production mode would be the first half
of a deployment path nobody has granted. See `docs/LOOPIA_CAPABILITY_REPORT.md` §5.

The future production runtime copy profile is described in `docs/DEPLOYMENT_RUNBOOK.md` §3.4 and is
**deferred**, not built.

---

## After the copy

Preflight validates a release. It says nothing about the deployment that results from it, because it
never speaks to the host. That is `ops/staging-smoke.php` — see
[`docs/BETA_RUNBOOK.md`](../../docs/BETA_RUNBOOK.md) Gate N — which exercises the deployed site over
real HTTPS and proves the things no in-process test can: that the document root points where the
runbook says, that the private tree is unreachable over HTTP, that TLS terminates, and that every
response carries the support reference.

A passing preflight authorizes nothing. It says the release is internally consistent and safe to
copy inertly; the deployment itself is still an operator act, and recording that it happened is a
separate reviewed edit to `deployment-evidence.json`.
