<?php
/**
 * The decision ledger, as a timeline.
 *
 * Every entry is a governed act, and the chain position is shown because that is what makes the
 * ledger checkable rather than merely present.
 *
 * @var list<array<string, mixed>> $entries
 * @var ?string $verification
 */
use Allfather\Loopia\Http\View;
$tag = static fn (string $term, string $kind = 'state'): string =>
    (new View(__DIR__))->partial('_tag', ['term' => $term, 'kind' => $kind]);
?>
<header class="page__head">
  <h1 class="page__title">Decision ledger</h1>
  <p class="page__lede">
    Every governed decision in this workspace, in the order it was recorded, hash-chained to the
    one before it.
  </p>
</header>

<?php if ($verification !== null): ?>
  <div class="panel panel--governance" style="margin-bottom:20px">
    <div class="panel__head">
      <h3 class="panel__title">Chain verification</h3>
      <?= $tag($verification) ?>
    </div>
    <p style="margin:0;color:var(--text-dim)">
      Recomputed from the stored records, not read from a status column. A withdrawal appears here
      as its own record, so the chain covers the correction too.
    </p>
  </div>
<?php endif; ?>

<?php if ($entries === []): ?>
  <div class="notice notice--empty">No decision has been recorded in this workspace yet.</div>
<?php else: ?>
  <ol class="timeline">
    <?php foreach ($entries as $entry): ?>
      <li class="timeline__item">
        <div class="timeline__head">
          <span class="timeline__seq">#<?= View::e($entry['record_sequence_number'] ?? '') ?></span>
          <?= $tag((string) ($entry['record_type'] ?? 'UNKNOWN'), 'governance') ?>
          <?= $tag((string) ($entry['primary_outcome_status'] ?? 'UNKNOWN')) ?>
          <?php if (($entry['current_reliance_status'] ?? '') !== 'ACTIVE_AS_RECORDED'): ?>
            <?= $tag((string) $entry['current_reliance_status']) ?>
          <?php endif; ?>
        </div>
        <p class="timeline__summary"><?= View::e($entry['safe_decision_summary'] ?? '') ?></p>
        <dl class="kv" style="margin-top:8px">
          <dt>Recorded</dt><dd><?= View::e($entry['recorded_at'] ?? '') ?></dd>
          <dt>Payload digest</dt><dd><?= View::e($entry['record_payload_digest'] ?? '') ?></dd>
        </dl>
      </li>
    <?php endforeach; ?>
  </ol>
<?php endif; ?>
