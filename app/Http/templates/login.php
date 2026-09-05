<?php
/** @var ?string $error */
/** @var string $csrf_token */
use Allfather\Loopia\Http\View;
?>
<div class="login">
  <div class="login__card">
    <header class="page__head">
      <h1 class="page__title">Allfather control room</h1>
      <p class="page__lede">Governed intelligence for your workspace. Internal output only.</p>
    </header>

    <?php if (($error ?? null) !== null): ?>
      <div class="notice notice--denied" role="alert">
        <?= View::e($error) ?>
        <p class="notice__codes">
          The same message is shown whether the address is unknown or the password is wrong —
          telling them apart would confirm which addresses exist.
        </p>
      </div>
    <?php endif; ?>

    <form class="form" method="post" action="/login">
      <!--
        The sign-in POST is CSRF-checked like every other state-changing request. There is no
        session to bind the token to yet, so the check is against the matching __Host- cookie this
        page set — and the token is replaced by the session's own the moment sign-in succeeds.
      -->
      <input type="hidden" name="csrf_token" value="<?= View::e($csrf_token ?? '') ?>">
      <div class="field">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" autocomplete="username" required>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
      </div>
      <button class="button" type="submit">Sign in</button>
    </form>
  </div>
</div>
