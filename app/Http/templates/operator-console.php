<?php
/** @var string $scriptUrl */
/** @var string $styleUrl */
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
<div id="root"></div>
<script type="module" src="<?= View::e($scriptUrl) ?>"></script>
</body>
</html>
