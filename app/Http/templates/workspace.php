<?php
/**
 * The workspace surface: what authority this operator holds, and what the system is holding back.
 *
 * @var array<string, mixed> $context
 * @var list<array<string, mixed>> $gaps
 * @var array<string, mixed> $session
 */
use Allfather\Loopia\Http\View;
$tag = static fn (string $term, string $kind = 'state'): string =>
    (new View(__DIR__))->partial('_tag', ['term' => $term, 'kind' => $kind]);
?>
<header class="page__head">
  <h1 class="page__title">Workspace</h1>
  <p class="page__lede">
    Your authority in this workspace, and anything currently blocking a release.
  </p>
</header>

<section class="section">
  <h2 class="section__title">Authority</h2>
  <div class="panel panel--authority">
    <div class="panel__head">
      <h3 class="panel__title">Roles and permissions</h3>
      <span class="panel__meta">generated <?= View::e($context['generated_at'] ?? '—') ?></span>
    </div>
    <?php if (($context['roles'] ?? []) === []): ?>
      <p class="notice notice--empty">
        No active role in this workspace. Every surface will deny, and each will say which
        permission it wanted.
      </p>
    <?php else: ?>
      <div class="tags" style="margin-bottom:12px">
        <?php foreach ($context['roles'] as $role): ?><?= $tag($role, 'authority') ?><?php endforeach; ?>
      </div>
      <div class="tags">
        <?php foreach ($context['permissions'] as $permission): ?><?= $tag($permission, 'authority') ?><?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="section">
  <h2 class="section__title">Release readiness</h2>
  <?php if ($gaps === []): ?>
    <div class="panel panel--governance">
      <p style="margin:0;color:var(--text-dim)">No blocking gap is recorded for this workspace.</p>
    </div>
  <?php else: ?>
    <div class="panel panel--governance">
      <div class="panel__head">
        <h3 class="panel__title">Blocking gaps</h3>
        <span class="panel__meta"><?= count($gaps) ?> open</span>
      </div>
      <p style="margin:0 0 12px;color:var(--text-dim)">
        A blocking gap is reported <em>before</em> anything else on this surface, because a
        readiness summary that led with what was ready would bury what is not.
      </p>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Gap</th><th>Severity</th><th>Summary</th></tr></thead>
          <tbody>
            <?php foreach ($gaps as $gap): ?>
              <tr>
                <td class="mono"><?= View::e($gap['gap_key'] ?? '') ?></td>
                <td><?= $tag((string) ($gap['severity'] ?? 'UNKNOWN')) ?></td>
                <td style="color:var(--text-dim)"><?= View::e($gap['safe_summary'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</section>
