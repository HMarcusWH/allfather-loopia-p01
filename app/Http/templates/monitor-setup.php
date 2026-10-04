<?php
use Allfather\Loopia\Http\View;
/** Scriptless mission setup. Every value is rendered as text; setup grants no role and admits nothing. */
$csrf = $session['csrf_token'] ?? '';
$defaults = ['max_input_bytes' => '32768', 'max_output_tokens' => '1024', 'max_model_calls' => '4', 'max_observation_age_seconds' => '604800', 'retention_class' => 'standard', 'caching_policy' => 'short_cache'];
$v = static function (string $key) use ($form, $defaults): string { return $form[$key] !== '' ? $form[$key] : ($defaults[$key] ?? ''); };
$checked = static fn (string $key): string => ($form[$key] ?? '') === '1' ? ' checked' : '';
$option = static fn (string $key, string $value, string $label): string => '<option value="' . View::e($value) . '"' . ($v($key) === $value ? ' selected' : '') . '>' . View::e($label) . '</option>';
$tone = static fn (string $action): string => match ($action) { 'REUSE' => 'is-ok', 'CREATE' => 'is-neutral', 'CONFLICT', 'REFUSE' => 'is-refused', default => 'is-uncertain' };
?>
<header class="page__head">
  <h1 class="page__title">Monitor setup</h1>
  <p class="page__lede">Set up one real Monitor mission without hand-written SQL: plan it, review every object it would reuse or create, then apply exactly that plan.</p>
  <p><a href="/console/monitor">Open the Monitor workbench</a> · <a href="/console/monitor/schedule">Monitor schedules</a></p>
</header>
<div class="notice notice--empty">
  <strong>Setup grants nothing.</strong> It needs the roles you already hold and never grants one, subscribes a tenant, approves a source on its own, rotates a credential or admits native execution. Company-profile import and provider credentials stay private command-line acts (<span class="mono">ops/monitor/import-profile.php</span>, <span class="mono">ops/provider/credential.php</span>); this page only refers to them by id.
</div>
<?php if ($errorCodes !== []): ?>
<div class="notice notice--denied" role="alert"><strong>That did not complete.</strong>
  <p><?= View::e(implode(', ', $errorCodes)) ?><?php foreach (['field', 'missing_permissions'] as $k): if (isset($errorDetail[$k])): ?> — <?= View::e($k) ?>: <span class="mono"><?= View::e(is_array($errorDetail[$k]) ? implode(', ', $errorDetail[$k]) : (string) $errorDetail[$k]) ?></span><?php endif; endforeach; ?></p>
  <p>Support reference: <span class="mono"><?= View::e($requestId) ?></span></p>
  <?php if (in_array('NO_ACTIVE_WORKSPACE', $errorCodes, true)): ?><p><a href="/console/workspaces">Choose a workspace</a></p><?php endif; ?>
</div>
<?php endif; ?>

<?php if (is_array($receipt)): ?>
<section aria-labelledby="setup-receipt"><h2 id="setup-receipt">Setup receipt</h2>
<p>Setup <span class="mono"><?= View::e($receipt['setup_key']) ?></span> revision <?= View::e((string) $receipt['revision']) ?> configured mission <span class="mono"><?= View::e($receipt['mission_id']) ?></span> (configuration <span class="mono"><?= View::e($receipt['definition_digest']) ?></span>). It granted no role and admitted no native execution.</p>
<div class="table-wrap"><table><thead><tr><th>Step</th><th>Action</th><th>Object</th></tr></thead><tbody>
<?php foreach ($receipt['steps'] as $step): ?>
<tr><td><?= View::e($step['step']) ?></td><td><span class="tag <?= View::e($tone((string) $step['action'])) ?>"><?= View::e($step['action']) ?></span></td><td class="mono"><?= View::e((string) ($step['id'] ?? '')) ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<p>Next: an owner reviews whether this mission may run natively, and an admitted <span class="mono">MANUAL_MONITOR</span> grant is recorded through <span class="mono">ops/monitor/authorization.php</span>. Until then the workbench can inspect it but every native step is refused.</p>
</section>
<?php endif; ?>

