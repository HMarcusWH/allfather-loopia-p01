<?php
/**
 * Choosing this account's password.
 *
 * ONE FORM, NO SCRIPT. The console runs none, and a password field is the last place to introduce
 * one: every client-side strength meter is a value that has been somewhere other than this form.
 *
 * THE CURRENT PASSWORD IS ASKED FOR EVEN THOUGH THE CALLER IS SIGNED IN. A session proves somebody
 * signed in; it does not prove the person here now is that somebody. An unattended browser is the
 * ordinary case, not the exotic one.
 *
 * @var bool $mustChangePassword
 * @var int $minimumLength
 * @var ?string $error
 * @var string $csrf_token
 * @var ?string $requestId
 */
use Allfather\Loopia\Http\View;
$tag = static fn (string $term, string $kind = 'state'): string =>
    (new View(__DIR__))->partial('_tag', ['term' => $term, 'kind' => $kind]);
?>
<div class="login">
  <div class="login__card">
    <header class="page__head">
      <h1 class="page__title"><?= $mustChangePassword ? 'Choose your password' : 'Change password' ?></h1>
      <?php if ($mustChangePassword): ?>
        <p class="page__lede">
          This account was opened with a password an operator issued, so two people know it. Choose
          your own before continuing — nothing else in the console is available until you do.
        </p>
      <?php else: ?>
        <p class="page__lede">
          Replacing your password signs out every session, including this one. You will sign in
          again with the new password.
        </p>
      <?php endif; ?>
    </header>

    <?php if (($error ?? null) !== null): ?>
      <div class="notice notice--denied" role="alert">
        <strong>That password was not accepted.</strong>
        <p class="notice__codes"><?= $tag($error) ?></p>
      </div>
    <?php endif; ?>

    <form class="form" method="post" action="/account/password">
      <input type="hidden" name="csrf_token" value="<?= View::e($csrf_token ?? '') ?>">
      <div class="field">
        <label for="current_password">Current password</label>
        <input id="current_password" name="current_password" type="password"
               autocomplete="current-password" required>
      </div>
      <div class="field">
        <label for="new_password">New password</label>
        <input id="new_password" name="new_password" type="password" autocomplete="new-password"
               minlength="<?= View::e($minimumLength) ?>" required>
      </div>
      <div class="field">
        <label for="confirm_password">New password again</label>
        <input id="confirm_password" name="confirm_password" type="password"
               autocomplete="new-password" minlength="<?= View::e($minimumLength) ?>" required>
      </div>
      <button class="button" type="submit">Set password</button>
    </form>

    <p class="page__meta">
      At least <?= View::e($minimumLength) ?> characters. Length is the only rule: composition rules
      push people toward predictable substitutions, so a long passphrase is the better answer.
    </p>

    <?php if ($mustChangePassword): ?>
      <form class="form" method="post" action="/logout">
        <input type="hidden" name="csrf_token" value="<?= View::e($csrf_token ?? '') ?>">
        <button class="button button--quiet" type="submit">Sign out instead</button>
      </form>
    <?php endif; ?>

    <?php if (($requestId ?? null) !== null): ?>
      <p class="page__meta">Support reference <code><?= View::e($requestId) ?></code>.</p>
    <?php endif; ?>
  </div>
</div>
