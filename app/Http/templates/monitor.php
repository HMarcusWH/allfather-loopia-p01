<?php
use Allfather\Loopia\Http\View;
/** All service data is rendered as text. Payload inspectors never interpret HTML or instructions. */
$json = static fn (mixed $data): string => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$csrf = $session['csrf_token'] ?? '';
$missions = $listing['missions'] ?? []; $runs = $listing['runs'] ?? []; $scheduledQueue = $listing['scheduled_queue'] ?? [];
$run = $detail['run'] ?? null; $preview = $detail['preview_request'] ?? null;
$stage = (string) ($run['stage'] ?? '');
$previewReason = (string) ($detail['preview_reason_code'] ?? '');
$terminal = in_array($stage, ['COMPLETE', 'BASELINE', 'NO_CHANGE', 'BLOCKED', 'FAILED', 'STOPPED'], true);
$needsReview = in_array($stage, ['REVIEW_REQUIRED', 'ROUTING_REVIEW'], true);
$reviewDigest = (string) ($detail['review_digest'] ?? '');
$requestDigest = is_array($preview) ? (string) ($preview['request_digest'] ?? $preview['digest'] ?? '') : '';
?>
<header class="page__head">
  <h1 class="page__title">Monitor workbench</h1>
  <p class="page__lede">Inspect the evidence and exact model request, advance a configured mission one step at a time, and review its draft before any internal release.</p>
  <p><a href="/console/app?view=p01">Return to Competition Monitor</a></p>
</header>
<div class="notice notice--empty">
  <strong>Internal workflow.</strong> A native run fetches a source or calls a model only under an admitted execution grant for its mission; without one, native steps are refused and nothing is fetched or sent. Fixture runs use synthetic observations and model responses in TEST workspaces. A draft, critic recommendation or run status is not a release or permission to act externally.
</div>
<?php if ($errorCodes !== []): ?>
<div class="notice notice--denied" role="alert"><strong>The requested step did not complete.</strong>
  <p><?= View::e(implode(', ', $errorCodes)) ?></p>
  <p>Support reference: <span class="mono"><?= View::e($requestId) ?></span></p>
  <?php if (in_array('NO_ACTIVE_WORKSPACE', $errorCodes, true)): ?><p><a href="/console/workspaces">Choose a workspace</a></p><?php endif; ?>
</div>
<?php endif; ?>

<section aria-labelledby="monitor-start"><h2 id="monitor-start">Configured missions</h2>
<?php if ($missions === []): ?>
  <p>No approved mission configuration is available in this workspace. Source scope and execution mode must be configured through the governed setup before a run can start.</p>
<?php else: ?>
  <form method="post" action="/console/monitor" class="monitor-form">
    <input type="hidden" name="csrf_token" value="<?= View::e($csrf) ?>">
    <input type="hidden" name="action" value="start">
    <input type="hidden" name="idempotency_key" value="<?= View::e($startKey) ?>">
    <label for="monitor-mission">Mission</label>
    <select id="monitor-mission" name="mission_id" required>
      <option value="">Choose a configured mission</option>
      <?php foreach ($missions as $mission): ?>
      <option value="<?= View::e($mission['mission_id']) ?>"><?= View::e($mission['title'] ?? $mission['display_name'] ?? $mission['name'] ?? $mission['mission_id']) ?> — <?= View::e($mission['execution_mode'] ?? $mission['mode'] ?? 'mode set by configuration') ?></option>
      <?php endforeach; ?>
    </select>
    <button class="button" type="submit">Create run</button>
  </form>
<?php endif; ?>
</section>