<?php if (is_array($plan)): ?>
<section aria-labelledby="setup-plan"><h2 id="setup-plan">Review the plan</h2>
<p>Nothing has been written. Plan digest <span class="mono"><?= View::e($plan['plan_digest']) ?></span>, request digest <span class="mono"><?= View::e($plan['request_digest']) ?></span>, revision <?= View::e((string) ($plan['revision'] ?? 1)) ?>.</p>
<div class="table-wrap"><table><thead><tr><th>Step</th><th>Action</th><th>Object</th><th>Reason</th></tr></thead><tbody>
<?php foreach ($plan['steps'] as $step): ?>
<tr><td><?= View::e($step['step']) ?></td><td><span class="tag <?= View::e($tone((string) $step['action'])) ?>"><?= View::e($step['action']) ?></span></td><td class="mono"><?= View::e((string) ($step['id'] ?? '')) ?></td><td class="mono"><?= View::e((string) ($step['reason_code'] ?? '')) ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php if (($plan['blocked_by'] ?? []) !== []): ?>
<p class="notice notice--denied" role="alert">This plan cannot be applied: resolve every CONFLICT and REFUSE step first. Setup never overwrites, widens or re-grants something that already exists.</p>
<?php else: ?>
<form method="post" action="/console/monitor/setup" class="monitor-form">
  <input type="hidden" name="csrf_token" value="<?= View::e($csrf) ?>"><input type="hidden" name="action" value="apply">
  <input type="hidden" name="plan_digest" value="<?= View::e($plan['plan_digest']) ?>">
  <?php foreach ($fieldNames as $name): ?><input type="hidden" name="<?= View::e($name) ?>" value="<?= View::e($form[$name]) ?>"><?php endforeach; ?>
  <button class="button" type="submit">Apply exactly this plan</button><span>If anything changed since this plan was computed, apply refuses and you plan again.</span>
</form>
<?php endif; ?>
</section>
<?php endif; ?>

