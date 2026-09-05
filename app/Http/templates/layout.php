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
      <a class="rail__link" href="/console"<?= ($nav['current'] ?? '') === 'console' ? ' aria-current="page"' : '' ?>>Workspace</a>
      <a class="rail__link" href="/console/decisions"<?= ($nav['current'] ?? '') === 'decisions' ? ' aria-current="page"' : '' ?>>Decision ledger</a>
    </div>

    <div class="rail__group">
      <p class="rail__label">Released output</p>
      <a class="rail__link" href="/console/briefings"<?= ($nav['current'] ?? '') === 'briefings' ? ' aria-current="page"' : '' ?>>Competition briefings</a>
      <a class="rail__link" href="/console/distributions"<?= ($nav['current'] ?? '') === 'distributions' ? ' aria-current="page"' : '' ?>>Role distributions</a>
      <a class="rail__link" href="/console/marketing"<?= ($nav['current'] ?? '') === 'marketing' ? ' aria-current="page"' : '' ?>>Marketing drafts</a>
    </div>

    <form method="post" action="/logout" class="rail__group">
      <input type="hidden" name="csrf_token" value="<?= View::e($session['csrf_token'] ?? '') ?>">
      <button class="rail__link" type="submit" style="background:none;border:0;padding:5px 0;cursor:pointer;font:inherit;color:inherit">Sign out</button>
    </form>

    <p class="rail__note">
      Navigation is a convenience. Hiding an item is not a permission and showing one is not
      either — every surface re-checks authority on the server.
    </p>
  </nav>

  <main class="main">
    <?= $content ?>
    <p class="ceiling">
      <strong>Claim ceiling.</strong> This console shows internally released intelligence only.
      Nothing here is customer-visible output, and no surface in this projection sends, publishes
      or performs an external action.
    </p>
  </main>
</div>
<?php endif; ?>
</body>
</html>
