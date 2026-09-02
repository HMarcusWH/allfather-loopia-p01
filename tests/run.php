<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$lock = json_decode(
    file_get_contents($root . '/upstream.lock.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);

$failures = [];

if (($lock['projection_id'] ?? null) !== 'LOOPIA_P01') {
    $failures[] = 'projection_id must be LOOPIA_P01';
}

if (($lock['canonical_repository'] ?? null) !== 'HMarcusWH/Allfatherbusinessintelligence') {
    $failures[] = 'canonical repository identity changed';
}

if (($lock['action_classes'] ?? null) !== ['A', 'B', 'C']) {
    $failures[] = 'action ceiling must remain exactly A/B/C';
}

if (($lock['claim_boundary']['external_action_authorized'] ?? true) !== false) {
    $failures[] = 'external action must remain unauthorized';
}

if (($lock['claim_boundary']['customer_visible_output_authorized'] ?? true) !== false) {
    $failures[] = 'customer-visible output must remain unauthorized at foundation';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Foundation invariants: PASS" . PHP_EOL);
