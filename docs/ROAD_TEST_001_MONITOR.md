# ROAD_TEST_001 — manual native Monitor

This is the P06 operator protocol for the first real-source, real-model Monitor mission on staging.
It is not authority and no step is performed by merging the code. The road test is manual; the
scheduler remains empty and the two human reviews remain human.

## 0. Hard prerequisites

Do not begin until all of these are independently true:

1. The exact candidate has complete MariaDB 10.6 and 10.11 qualification.
2. The exact candidate is deployed inertly and passes the host probe, ordinary staging smoke and
   ops/staging-monitor-smoke.php.
3. P10 has been exercised operationally: a real encrypted native backup was retained off-host and
   restored into a different, initially empty disposable target with populated history.
4. The P10 rehearsal has proved the actual Loopia trigger/DEFINER and database-principal behavior
   needed by the disposable restore target. Do not assume a second Loopia database can use the same
   principal merely because local MariaDB can.
5. Provider quota and provider-side data/retention posture have been reviewed for the exact
   synthetic evaluation and later real mission.
6. No scheduler handler is registered and no external send/publish/CRM/payment path exists.

Unknown means BLOCKED, not probably ready.

## 1. Freeze and qualify the exact release

Record the full release SHA, prepared-release source fingerprint, Operator Console manifest digest,
deployed host, and observed MariaDB/PHP versions. Run the normal release qualification and deployment
preflight. A LOCAL receipt remains local; a host observation remains a host observation.

## 2. Qualify the deployed Monitor surface

Use the same hardened HTTPS client as the ordinary staging smoke:

    read -rs PW
    printf '%s\n' "$PW" | php ops/staging-monitor-smoke.php \
      --base-url=https://staging.allfatherbi.com \
      --email=<operator-email> \
      --password-stdin
    unset PW

The Monitor smoke performs negative boundary checks only: workbench reachability, session-scoped
workspace behavior, CSRF refusal, body-only run identity and refusal of review/advance calls that
have no inspected run. It does not fetch a source, call a model, submit a human review or start a
road test.

## 3. Prove the deployment is inert before authority

Run ops/monitor/preflight.php for the exact tenant/workspace/mission before any execution grant is
admitted. At this point the expected posture is INERT_STAGING. A MANUAL_PILOT_READY posture here
would mean execution authority already exists and must be explained before continuing.

The preflight is a description, not permission, and a successful inspection must not manufacture an
ALLOW audit row.

## 4. Bounded real-model evaluation

Use only the frozen synthetic/non-sensitive evaluation manifest.

1. Prepare the exact MODEL_EVALUATION grant.
2. Review its digest and apply the separate owner decision for that exact grant.
3. Start and preview the evaluation.
4. Inspect exact request digests before execution.
5. Execute inside the bounded model-attempt budget.
6. Record required human grounding/usefulness assessments.
7. Produce the evaluation report.

The evaluation lane has no source-fetch or product-release authority. An accepted report approves
nothing by itself.

## 5. Separate prompt approvals

For P01_SIGNAL_CLASSIFIER, BATTLECARD_GENERATOR, MONITOR_REVIEW_CRITIC and
MONITOR_ROUTING_CRITIC, apply separately reviewed MANUAL_MONITOR prompt approvals citing the
accepted live evaluation for the exact prompt/schema/model-policy identity. Do not edit the immutable
prompt bundle in place.

## 6. Repeatable mission setup

Use ops/monitor/setup.php, not hand-written SQL. Freeze the mission/source, capture URL,
company/profile evidence selection, provider credential identity, internal target role, P01/P04
policy identities, model governance and prompt selection. Setup grants no execution.

## 7. Admit one bounded manual grant

Prepare and inspect the exact MANUAL_MONITOR grant. Bind the frozen mission definition, release/source
fingerprint, prompt approvals, credential, model policy, source, target and bounded source/model
budgets. Apply the owner decision only after the digest is inspected. Do not create a
SCHEDULED_OBSERVATION grant for ROAD_TEST_001.

