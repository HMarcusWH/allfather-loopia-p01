<?php
/** @var int $status @var string $message @var string|null $requestId */
use Allfather\Loopia\Http\View;
?>
<div class="login">
  <div class="login__card">
    <header class="page__head">
      <h1 class="page__title"><?= View::e($status) ?></h1>
      <p class="page__lede"><?= View::e($message) ?></p>
    </header>
    <?php if (($requestId ?? null) !== null): ?>
    <p class="page__meta">
      Support reference <code><?= View::e($requestId) ?></code>.
      Quote it when reporting this — it identifies the request and nothing about you or your tenant.
    </p>
    <?php endif; ?>
    <p><a href="/console">Return to the console</a></p>
  </div>
</div>