<?php if ($scheduledQueue !== []): ?>
<section aria-labelledby="monitor-queue"><h2 id="monitor-queue">Scheduled observations waiting for you</h2>
<p>A schedule captured and compared these sources and found a change. Nothing was sent to a model and nothing was released: each run waits at the request-inspection step until you open it, inspect the exact request and decide.</p>
<div class="table-wrap"><table><thead><tr><th>Run</th><th>Scheduled for</th><th>Missed slots</th><th>Queued</th></tr></thead><tbody>
<?php foreach ($scheduledQueue as $item): ?>
<tr><td><a href="/console/monitor?run=<?= View::e(rawurlencode((string) $item['monitor_run_id'])) ?>"><?= View::e($item['monitor_run_id']) ?></a></td>
<td><?= View::e($item['scheduled_for'] ?? '') ?></td><td><?= View::e((string) ($item['skipped_slots'] ?? 0)) ?></td><td><?= View::e($item['recorded_at'] ?? '') ?></td></tr>
<?php endforeach; ?></tbody></table></div>
</section>
<?php endif; ?>

<section aria-labelledby="monitor-runs"><h2 id="monitor-runs">Runs in this workspace</h2>
<?php if ($runs === []): ?><p>No runs recorded.</p><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Run</th><th>Mission</th><th>Mode</th><th>Stage</th><th>Updated</th></tr></thead><tbody>
<?php foreach ($runs as $item): ?>
<tr><td><a href="/console/monitor?run=<?= View::e(rawurlencode((string) $item['monitor_run_id'])) ?>"><?= View::e($item['monitor_run_id']) ?></a></td>
<td><?= View::e($item['mission_id'] ?? '') ?></td><td><?= View::e($item['execution_mode'] ?? '') ?></td>
<td><?= View::e($item['stage'] ?? '') ?></td><td><?= View::e($item['updated_at'] ?? '') ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?></section>

