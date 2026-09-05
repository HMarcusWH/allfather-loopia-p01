<?php
/**
 * The workspace chooser.
 *
 * EVERY ROW IS ITS OWN FORM, carrying its own hidden workspace id. There is no select element and
 * no client-side state, because the console runs no script at all — and a POST whose target is a
 * hidden field the page rendered is exactly as untrusted on arrival as one a caller typed. The
 * server re-checks the membership either way.
 *
 * @var list<array<string, mixed>> $workspaces
 * @var ?string $activeWorkspaceId
 * @var ?string $error
 * @var array<string, mixed>|null $session
 */
use Allfather\Loopia\Http\View;
$tag = static fn (string $term, string $kind = 'state'): string =>
    (new View(__DIR__))->partial('_tag', ['term' => $term, 'kind' => $kind]);
?>
<header class="page__head">
  <h1 class="page__title">Workspaces</h1>
  <p class="page__lede">
    Which workspace this session is acting in. Selecting one changes what your questions are asked
    about — it grants nothing: every surface still checks the permission it needs.
  </p>
</header>

<?php if (($error ?? null) !== null): ?>
  <div class="notice notice--denied" role="alert">
    <strong>That workspace was not selected.</strong>
    <p class="notice__codes"><?= $tag($error) ?></p>
  </div>
<?php endif; ?>

<?php if ($workspaces === []): ?>
  <div class="notice notice--empty">
    You hold no active membership in any workspace of this tenant. A membership is granted by an
    operator with authority over the workspace; it is not something this surface can request.
  </div>
<?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Workspace</th>
          <th>Key</th>
          <th>Environment</th>
          <th>Capability ceiling</th>
          <th>External effects</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($workspaces as $workspace): ?>
          <?php $isActive = (string) $workspace['workspace_id'] === (string) ($activeWorkspaceId ?? ''); ?>
          <tr>
            <td><?= View::e($workspace['display_name']) ?></td>
            <td class="mono"><?= View::e($workspace['workspace_key']) ?></td>
            <td><?= $tag((string) $workspace['environment_class']) ?></td>
            <td><?= $tag((string) $workspace['active_v1_capability_ceiling'], 'action') ?></td>
            <td>
              <?= ((int) $workspace['external_side_effects_allowed']) === 1
                    ? $tag('ALLOWED')
                    : $tag('BLOCKED') ?>
            </td>
            <td>
              <?php if ($isActive): ?>
                <span class="tag is-ok">Current</span>
              <?php else: ?>
                <form method="post" action="/console/workspace">
                  <input type="hidden" name="csrf_token" value="<?= View::e($session['csrf_token'] ?? '') ?>">
                  <input type="hidden" name="workspace_id" value="<?= View::e($workspace['workspace_id']) ?>">
                  <button class="button" type="submit">Act in this workspace</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
