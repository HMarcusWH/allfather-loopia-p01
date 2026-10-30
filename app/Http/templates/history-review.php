<?php
use Allfather\Loopia\Http\View;

/** UI-02 private historical review preview. Every value is escaped. */
$rows=($read['decision']??null)==='VISIBLE' ?($read['data']['rows']??[]):[];
$state=(string)($read['decision']??'UNAVAILABLE');
$csrf=(string)($session['csrf_token']??'');
$planned=is_array($command) && isset($command['plan_digest'])
    && ($command['status']??null)==='REVIEWED_COMMAND_CANDIDATE_ONLY';
?>
<header class="page__head">
  <h1 class="page__title">Historical review workbench</h1>
  <p class="page__lede">Operator-only, source-bound review metadata. Human factual and delivery approvals are separate; neither is granted by a proposal.</p>
  <p><a href="/console/app?view=p01">Competition Monitor</a> ·
    <a href="/console/app?view=p02">Company and entity context</a> ·
    <a href="/console/app?view=p03">ICP, offers and prospects</a> ·
    <a href="/console/app?view=p04">Role briefs</a> ·
    <a href="/console/app?view=p05">Drafts</a> ·
    <a href="/console/decisions">M10 decisions</a></p>
</header>
<div class="notice notice--empty">
  <strong>Unreleased historical intelligence.</strong>
  Historical findings, evidence counts, critic results and attention candidates are not a source-use grant, approved business fact, M10 release or permission to notify customers. This review workbench is not registered for live users.
</div>
<?php if ($errorCodes!==[]): ?>
  <p role="alert" class="notice notice--denied"><?= View::e(implode(', ',$errorCodes)) ?>. Support reference <?= View::e($requestId) ?>.</p>
<?php endif; ?>
<section aria-labelledby="review-state">
  <h2 id="review-state">Scoped review results</h2>
  <?php if ($state!=='VISIBLE'): ?>
    <p role="status"><?= View::e($state) ?>:
      <?= View::e(implode(', ',$read['reason_codes']??['UI02_NO_OWNER_READ'])) ?>.
      Missing metadata is not evidence of no competitor change.</p>
  <?php elseif ($rows===[]): ?>
    <p role="status">No candidate reviews are available on this scoped page. No historical conclusion is implied.</p>
  <?php else: ?>
    <?php foreach($rows as $row): ?>
      <article class="surface-panel" aria-label="Historical finding">
        <h3>Finding <span class="mono"><?= View::e($row['review_id']) ?></span></h3>
        <p><span class="tag is-held"><?= View::e($row['status']) ?></span> ·
          review due: <?= View::e($row['review_due_status']) ?> ·
          source rights: <?= View::e($row['historical_source_rights']) ?></p>
        <dl>
          <dt>As-of basis</dt><dd class="mono"><?= View::e($row['basis_digest']) ?></dd>
          <dt>Known-at cutoff</dt><dd><?= View::e($row['knowledge_cutoff_utc']) ?></dd>
          <dt>Analyst digest</dt><dd class="mono"><?= View::e($row['analyst_digest']) ?></dd>
          <dt>Critic digest</dt><dd class="mono"><?= View::e($row['critic_digest']) ?></dd>
          <dt>Later reported outcome</dt><dd><?= View::e($row['outcome_status']) ?> (<?= View::e($row['outcome_adjudication']) ?>)</dd>
          <dt>Attention</dt><dd><?= View::e($row['attention_status']) ?></dd>
          <dt>Contrary references</dt><dd><?= View::e($row['contrary_count']) ?> of <?= View::e($row['selected_count']) ?></dd>
          <dt>Reviewed revision</dt><dd class="mono"><?= View::e($row['revision']) ?></dd>
        </dl>
        <h4>Exact evidence references (metadata only)</h4>
        <div class="table-wrap"><table>
          <thead><tr><th>Reference</th><th>Source</th><th>Polarity</th><th>Known at</th><th>Support claim</th></tr></thead>
          <tbody><?php foreach($row['primary_references'] as $ref): ?>
            <tr><td class="mono"><?= View::e($ref['ref_id']) ?></td>
            <td class="mono"><?= View::e($ref['source_ref_id']) ?></td>
            <td><?= View::e($ref['polarity']) ?></td>
            <td><?= View::e($ref['known_at_utc']) ?></td>
            <td><?= $ref['cited_as_support']?'Yes':'No' ?></td></tr>
          <?php endforeach; ?></tbody>
        </table></div>
        <h4>Reviewed command candidate</h4>
        <p>No native human approval, provider dispatch, scheduled run, P02 change or customer release can be made here.</p>
        <form action="/console/history-review" method="post" aria-label="Plan review candidate">
          <input type="hidden" name="csrf_token" value="<?= View::e($csrf) ?>">
          <input type="hidden" name="competitor" value="<?= View::e($competitor) ?>">
          <input type="hidden" name="question" value="<?= View::e($question) ?>">
          <input type="hidden" name="expected_revision" value="<?= View::e($row['revision']) ?>">
          <input type="hidden" name="command_mode" value="plan">
          <label>Review action
            <select name="review_action" required>
              <?php foreach($actions as $action): ?><option value="<?= View::e($action) ?>"><?= View::e($action) ?></option><?php endforeach; ?>
            </select>
          </label>
          <label>Reason code
            <select name="reason_code" required>
              <?php foreach($reasons as $reason): ?><option value="<?= View::e($reason) ?>"><?= View::e($reason) ?></option><?php endforeach; ?>
            </select>
          </label>
          <button type="submit">Inspect candidate plan</button>
        </form>
      </article>
    <?php endforeach; ?>
    <?php $next=$read['data']['next_page']??null; if(is_int($next)): ?>
      <p><a href="/console/history-review?competitor=<?= rawurlencode($competitor) ?>&amp;question=<?= rawurlencode($question) ?>&amp;page=<?= View::e($next) ?>">Next bounded review page</a></p>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php if($planned): ?>
  <section class="surface-panel" aria-label="Reviewed command plan">
    <h2>Exact candidate plan</h2>
    <p class="mono"><?= View::e($command['plan_digest']) ?></p>
    <p>No business-state mutation is authorized. The plan binds the exact review revision, signed-in operator and reason.</p>
    <form method="post" action="/console/history-review">
      <?php foreach([
        'csrf_token'=>$csrf,'competitor'=>$competitor,'question'=>$question,
        'expected_revision'=>$command['expected_review_revision'],
        'review_action'=>$command['action'],'reason_code'=>$command['reason_code'],
        'plan_digest'=>$command['plan_digest'],'command_mode'=>'propose',
      ] as $key=>$val): ?>
        <input type="hidden" name="<?= View::e($key) ?>" value="<?= View::e($val) ?>">
      <?php endforeach; ?>
      <button type="submit">Record synthetic review-work proposal</button>
    </form>
  </section>
<?php elseif(is_array($command)): ?>
  <p role="status">Fixture-only work candidate: <?= View::e($command['status']??'UNKNOWN') ?>.
    No actual human review, M10 decision, source action or customer release occurred.</p>
<?php endif; ?>
