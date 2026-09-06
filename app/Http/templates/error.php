<?php
/** @var int $status @var string $message */
use Allfather\Loopia\Http\View;
?>
<div class="login">
  <div class="login__card">
    <header class="page__head">
      <h1 class="page__title"><?= View::e($status) ?></h1>
      <p class="page__lede"><?= View::e($message) ?></p>
    </header>
    <p><a href="/console">Return to the console</a></p>
  </div>
</div>
