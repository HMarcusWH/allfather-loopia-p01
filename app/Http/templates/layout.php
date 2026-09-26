<?php
/** @var string $content */
/** @var string $title */
/** @var array<string, mixed>|null $session */
/** @var array<string, mixed> $nav */
use Allfather\Loopia\Http\View;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e($title) ?> — Allfather control room</title>
<link rel="stylesheet" href="/assets/console.css">
</head>
<body>
<?php if (($session ?? null) === null): ?>
<?= $content ?>
<?php else: ?>
<div class="shell">
  <nav class="rail" aria-label="Console sections">
    <div class="rail__brand">
      <p class="rail__title">Allfather</p>
      <p class="rail__sub"><?= View::e($session['display_name']) ?></p>
    </div>

    <div class="rail__group">
      <p class="rail__label">Governance</p>
      <a class="rail__link" href="/console/app">Operator Console</a>
      <a class="rail__link" href="/console"<?= ($nav['current'] ?? '') === 'console' ? ' aria-current="page"' : '' ?>>Workspace</a>
      <a class="rail__link" href="/console/workspaces"<?= ($nav['current'] ?? '') === 'workspaces' ? ' aria-current="page"' : '' ?>>Choose workspace</a>
      <a class="rail__link" href="/console/monitor"<?= ($nav['current'] ?? '') === 'monitor' ? ' aria-current="page"' : '' ?>>Monitor workbench</a>
      <a class="rail__link" href="/console/decisions"<?= ($nav['current'] ?? '') === 'decisions' ? ' aria-current="page"' : '' ?>>Decision ledger</a>
    </div>

    <div class="rail__group">
      <p class="rail__label">Released output</p>
      <a class="rail__link" href="/console/briefings"<?= ($nav['current'] ?? '') === 'briefings' ? ' aria-current="page"' : '' ?>>Competition briefings</a>
      <a class="rail__link" href="/console/distributions"<?= ($nav['current'] ?? '') === 'distributions' ? ' aria-current="page"' : '' ?>>Role distributions</a>
      <a class="rail__link" href="/console/marketing"<?= ($nav['current'] ?? '') === 'marketing' ? ' aria-current="page"' : '' ?>>Marketing drafts</a>
    </div>

    <div class="rail__group">
      <p class="rail__label">Your account</p>
      <a class="rail__link" href="/account"<?= ($nav['current'] ?? '') === 'account' ? ' aria-current="page"' : '' ?>>Account</a>
      <a class="rail__link" href="/support"<?= ($nav['current'] ?? '') === 'support' ? ' aria-current="page"' : '' ?>>Help &amp; support</a>
    </div>

    <!--
      Sign-out is a POST carrying the session's own CSRF token, and stays one. A GET link would be
      a link any page on the internet could make somebody follow — and "it is only a logout" is how
      that argument starts every time.
    -->
    <form method="post" action="/logout" class="rail__group">
      <input type="hidden" name="csrf_token" value="<?= View::e($session['csrf_token'] ?? '') ?>">
      <button class="rail__link rail__button" type="submit">Sign out</button>
    </form>

    <p class="rail__note">
      Navigation is a convenience. Hiding an item is not a permission and showing one is not
      either — every surface re-checks authority on the server.
    </p>
  </nav>

  <main class="main">
    <?= $content ?>
    <p class="ceiling">
      <strong>Claim ceiling.</strong>
      <?php if (($nav['current'] ?? '') === 'monitor'): ?>
      This workbench includes unreleased requests, drafts and review records. Those records are not
      released intelligence or authority to execute. Nothing here is customer-visible output.
      <?php else: ?>
      This console shows internally released intelligence only. Nothing here is customer-visible
      output, and no surface in this projection sends, publishes or performs an external action.
      <?php endif; ?>
    </p>
  </main>
</div>
<?php endif; ?>
</body>
</html>
