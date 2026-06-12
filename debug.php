<?php
// HAPUS FILE INI SETELAH SELESAI DEBUG

header('Content-Type: text/plain');

$keys = [
    'HTTP_HOST',
    'REDIRECT_REAL_HOST',
    'HTTP_X_FORWARDED_HOST',
    'HTTP_X_FORWARDED_PROTO',
    'HTTP_X_FORWARDED_SSL',
    'HTTPS',
    'SERVER_PORT',
    'SERVER_NAME',
];

foreach ($keys as $key) {
    echo str_pad($key, 30) . ' = ' . ($_SERVER[$key] ?? '(tidak ada)') . "\n";
}

echo "\n--- getBaseUrl() result ---\n";
require_once __DIR__ . '/includes/helpers.php';
echo getBaseUrl() . "\n";