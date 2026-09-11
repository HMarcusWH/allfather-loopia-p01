<?php
/**
 * The authenticated React shell document.
 *
 * ONE VALUE CROSSES FROM THE SERVER INTO THE SHELL, and it is the session's own CSRF token, carried
 * on the mount element so the shell's sign-out control can be a real POST form. It is the same
 * value the server-rendered console already writes into every hidden input in a document like this
 * one, so nothing is newly exposed — and `script-src 'self'` means no third-party script can read
 * it in either case. It is escaped like everything else: `View::e` is the only route to the page.
 *
 * WHAT IS STILL ABSENT: no inline script, no inline style, no remote origin, and no governed data.
 * Everything the console shows it fetches through an owner-gated read over the session cookie.
 *
 * @var string $scriptUrl
 * @var string $styleUrl
 * @var string $csrfToken
 */
use Allfather\Loopia\Http\View;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Allfather Operator Console</title>
<link rel="stylesheet" href="<?= View::e($styleUrl) ?>">
</head>
<body>
<div id="root" data-allfather-console data-csrf-token="<?= View::e($csrfToken) ?>"></div>
<script type="module" src="<?= View::e($scriptUrl) ?>"></script>
</body>
</html>
