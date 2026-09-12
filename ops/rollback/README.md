# Rollback and stand-down

**Rollback is never a delete.** The storage refuses one, and the reason is not squeamishness about
data: a rollback that destroyed the record would also destroy the proof that the rollback happened,
and an organization that cannot show what it withdrew and when has not withdrawn anything — it has
stopped talking about it.

So the two things this directory is about are strictly separate, and conflating them is the mistake
everything below is shaped to prevent:

| | What it is | How it is done |
| --- | --- | --- |
| **Application release rollback** | the CODE goes back to a previous known-good release | restore the tree, restore the matching Operator Console manifest, verify |
| **Governed withdrawal** | a RELEASED CLAIM stops being relied upon | append a correction: supersession, deactivation, revocation, an incident |

An application rollback touches no governed row. A governed withdrawal touches no code. Neither
deletes anything.

---

## 1. Before you start

**Do not run the migrations backwards.** There are no down-migrations here, deliberately. A schema
that went backwards past a migration a governed row depends on would take the row with it, and the
rollback would have become the erasure this whole discipline exists to prevent. If a release cannot
run against the current schema, the fix is a forward migration in a new release.

**Do not touch the runtime database.** A release rollback restores code. The staging runtime
database keeps every row it had, including rows written by the release being rolled back — those are
a record of what happened, which is true whether or not the release stays.

**Never point `tests/run.php` at the runtime database.** The harness truncates every application
table except `schema_migrations` between suites. On a host, the suite runs against the dedicated
qualification database and nothing else — see `docs/BETA_RUNBOOK.md` Gate G.

## 2. Application release rollback

1. **Identify the previous known-good release.** A full 40-character SHA whose CI run was green and
   whose deployment was recorded. `deployment-evidence.json` is where that is written down; if the
   release you are rolling back to has no `STAGING_DEPLOYMENT` record, you are choosing a release
   nobody has deployed, which is a different decision and deserves to be made deliberately.

2. **Preserve the current evidence.** Before anything moves, capture what the broken deployment
   looked like: the failing response, its `X-Request-ID`, the relevant private request-log lines, the
   output of `php ops/rollback/verify-stand-down.php` as it stands now. A rollback destroys the
   ability to reproduce the fault, which is exactly why the record is taken first.

3. **Restore the application tree** from a checkout at the previous SHA, with the same `rsync` the
   deployment runbook uses — `docs/DEPLOYMENT_RUNBOOK.md` §3. `.env` is excluded, as always: the
   configuration is the host's and a rollback must not silently change which database it points at.

4. **Restore the matching Operator Console assets, manifest last.** This is the step that is easiest
   to skip and the one whose failure looks healthiest. A restored application tree beside a NEWER
   staged bundle serves a document that expects one bundle and a browser that executes another. Copy
   the previous release's hashed files first, then replace `manifest.json` with one remote `mv`.

   Old content-hashed assets are retained rather than pruned, which is what makes this reversible in
   both directions: the files the previous manifest names are still there.

5. **Set `APP_RELEASE_REF` to the restored SHA**, in the host's private `.env`. A deployment that
   says it is running the release it was rolled back FROM will have every partner report quote a
   reference that resolves to the wrong code.

6. **Verify.**

       php ops/rollback/verify-stand-down.php \
         --expect-release=<the restored 40-character SHA> \
         --email=<your internal test account>

   Then, from outside:

       curl -sS -o /dev/null -w '%{http_code}\n' https://<domain>/healthz
       php ops/staging-smoke.php --base-url=https://<domain> --mode=anonymous

   And by hand: sign in with the test account, capture the `X-Request-ID` from a response, and find
   exactly that id in the private request log over SSH. A rollback that restored the code but not
   the ability to correlate a partner's report is a rollback that left support broken.

7. **Record it.** A `ROLLBACK_DRILL` record in `deployment-evidence.json` if this was a drill; a
   `STAGING_DEPLOYMENT` record for the restored release if it was real. Both are human edits, both
   reviewed — see `docs/DEPLOYMENT_EVIDENCE_MODEL.md` §7.

### What `verify-stand-down.php` checks

Read-only, and structurally so: no table, no file, no incident closed, no flag set. The most
dangerous thing a verifier could do is resolve the situation it was asked to describe.

- **The release the deployment believes it is running**, against the one you expected.
- **The Operator Console manifest** — present, valid JSON, naming an entry script.
- **The scheduler's posture.** Two separate facts, because a rollback can get one right and the
  other wrong: the handler registry is empty (a property of the restored CODE) and the endpoint is
  still POST-only; and an unauthenticated call is refused (a property of the restored
  CONFIGURATION). The door is *asked* rather than described — the verifier presents an empty token
  to the real `SchedulerAuth` and reports what a caller would actually get.
- **The live-read posture**, from the evidence registry rather than from a feature flag. "Live read
  is off" is a claim about what happened, and the thing that can answer it is the record of what
  happened.
- **One named test account** — lifecycle status, whether a password change is outstanding, how many
  sessions are live. `--email` names exactly one; there is no "check every account", because a
  verifier that enumerated logins would be a lifecycle oracle for the whole deployment.
- **M10 stream integrity, recomputed.** `decision_ledger_streams.status` is a column somebody could
  have written; `verifyStream` walks the chain. After a restore this is the difference between a
  ledger that verifies and one that merely says it does. Failures report the stream id and reason
  codes and never a record payload.

Exit 0 and `STAND_DOWN_VERIFIED` mean every check passed. Otherwise every finding is listed.

## 3. Governed withdrawal

Five levers, none of which is a delete. Each appends a fact that changes what the current state
means, and each is proved twice in `tests/rollback/stand_down_discipline.php`: that it takes effect,
and that the thing it withdrew is still there afterwards.

| Lever | Effect | Reversible? |
| --- | --- | --- |
| `M16::deactivateVisibility` | removes an artifact from the surface | yes, by a new activation |
| `EvidenceSpine::setSourceStatus(…, 'REVOKED')` | stops NEW captures immediately; evidence already captured stays valid as history | yes, by re-activating |
| `OutreachArtifacts::revokeAuthorization` | an outstanding permission to act can never be consumed | no — issue a new one |
| `DecisionLedger::supersede` | withdraws reliance on a release; the original stays byte-intact | no — restoring reliance is a NEW decision |
| `M11::openIncident` | records that something needs looking at | resolved, not deleted |

A **CONSUMED** authorization is not revocable. What it authorized already happened, and that is
historical fact — recorded truthfully, never un-recorded.

After any withdrawal, the chain must still verify **with the correction in it**. That is what makes
the withdrawal provable rather than merely performed, and it is what
`verify-stand-down.php`'s `m10_integrity` block answers.

## 4. Standing down live read

Not applicable yet: live read has never been activated, and nothing in this repository constructs
`CurlHttpsTransport` or `DnsHostResolver`. When it is, standing it down is the same shape as
everything else here — remove the wiring in a reviewed change, deploy that release, and append a
`LIVE_READ_STAND_DOWN` record. The registry is latest-wins per release, so the stand-down record is
what makes `live_read_activated` false again; deleting the activation record would be the one move
that is never correct, because it would erase the fact that it was ever on.

## 5. What a rollback does not do

- It does not delete a decision, an evidence object, a release or an audit record. The storage
  refuses it, independently of every lever above.
- It does not close an incident. Somebody looks at the incident; a command that closed it would be
  deciding that.
- It does not change a claim-boundary flag. Those are a human's to edit, reviewed, against evidence.
- It does not grant or withdraw authority. A rolled-back deployment has exactly the authority the
  restored release had.
