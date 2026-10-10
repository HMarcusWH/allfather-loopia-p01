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
  <aside class="rail">
    <div class="rail__brand">
      <a href="/console/app" aria-label="Allfather BI Operator Console">
        <img class="rail__logo" src="/assets/allfather-logo.svg" width="168" height="41" alt="Allfather BI — Governed Intelligence Console">
      </a>
      <p class="rail__sub"><?= View::e($session['display_name']) ?></p>
    </div>

    <!-- Scriptless navigation. Permissions are still decided on every destination by its owner,
         never by whether a link appears in this presentation. The protected React shell uses
         context-derived navigation; these are safe anchors, not authority grants. -->
    <nav class="rail__primary" aria-label="Console sections">
      <div class="rail__group">
        <p class="rail__label">Intelligence</p>
        <a class="rail__link" href="/console/app?view=p01">Competition Monitor</a>
        <a class="rail__link" href="/console/app?view=monitor-runs">Monitor runs</a>
        <a class="rail__link" href="/console/app?view=p02">Entity Context</a>
        <a class="rail__link" href="/console/app?view=p03">Lead Intelligence</a>
        <a class="rail__link" href="/console/app?view=p04">Distribution</a>
        <a class="rail__link" href="/console/app?view=p05">Marketing Drafts</a>
      </div>

      <div class="rail__group">
        <p class="rail__label">Governance</p>
        <a class="rail__link" href="/console"<?= ($nav['current'] ?? '') === 'console' ? ' aria-current="page"' : '' ?>>Workspace summary</a>
        <a class="rail__link" href="/console/decisions"<?= ($nav['current'] ?? '') === 'decisions' ? ' aria-current="page"' : '' ?>>Decision ledger</a>
        <a class="rail__link" href="/console/monitor"<?= ($nav['current'] ?? '') === 'monitor' ? ' aria-current="page"' : '' ?>>Monitor workbench</a>
        <a class="rail__link" href="/console/monitor/setup"<?= ($nav['current'] ?? '') === 'monitor-setup' ? ' aria-current="page"' : '' ?>>Monitor setup</a>
        <a class="rail__link" href="/console/monitor/schedule"<?= ($nav['current'] ?? '') === 'monitor-schedule' ? ' aria-current="page"' : '' ?>>Monitor schedules</a>
      </div>

      <div class="rail__group">
        <p class="rail__label">Released output</p>
        <a class="rail__link" href="/console/briefings"<?= ($nav['current'] ?? '') === 'briefings' ? ' aria-current="page"' : '' ?>>Competition briefings</a>
        <a class="rail__link" href="/console/distributions"<?= ($nav['current'] ?? '') === 'distributions' ? ' aria-current="page"' : '' ?>>Role distributions</a>
        <a class="rail__link" href="/console/marketing"<?= ($nav['current'] ?? '') === 'marketing' ? ' aria-current="page"' : '' ?>>Marketing drafts</a>
      </div>
    </nav>

    <nav class="rail__utilities" aria-label="Session">
      <p class="rail__label">Session</p>
      <a class="rail__link" href="/console/workspaces"<?= ($nav['current'] ?? '') === 'workspaces' ? ' aria-current="page"' : '' ?>>Choose workspace</a>
      <a class="rail__link" href="/account"<?= ($nav['current'] ?? '') === 'account' ? ' aria-current="page"' : '' ?>>Account</a>
      <a class="rail__link" href="/support"<?= ($nav['current'] ?? '') === 'support' ? ' aria-current="page"' : '' ?>>Help &amp; support</a>

      <!-- Sign out remains CSRF-checked POST, never a GET navigation link. -->
      <form method="post" action="/logout" class="rail__logout">
        <input type="hidden" name="csrf_token" value="<?= View::e($session['csrf_token'] ?? '') ?>">
        <button class="rail__link rail__button" type="submit">Sign out</button>
      </form>
    </nav>

    <p class="rail__note">
      <strong>Internal presentation only</strong>
      No control grants authority.
    </p>
  </aside>

  <div class="shell__main">
    <header class="shell__topbar">
      <span class="shell__topbar-title">Operator Console</span>
      <span class="shell__topbar-sub"><?= View::e($title) ?> · Protected account session</span>
    </header>
    <main class="main" id="main-content">
      <?= $content ?>
      <p class="ceiling">
        <strong>Claim ceiling.</strong>
        <?php if (in_array($nav['current'] ?? '', ['monitor', 'monitor-setup', 'monitor-schedule'], true)): ?>
        This workbench includes unreleased requests, drafts and review records. Those records are not
        released intelligence or authority to execute. Nothing here is customer-visible output.
        <?php else: ?>
        This console shows internally released intelligence only. Nothing here is customer-visible
        output, and no surface in this projection sends, publishes or performs an external action.
        <?php endif; ?>
      </p>
    </main>
  </div>
</div>
<?php endif; ?>
</body>
</html>
