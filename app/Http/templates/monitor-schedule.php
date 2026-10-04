<?php
use Allfather\Loopia\Http\View;
/** Scriptless schedule administration. Every value is rendered as text; nothing here admits a schedule. */
$csrf = $session['csrf_token'] ?? '';
$schedules = $overview['schedules'] ?? []; $grants = $overview['grants'] ?? [];
$input = is_array($plan) ? ($plan['input'] ?? []) : [];
$field = static fn (string $key): string => is_scalar($input[$key] ?? null) ? (string) $input[$key] : '';
$cadenceLabel = static fn (int $s): string => $cadences[$s] ?? (intdiv($s, 3600) . ' hours');
?>
<header class="page__head">
  <h1 class="page__title">Monitor schedules</h1>
  <p class="page__lede">Plan and propose a recurring observation for a configured native mission, and stand one down. A schedule observes and queues changes for you; it never calls a model, never inspects a request and never records a review.</p>
  <p><a href="/console/monitor">Open the Monitor workbench</a></p>
</header>
<div class="notice notice--empty">
  <strong>Proposing is not admitting.</strong> A proposed schedule does nothing. It runs only after a reviewed owner decision in the deployed tree lists its exact digest and its <span class="mono">SCHEDULED_OBSERVATION</span> grant is itself admitted — a private command-line act, not a button here. This repository ships no such decision.
</div>
<?php if ($errorCodes !== []): ?>
<div class="notice notice--denied" role="alert"><strong>That did not complete.</strong>
  <p><?= View::e(implode(', ', $errorCodes)) ?><?php if (isset($errorDetail['detail']) && is_string($errorDetail['detail'])): ?> (<?= View::e($errorDetail['detail']) ?>)<?php endif; ?></p>
  <p>Support reference: <span class="mono"><?= View::e($requestId) ?></span></p>
  <?php if (in_array('NO_ACTIVE_WORKSPACE', $errorCodes, true)): ?><p><a href="/console/workspaces">Choose a workspace</a></p><?php endif; ?>
</div>
<?php endif; ?>

<section aria-labelledby="schedule-list"><h2 id="schedule-list">Schedules in this workspace</h2>
<?php if ($schedules === []): ?><p>No schedule has been proposed in this workspace.</p><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Version</th><th>Status</th><th>Usable now</th><th>Cadence</th><th>Occurrences</th><th>Window</th><th>Digest</th></tr></thead><tbody>
<?php foreach ($schedules as $s): ?>
<tr<?= $s['monitor_schedule_version_id'] === $selected ? ' aria-current="true"' : '' ?>>
<td><span class="mono"><?= View::e($s['monitor_schedule_version_id']) ?></span><br>series <span class="mono"><?= View::e($s['monitor_schedule_id']) ?></span> v<?= View::e((string) $s['schedule_version']) ?></td>
<td><span class="tag <?= View::e(View::tone((string) $s['status'])) ?>"><?= View::e($s['status']) ?></span></td>
<td><?= $s['usable_now'] ? 'Yes' : 'No — <span class="mono">' . View::e((string) $s['not_usable_reason']) . '</span>' ?></td>
<td><?= View::e($cadenceLabel((int) $s['cadence_seconds'])) ?></td>
<td><?= View::e((string) $s['occurrences']) ?> of <?= View::e((string) $s['max_occurrences']) ?></td>
<td><?= View::e($s['valid_from']) ?> → <?= View::e($s['valid_until']) ?></td>
<td><span class="mono"><?= View::e($s['schedule_digest']) ?></span></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<?php foreach ($schedules as $s): if ($s['monitor_schedule_version_id'] !== $selected) { continue; } ?>
<h3>Selected version</h3>
<p>Mission <span class="mono"><?= View::e($s['mission_id']) ?></span> under grant <span class="mono"><?= View::e($s['execution_grant_id']) ?></span>; the scheduler identity <span class="mono"><?= View::e($s['service_identity_id']) ?></span> would act for operator <span class="mono"><?= View::e($s['delegating_operator_id']) ?></span>.</p>
<?php if ($s['status'] === 'PROPOSED'): ?>
<p>To admit it, an owner records a decision listing this digest under <span class="mono">authorizes.monitor_schedules</span>, it is reviewed and deployed under <span class="mono">docs/decisions/</span>, and then an operator runs:</p>
<pre class="monitor-json">php ops/monitor/schedule.php admit --schedule-version-id=<?= View::e($s['monitor_schedule_version_id']) ?> --decision=docs/decisions/&lt;file&gt;.json --decision-sha256=&lt;sha256&gt; --env=&lt;private env&gt; --tenant-id=… --workspace-id=… --operator-id=…</pre>
<?php endif; ?>
<?php endforeach; ?>
</section>