<?php if (is_array($run)): ?>
<section aria-labelledby="monitor-current"><h2 id="monitor-current">Selected run</h2>
  <p><strong><?= View::e($stage) ?></strong> · <?= View::e($run['execution_mode'] ?? '') ?> · Integrity: <?= View::e($detail['integrity'] ?? 'UNVERIFIED') ?></p>
  <?php if (($run['execution_mode'] ?? '') === 'FIXTURE'): ?><p class="notice">Synthetic fixture: these results do not demonstrate a live model or a real competitor observation.</p><?php endif; ?>
  <?php $admission = $detail['execution_admission'] ?? ['required' => false]; ?>
  <?php if (($admission['required'] ?? false) === true && ($admission['admitted'] ?? false) === true): ?>
  <p class="notice">Native execution is admitted for this mission under grant <span class="mono"><?= View::e($admission['execution_grant_id']) ?></span> until <?= View::e($admission['valid_until']) ?>. Model attempts used: <?= View::e((string) $admission['model_attempts']['used']) ?> of <?= View::e((string) $admission['model_attempts']['limit']) ?>. Source captures used: <?= View::e((string) $admission['source_captures']['used']) ?> of <?= View::e((string) $admission['source_captures']['limit']) ?>. Every step is re-checked; spent budget is not refunded.</p>
  <?php elseif (($admission['required'] ?? false) === true): ?>
  <p class="notice notice--denied">Native steps for this run are refused: <span class="mono"><?= View::e((string) ($admission['reason_code'] ?? 'EXECUTION_GRANT_NOT_ADMITTED')) ?></span>. You can still inspect it; nothing will be fetched or sent.</p>
  <?php endif; ?>
  <details><summary>Run configuration and progress</summary><pre class="monitor-json"><?= View::e($json($run)) ?></pre></details>

  <?php if ($previewReason !== ''): ?><p class="notice notice--denied" role="alert">The next request could not be prepared: <?= View::e($previewReason) ?>. This step cannot be submitted.</p><?php endif; ?>

  <?php if (is_array($preview)): ?>
  <h3>Request to review before the next step</h3>
  <p>This is the exact proposed request and prompt context. Continuing is bound to its digest; changed inputs must be inspected again.</p>
  <pre class="monitor-json"><?= View::e($json($preview)) ?></pre>
  <?php endif; ?>

  <?php if (!$terminal && !$needsReview && $previewReason === '' && (!is_array($preview) || $requestDigest !== '')): ?>
  <form method="post" action="/console/monitor" class="monitor-form">
    <input type="hidden" name="csrf_token" value="<?= View::e($csrf) ?>"><input type="hidden" name="action" value="advance">
    <input type="hidden" name="run_id" value="<?= View::e($run['monitor_run_id']) ?>">
    <?php if (is_array($preview)): ?><input type="hidden" name="request_digest" value="<?= View::e($requestDigest) ?>"><?php endif; ?>
    <button class="button" type="submit"><?= is_array($preview) ? 'Continue with this request' : 'Advance one step' ?></button>
  </form>
  <?php elseif (is_array($preview) && $requestDigest === ''): ?><p role="alert">Request integrity reference is missing. This step cannot be submitted.</p><?php endif; ?>

  <h3>Recorded requests, candidates and reviews</h3>
  <?php if (($detail['artifacts'] ?? []) === []): ?><p>No candidate artifacts have been recorded yet.</p><?php endif; ?>
  <?php foreach (($detail['artifacts'] ?? []) as $index => $artifact): ?>
  <details><summary><?= View::e(is_array($artifact) ? ($artifact['kind'] ?? $artifact['artifact_kind'] ?? 'Artifact ' . ((int) $index + 1)) : 'Artifact') ?></summary><pre class="monitor-json"><?= View::e($json($artifact)) ?></pre></details>
  <?php endforeach; ?>

  <?php if ($needsReview && $reviewDigest !== ''): ?>
  <h3>Human review</h3>
  <p>Review the evidence, draft and critic above. Your assessment is recorded against this exact candidate. Approval still passes the normal policy and release checks.</p>
  <form method="post" action="/console/monitor" class="monitor-form">
    <input type="hidden" name="csrf_token" value="<?= View::e($csrf) ?>"><input type="hidden" name="action" value="review">
    <input type="hidden" name="run_id" value="<?= View::e($run['monitor_run_id']) ?>"><input type="hidden" name="candidate_digest" value="<?= View::e($reviewDigest) ?>">
    <label for="monitor-decision">Decision</label><select id="monitor-decision" name="decision" required>
      <option value="">Choose your decision</option><option value="HOLD">Hold for more evidence</option><option value="REJECT">Reject this candidate</option><option value="APPROVE">Approve for governed internal release checks</option>
    </select>
    <label for="monitor-assessment">Your assessment</label><textarea id="monitor-assessment" name="assessment" rows="5" minlength="20" maxlength="4000" aria-describedby="monitor-assessment-help" required></textarea>
    <p id="monitor-assessment-help">Write your own assessment (at least 20 characters; up to 4,000 bytes). Explain the evidence, uncertainty and intended internal audience.</p>
    <button class="button" type="submit">Record human review</button>
  </form>
  <?php elseif ($needsReview): ?><p role="alert">The candidate integrity reference is missing; review cannot be submitted.</p><?php endif; ?>

  <?php if (!$terminal): ?>
  <form method="post" action="/console/monitor" class="monitor-form">
    <input type="hidden" name="csrf_token" value="<?= View::e($csrf) ?>"><input type="hidden" name="action" value="stop">
    <input type="hidden" name="run_id" value="<?= View::e($run['monitor_run_id']) ?>">
    <button class="button" type="submit">Stop this run</button><span>Recorded evidence and history are preserved.</span>
  </form>
  <?php endif; ?>

  <?php if (($detail['current_reliance'] ?? []) !== []): ?>
  <h3>Current release status</h3>
  <p>This is read from the decision ledger now. A completed run remains historical if its release is later withdrawn or superseded.</p>
  <pre class="monitor-json"><?= View::e($json($detail['current_reliance'])) ?></pre>
  <?php endif; ?>

  <details><summary>Recorded event history</summary><pre class="monitor-json"><?= View::e($json($detail['events'] ?? [])) ?></pre></details>
</section>
<?php endif; ?>
