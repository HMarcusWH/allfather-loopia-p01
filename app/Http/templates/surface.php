<?php
/**
 * A gated release surface: briefings, role distributions, or marketing drafts.
 *
 * THE DENIAL IS THE INTERESTING CASE, and it is rendered first. A denied surface shows an EMPTY
 * list and the reason codes the guard actually returned — not a generic "no access", because an
 * operator who cannot see which of several conditions refused cannot fix it.
 *
 * @var string $heading
 * @var string $lede
 * @var array<string, mixed> $result   decision / required_permission / reason_codes / rows
 * @var list<array{key: string, label: string, kind?: string}> $columns
 * @var ?string $roleNote
 */
use Allfather\Loopia\Http\View;
$tag = static fn (string $term, string $kind = 'state'): string =>
    (new View(__DIR__))->partial('_tag', ['term' => $term, 'kind' => $kind]);
?>
<header class="page__head">
  <h1 class="page__title"><?= View::e($heading) ?></h1>
  <p class="page__lede"><?= View::e($lede) ?></p>
</header>

<?php if (($roleNote ?? null) !== null): ?>
  <div class="panel panel--authority" style="margin-bottom:16px">
    <p style="margin:0;color:var(--text-dim)"><?= View::e($roleNote) ?></p>
  </div>
<?php endif; ?>

<?php if ($result['decision'] !== 'VISIBLE'): ?>
  <div class="notice notice--denied" role="status">
    <strong>Not visible to you.</strong>
    This surface requires <code><?= View::e($result['required_permission']) ?></code>, and the
    list is empty rather than partial — a denial that still showed a summary line would be a denial
    in name only.
    <p class="notice__codes">
      <?php foreach ($result['reason_codes'] as $code): ?><?= $tag($code) ?> <?php endforeach; ?>
    </p>
  </div>
<?php elseif ($result['rows'] === []): ?>
  <div class="notice notice--empty">
    You are authorized for this surface, and nothing is currently released to it.
  </div>
<?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><?php foreach ($columns as $column): ?><th><?= View::e($column['label']) ?></th><?php endforeach; ?></tr>
      </thead>
      <tbody>
        <?php foreach ($result['rows'] as $row): ?>
          <tr>
            <?php foreach ($columns as $column): ?>
              <?php $value = (string) ($row[$column['key']] ?? ''); ?>
              <?php if (($column['kind'] ?? null) !== null): ?>
                <td><?= $value === '' ? '<span class="tag is-neutral">—</span>' : $tag($value, $column['kind']) ?></td>
              <?php elseif (($column['mono'] ?? false) === true): ?>
                <td class="mono"><?= View::e($value) ?></td>
              <?php else: ?>
                <td><?= View::e($value) ?></td>
              <?php endif; ?>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