<?php if ($schedules !== []): ?>
<section aria-labelledby="schedule-stand-down"><h2 id="schedule-stand-down">Stand a schedule down</h2>
<p>Suspending stops new occurrences of an admitted version; revoking ends a version for good. Neither deletes an occurrence or changes a run already recorded.</p>
<form method="post" action="/console/monitor/schedule" class="monitor-form">
  <input type="hidden" name="csrf_token" value="<?= View::e($csrf) ?>">
  <label for="sd-version">Schedule version</label>
  <select id="sd-version" name="schedule_version_id" required><option value="">Choose a version</option>
  <?php foreach ($schedules as $s): if (in_array($s['status'], ['REVOKED'], true)) { continue; } ?>
    <option value="<?= View::e($s['monitor_schedule_version_id']) ?>"><?= View::e($s['monitor_schedule_version_id']) ?> — <?= View::e($s['status']) ?></option>
  <?php endforeach; ?></select>
  <label for="sd-reason">Reason</label>
  <select id="sd-reason" name="reason_code" required><option value="">Choose a reason</option>
  <?php foreach ($reasons as $reason): ?><option value="<?= View::e($reason) ?>"><?= View::e($reason) ?></option><?php endforeach; ?></select>
  <button class="button" type="submit" name="action" value="suspend">Suspend</button>
  <button class="button" type="submit" name="action" value="revoke">Revoke</button>
</form>
</section>
<?php endif; ?>

<section aria-labelledby="schedule-plan"><h2 id="schedule-plan">Plan a schedule version</h2>
<?php if ($grants === []): ?>
<p>No <span class="mono">SCHEDULED_OBSERVATION</span> grant exists in this workspace. A schedule needs one for its mission's frozen native configuration; grants are proposed and admitted through <span class="mono">ops/monitor/authorization.php</span>.</p>
<?php else: ?>
<p>Planning is read-only: it validates the definition against the grant, the frozen configuration, the scheduler identity and the source's own cadence floor, and shows the digest a decision would have to list.</p>
<form method="post" action="/console/monitor/schedule" class="monitor-form">
  <input type="hidden" name="csrf_token" value="<?= View::e($csrf) ?>"><input type="hidden" name="action" value="plan">
  <label for="sc-grant">Scheduled-observation grant</label>
  <select id="sc-grant" name="execution_grant_id" required><option value="">Choose a grant</option>
  <?php foreach ($grants as $g): ?>
    <option value="<?= View::e($g['execution_grant_id']) ?>"<?= $field('execution_grant_id') === $g['execution_grant_id'] ? ' selected' : '' ?>><?= View::e($g['execution_grant_id']) ?> — mission <?= View::e($g['mission_id']) ?> — <?= View::e($g['status']) ?> until <?= View::e($g['valid_until']) ?></option>
  <?php endforeach; ?></select>
  <label for="sc-series">Existing series (only for a successor version)</label>
  <input id="sc-series" name="monitor_schedule_id" value="<?= View::e($field('monitor_schedule_id')) ?>" pattern="[0-9a-f-]{36}" autocomplete="off">
  <label for="sc-service">Scheduler service identity</label>
  <input id="sc-service" name="service_identity_id" value="<?= View::e($field('service_identity_id')) ?>" required pattern="[0-9a-f-]{36}" autocomplete="off" aria-describedby="sc-service-help">
  <p id="sc-service-help">A tenant-bound <span class="mono">SYSTEM_SCHEDULER</span> identity with its own workspace membership. It acts for the grant's operator and never more than that operator may.</p>
  <label for="sc-cadence">Cadence</label>
  <select id="sc-cadence" name="cadence_seconds" required>
  <?php foreach ($cadences as $seconds => $label): ?><option value="<?= View::e((string) $seconds) ?>"<?= $field('cadence_seconds') === (string) $seconds ? ' selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?></select>
  <label for="sc-max">Most occurrences</label>
  <input id="sc-max" name="max_occurrences" type="number" min="1" max="500" value="<?= View::e($field('max_occurrences')) ?>" required>
  <label for="sc-from">Valid from (UTC, <span class="mono">YYYY-MM-DDTHH:MM:SS.sssZ</span>)</label>
  <input id="sc-from" name="valid_from" value="<?= View::e($field('valid_from')) ?>" required autocomplete="off">
  <label for="sc-until">Valid until</label>
  <input id="sc-until" name="valid_until" value="<?= View::e($field('valid_until')) ?>" required autocomplete="off">
  <button class="button" type="submit">Plan this version</button>