After admission, run ops/monitor/preflight.php again for the exact tenant/workspace/mission. Now and
only now require MANUAL_PILOT_READY. It must describe one current MANUAL_MONITOR grant whose release,
configuration, principal permissions, entitlement, credential, prompt/evaluation evidence and
remaining two-capture/four-attempt budget all still match. This second preflight still grants
nothing.

## 8. Execute manually in /console/monitor

The Monitor baseline and changed observation are **two runs under the same bounded grant**.
A baseline run becomes terminal at BASELINE after its first source capture; the changed run is the one
that proceeds through DIFFED, the four model stages and both human reviews.

The intended sequence is:

1. start a first NATIVE **baseline run** with its own idempotency key;
2. advance it once; require stage BASELINE and retain its source snapshot/evidence;
3. after the approved observation interval or known source change, start a second NATIVE
   **changed run** with a different idempotency key;
4. advance the changed run once to capture the new source state;
5. advance it again to classify the diff; require a material change before model execution;
6. inspect the classifier request digest and advance;
7. inspect the draft request digest and advance;
8. inspect the review-critic request digest and advance;
9. human review #1 against the displayed candidate digest;
10. verify the P01/M10/M16 internal release;
11. advance once to prepare routing;
12. inspect the routing-critic request digest and advance;
13. human review #2 against the displayed candidate digest;
14. verify the P04/M10 internal distribution release;
15. confirm the changed run reaches COMPLETE.

The two source-capture budget units are therefore consumed by two distinct run IDs. The four model
attempts belong to the changed run. Use the **changed run ID** for the ROAD_TEST evidence export; the
grant-wide consumption summary ties it back to the baseline capture.

A model critic is advisory. It is not either human review. Email, SMS, CRM writes, publishing,
payments, public output and customer-visible authority are outside this road test.

## 9. Stand down immediately

As soon as the terminal road-test state has been inspected, suspend or revoke every current or
future-admitted Monitor execution grant. Do not leave execution authority live while preparing the
evidence package.

Then run ops/rollback/verify-stand-down.php for the exact running release/fingerprint. Require M10
integrity, every Monitor journal to replay, an empty scheduler handler registry and no active
maintenance operation. Preserve released internal history; do not delete history to make stand-down
green.

## 10. Export the sanitized evidence summary

After stand-down is verified:

    php ops/road-test/export-monitor-evidence.php \
      --env=/private/staging.env \
      --tenant-id=<tenant> \
      --workspace-id=<workspace> \
      --operator-id=<admin-operator> \
      --run-id=<monitor-run-id> \
      --expect-release=<40-char-release-sha>

The exporter emits identifiers, digests, states, counts and current-reliance results. It does not
emit source bodies, company-profile text, candidate text, human assessment text, credentials,
cookies, CSRF/session values or private filesystem paths. It does not decide PASS or FAIL and does
not modify deployment-evidence.json or upstream.lock.json. Seeing the grant as SUSPENDED or REVOKED
is expected; history is not reactivation.

## 11. Prove recovery over populated road-test history

Run P10 again against the populated staging state. Create the encrypted native backup, retain it
off-host with its digest recorded independently, restore to a different initially empty target and
verify database/artifact/request-log/M10/Monitor/output state with zero current or future-admitted
execution grants.

## 12. File evidence in a separate reviewed PR

Only after observations exist should a later PR add the applicable CONFORMANCE, STAGING_DEPLOYMENT,
LOOPIA_PROBE_001, STAGING_SMOKE, LIVE_READ_ACTIVATION, ROAD_TEST_001, LIVE_READ_STAND_DOWN and
ROLLBACK_DRILL records.

ROAD_TEST execution and ROAD_TEST success are different facts. No evidence record automatically
grants owner GO, production launch, external action or customer-visible output.
