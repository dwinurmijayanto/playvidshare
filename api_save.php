<?php
/**
 * api_save.php — VidShare · Generate kode + simpan storage per server
 * Letakkan di: play.vidshare.my.id/api_save.php
 */

// ── 1. Tangkap semua error SEBELUM apapun tercetak ─────────────
ob_start(); // buffer semua output (termasuk PHP warning/notice)

set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline): bool {
    global $_PHP_ERRORS;
    $_PHP_ERRORS[] = "[E{$errno}] {$errstr} in {$errfile}:{$errline}";
    return true;
});

set_exception_handler(function(Throwable $e): void {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'    => false,
        'error' => 'Uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
    ]);
    exit;
});

register_shutdown_function(function(): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'    => false,
            'error' => 'Fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line'],
        ]);
    }
});

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

global $_PHP_ERRORS;
$_PHP_ERRORS = [];

// ── 2. CORS ─────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    ob_end_clean();
    exit;
}

// ── 3. Hanya POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// ── 4. Load includes ─────────────────────────────────────────────
$possibleBases = [
    __DIR__,
    dirname(__DIR__),
    '/var/www/html',
    '/home/vidshare/public_html',
    '/var/www/vidshare',
];

$storagePath = null;
$helpersPath = null;

foreach ($possibleBases as $base) {
    $s = $base . '/includes/storage.php';
    $h = $base . '/includes/helpers.php';
    if (file_exists($s) && file_exists($h)) {
        $storagePath = $s;
        $helpersPath = $h;
        break;
    }
}

if (!$storagePath || !file_exists($storagePath)) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'      => false,
        'error'   => 'storage.php tidak ditemukan. __DIR__=' . __DIR__,
        'checked' => array_map(fn($b) => $b . '/includes/storage.php', $possibleBases),
    ]);
    exit;
}

if (!$helpersPath || !file_exists($helpersPath)) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'helpers.php tidak ditemukan']);
    exit;
}

require_once $storagePath;
require_once $helpersPath;

// ── 5. Validasi input ─────────────────────────────────────────────
$server = trim($_POST['server']  ?? '');
$cdnUrl = trim($_POST['cdn_url'] ?? '');
$title  = mb_substr(trim($_POST['title'] ?? ''), 0, 120);
if ($title === '') $title = 'Video';

$serverMap = [
    's1'=>'s1','s2'=>'s2','s3'=>'s3',
    'server1'=>'s1','server2'=>'s2','server3'=>'s3',
    '1'=>'s1','2'=>'s2','3'=>'s3',
];
$serverNorm = $serverMap[strtolower($server)] ?? null;

if (!$serverNorm) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => "Server tidak valid: '{$server}'. Gunakan: s1, s2, s3"]);
    exit;
}

if ($cdnUrl === '') {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'cdn_url tidak boleh kosong']);
    exit;
}

if (!filter_var($cdnUrl, FILTER_VALIDATE_URL)) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'cdn_url bukan URL valid: ' . $cdnUrl]);
    exit;
}

// ── 6. Cek fungsi yang dibutuhkan tersedia ────────────────────────
$missing = [];
foreach (['saveVideoForServer', 'generateUniqueCode', 'saveVideo', 'normalizeServerPrefix'] as $fn) {
    if (!function_exists($fn)) $missing[] = $fn;
}

if (!empty($missing)) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'         => false,
        'error'      => 'Fungsi tidak ditemukan: ' . implode(', ', $missing),
        'storage'    => $storagePath,
        'helpers'    => $helpersPath,
        'php_errors' => $_PHP_ERRORS,
    ]);
    exit;
}

// ── 7. Proses simpan ──────────────────────────────────────────────
try {
    $buffered = ob_get_clean();
    ob_start();

    $result = saveVideoForServer($cdnUrl, $title, $serverNorm);

    ob_end_clean();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'         => true,
        'code'       => $result['code'],
        'play_url'   => $result['player_url'],
        'server'     => $serverNorm,
        'duplicate'  => $result['duplicate'],
        'title'      => $title,
        'php_errors' => $_PHP_ERRORS,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'ok'         => false,
        'error'      => $e->getMessage(),
        'file'       => $e->getFile(),
        'line'       => $e->getLine(),
        'php_errors' => $_PHP_ERRORS,
        'buffered'   => isset($buffered) ? substr($buffered, 0, 500) : '',
    ]);
}