</form>
<?php endif; ?>
</section>

<?php if (is_array($plan) && isset($plan['schedule_digest'])): $d = $plan['definition']; ?>
<section aria-labelledby="schedule-review"><h2 id="schedule-review">Review the planned version</h2>
<dl class="facts">
  <dt>Schedule digest</dt><dd class="mono"><?= View::e($plan['schedule_digest']) ?></dd>
  <dt>Mission and configuration</dt><dd><span class="mono"><?= View::e($d['mission_id']) ?></span> · <span class="mono"><?= View::e($d['definition_digest']) ?></span></dd>
  <dt>Acts as</dt><dd>service <span class="mono"><?= View::e($d['service_identity_id']) ?></span> for operator <span class="mono"><?= View::e($d['delegating_operator_id']) ?></span></dd>
  <dt>Cadence</dt><dd><?= View::e($cadenceLabel((int) $d['cadence_seconds'])) ?> (source floor <?= View::e((string) intdiv((int) ($d['source_minimum_fetch_interval_ms'] ?? 0), 1000)) ?> s), catch-up <?= View::e($d['catch_up_policy']) ?></dd>
  <dt>Bounds</dt><dd>at most <?= View::e((string) $d['max_occurrences']) ?> occurrences, <?= View::e($d['valid_from']) ?> → <?= View::e($d['valid_until']) ?></dd>
  <dt>Mode</dt><dd><?= View::e($d['observation_mode']) ?> — no model call, no review, no release</dd>
</dl>
<details><summary>Full normalized definition</summary><pre class="monitor-json"><?= View::e(json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?></pre></details>
<form method="post" action="/console/monitor/schedule" class="monitor-form">
  <input type="hidden" name="csrf_token" value="<?= View::e($csrf) ?>"><input type="hidden" name="action" value="propose">
  <input type="hidden" name="schedule_digest" value="<?= View::e($plan['schedule_digest']) ?>">
  <?php foreach (['execution_grant_id', 'monitor_schedule_id', 'service_identity_id', 'cadence_seconds', 'max_occurrences', 'valid_from', 'valid_until'] as $key): ?>
  <input type="hidden" name="<?= View::e($key) ?>" value="<?= View::e($field($key)) ?>">
  <?php endforeach; ?>
  <button class="button" type="submit">Propose exactly this version</button><span>Recorded as PROPOSED. It grants nothing.</span>
</form>
</section>
<?php endif; ?>