<section aria-labelledby="setup-form"><h2 id="setup-form">Plan a mission setup</h2>
<form method="post" action="/console/monitor/setup" class="monitor-form">
  <input type="hidden" name="csrf_token" value="<?= View::e($csrf) ?>"><input type="hidden" name="action" value="plan">
  <fieldset><legend>Mission</legend>
    <label for="su-key">Setup key (lowercase letters, digits, hyphens)</label><input id="su-key" name="setup_key" value="<?= View::e($v('setup_key')) ?>" required pattern="[a-z0-9][a-z0-9-]{2,62}" autocomplete="off">
    <label for="su-successor">Successor of setup receipt (only to change an existing setup)</label><input id="su-successor" name="successor_of" value="<?= View::e($v('successor_of')) ?>" autocomplete="off">
    <label for="su-title">Title</label><input id="su-title" name="mission_title" value="<?= View::e($v('mission_title')) ?>" required maxlength="160">
    <label for="su-objective">Objective</label><textarea id="su-objective" name="mission_objective" rows="3" required maxlength="600"><?= View::e($v('mission_objective')) ?></textarea>
  </fieldset>
  <fieldset><legend>Competitor</legend>
    <label for="su-comp">Name</label><input id="su-comp" name="competitor_display_name" value="<?= View::e($v('competitor_display_name')) ?>" required maxlength="160">
    <label for="su-class">Class</label><select id="su-class" name="competitor_class" required><?php foreach (['DIRECT', 'INDIRECT', 'ADJACENT', 'MARKET_REFERENCE', 'OTHER'] as $c) { echo $option('competitor_class', $c, $c); } ?></select>
    <label for="su-org">Organization number (optional)</label><input id="su-org" name="competitor_organization_number" value="<?= View::e($v('competitor_organization_number')) ?>" maxlength="32">
  </fieldset>
  <fieldset><legend>Source</legend>
    <label for="su-url">HTTPS page to observe</label><input id="su-url" name="source_url" type="url" value="<?= View::e($v('source_url')) ?>" required maxlength="2048">
    <label for="su-sname">Source name</label><input id="su-sname" name="source_name" value="<?= View::e($v('source_name')) ?>" required maxlength="160">
    <label for="su-license">License class</label><select id="su-license" name="license_class" required><?php foreach (['PUBLIC_WEB_ALLOWED', 'PUBLIC_WEB_RESTRICTED'] as $c) { echo $option('license_class', $c, $c); } ?></select>
    <label for="su-use">Allowed use</label><select id="su-use" name="allowed_use_class" required><?php foreach (['observe_only', 'evidence_capture', 'derived_summary_allowed'] as $c) { echo $option('allowed_use_class', $c, $c); } ?></select>
    <label><input type="checkbox" name="derived_summary_allowed" value="1"<?= $checked('derived_summary_allowed') ?>> Derived summaries allowed by the terms</label>
    <label><input type="checkbox" name="customer_output_allowed" value="1"<?= $checked('customer_output_allowed') ?>> Customer output allowed by the terms</label>
    <label for="su-cache">Caching policy</label><select id="su-cache" name="caching_policy" required><?php foreach (['no_cache', 'short_cache', 'standard_cache'] as $c) { echo $option('caching_policy', $c, $c); } ?></select>
    <label for="su-ret">Retention</label><select id="su-ret" name="retention_class" required><?php foreach (['short', 'standard'] as $c) { echo $option('retention_class', $c, $c); } ?></select>
  </fieldset>
  <fieldset><legend>Your source review</legend>
    <p>You attest that you reviewed this source's terms and robots policy. Setup records you as the reviewer and never fetches the page to decide for you.</p>
    <label for="su-at">Reviewed at (UTC, <span class="mono">YYYY-MM-DDTHH:MM:SS.sssZ</span>)</label><input id="su-at" name="review_approved_at" value="<?= View::e($v('review_approved_at')) ?>" required autocomplete="off">
    <label for="su-ref">Where the review evidence is kept</label><input id="su-ref" name="review_evidence_reference" value="<?= View::e($v('review_evidence_reference')) ?>" required maxlength="300">
    <label for="su-sha">SHA-256 of that evidence</label><input id="su-sha" name="review_evidence_sha256" value="<?= View::e($v('review_evidence_sha256')) ?>" required pattern="[A-Fa-f0-9]{64}" autocomplete="off">
    <label><input type="checkbox" name="robots_policy_checked" value="1"<?= $checked('robots_policy_checked') ?>> I checked the robots policy</label>
    <label><input type="checkbox" name="terms_policy_checked" value="1"<?= $checked('terms_policy_checked') ?>> I checked the terms of use</label>
  </fieldset>
  <fieldset><legend>Context and routing</legend>
    <label for="su-profiles">Imported company-profile evidence ids (one per line, up to four)</label><textarea id="su-profiles" name="profile_evidence_ids" rows="3" required><?= View::e($v('profile_evidence_ids')) ?></textarea>
    <label for="su-role">Internal target role</label><select id="su-role" name="target_org_role" required><?php foreach ($roles as $c) { echo $option('target_org_role', $c, $c); } ?></select>
    <label for="su-aud">Context audience (optional; SALES targets default to SALES)</label><select id="su-aud" name="audience"><option value="">Default</option><?php foreach ($audiences as $c) { echo $option('audience', $c, $c); } ?></select>
    <label for="su-red">Redaction rules (one per line)</label><textarea id="su-red" name="redaction_rules" rows="3"><?= View::e($v('redaction_rules')) ?></textarea>
    <label for="su-cred">Provider credential id</label><input id="su-cred" name="credential_id" value="<?= View::e($v('credential_id')) ?>" required autocomplete="off">
  </fieldset>
  <fieldset><legend>Context limits</legend>
    <label for="su-in">Most input bytes (≤ 65536)</label><input id="su-in" name="max_input_bytes" type="number" min="1" max="65536" value="<?= View::e($v('max_input_bytes')) ?>" required>
    <label for="su-out">Most output tokens (16–2048)</label><input id="su-out" name="max_output_tokens" type="number" min="16" max="2048" value="<?= View::e($v('max_output_tokens')) ?>" required>
    <label for="su-calls">Most model calls per run (≤ 4)</label><input id="su-calls" name="max_model_calls" type="number" min="1" max="4" value="<?= View::e($v('max_model_calls')) ?>" required>
    <label for="su-age">Oldest observation, seconds</label><input id="su-age" name="max_observation_age_seconds" type="number" min="1" max="31536000" value="<?= View::e($v('max_observation_age_seconds')) ?>" required>
  </fieldset>
  <button class="button" type="submit">Plan (read-only)</button>
</form>
</section>
