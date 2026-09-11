<?php
/**
 * This account, and only this account.
 *
 * WHAT IS ABSENT IS THE DESIGN. No password hash, no algorithm name, no session token, no session
 * identifier, no CSRF value except the one this page's own forms carry, no other account, and no
 * tenant identifier but this session's own. Everything here is either something the reader
 * supplied or something about their own authority.
 *
 * ROLE MEMBERSHIPS ARE PRESENTATION. They are shown because "why can I not see battlecards?" is
 * the commonest beta question and the answer is usually right there — but the page says plainly
 * that the server re-decides on every request, because a reader who takes this list for the
 * boundary will eventually test the boundary.
 *
 * @var array<string, mixed> $account
 * @var list<array<string, mixed>> $memberships
 * @var array<string, mixed>|null $session
 * @var ?string $requestId
 */
use Allfather\Loopia\Http\View;
$tag = static fn (string $term, string $kind = 'state'): string =>
    (new View(__DIR__))->partial('_tag', ['term' => $term, 'kind' => $kind]);
?>
<header class="page__head">
  <h1 class="page__title">Account</h1>
  <p class="page__lede">
    The login you are signed in with. It is a credential holder bound to one governed operator —
    every governed action records the operator, never the login.
  </p>
</header>

<section class="section">
  <h2 class="section__title">Identity</h2>
  <div class="table-wrap">
    <table>
      <tbody>
        <tr><th>Name</th><td><?= View::e($account['display_name']) ?></td></tr>
        <tr><th>Email</th><td class="mono"><?= View::e($account['email']) ?></td></tr>
        <tr><th>Account state</th><td><?= $tag((string) $account['status']) ?></td></tr>
        <tr>
          <th>Governed operator</th>
          <td>
            <?= View::e($account['operator_display_name']) ?>
            <?= $tag((string) $account['operator_status'], 'authority') ?>
          </td>
        </tr>
        <tr><th>Tenant</th><td class="mono"><?= View::e($account['tenant_key']) ?></td></tr>
        <tr><th>Password last changed</th><td class="mono"><?= View::e($account['password_changed_at']) ?></td></tr>
        <tr>
          <th>Last sign-in</th>
          <td class="mono"><?= View::e($account['last_login_at'] ?? 'never') ?></td>
        </tr>
        <tr><th>Live sessions</th><td class="mono"><?= View::e($account['live_sessions']) ?></td></tr>
      </tbody>
    </table>
  </div>
</section>

<section class="section">
  <h2 class="section__title">Workspace roles</h2>
  <?php if ($memberships === []): ?>
    <div class="notice notice--empty">
      You hold no workspace membership. A membership is granted by an operator with authority over
      the workspace; it is not something this surface can request.
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Workspace</th><th>Key</th><th>Role</th><th>Membership</th></tr></thead>
        <tbody>
          <?php foreach ($memberships as $membership): ?>
            <tr>
              <td><?= View::e($membership['workspace_display_name']) ?></td>
              <td class="mono"><?= View::e($membership['workspace_key']) ?></td>
              <td><?= $tag((string) $membership['role_key'], 'authority') ?></td>
              <td><?= $tag((string) $membership['status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <p class="page__meta">
    This list is a view of your authority, not the authority itself. Every surface re-decides on
    the server for each request, so a role shown here is still refused if it has been revoked since
    this page was rendered.
  </p>
</section>

<section class="section">
  <h2 class="section__title">Password</h2>
  <p class="page__lede">
    Changing your password signs out every session you hold, including this one.
  </p>
  <p><a class="rail__link" href="/account/password">Change password</a></p>
</section>

<?php if (($requestId ?? null) !== null): ?>
  <p class="page__meta">Support reference <code><?= View::e($requestId) ?></code>.</p>
<?php endif; ?>
