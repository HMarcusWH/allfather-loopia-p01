<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

http_response_code(503);
echo json_encode([
    'service' => 'allfather-loopia-p01',
    'projection' => 'LOOPIA_P01',
    'status' => 'FOUNDATION_ONLY',
    'customer_visible_output' => false,
], JSON_THROW_ON_ERROR);
