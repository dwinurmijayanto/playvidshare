<?php
define('STREAM_SECRET',   'xK9#mP2$qL7@nR4!');
define('INTERNAL_SECRET', 'INTERNAL_xK9#mP2$qL7@nR4!');

// ── Layer 1: Validasi internal secret ─────────────────────────
$receivedSecret = $_SERVER['HTTP_X_INTERNAL_SECRET'] ?? '';

if ($receivedSecret !== INTERNAL_SECRET) {
    error_log('[video.php] BLOCKED secret invalid | REMOTE_ADDR=' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('Access denied');
}

// ── Layer 2: Validasi hanya dari loopback ──────────────────────
$remoteIP = $_SERVER['REMOTE_ADDR'] ?? '';

if ($remoteIP !== '127.0.0.1' && $remoteIP !== '::1') {
    error_log('[video.php] BLOCKED IP=' . $remoteIP . ' not loopback');
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('Access denied');
}

// ── Validasi token ─────────────────────────────────────────────
$token = isset($_GET['t']) ? trim($_GET['t']) : '';

if (empty($token)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    exit('Invalid token');
}

// ── Decrypt URL asli ───────────────────────────────────────────
$payload = base64_decode(urldecode($token));

if (!$payload || strpos($payload, '||') === false) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('Invalid payload');
}

// FIX: IV disimpan dalam format base64, decode dulu sebelum dipakai
[$ivBase64, $encrypted] = explode('||', $payload, 2);
$iv = base64_decode($ivBase64);

if (!$iv || strlen($iv) !== 16) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('Invalid IV');
}

$url = openssl_decrypt($encrypted, 'AES-256-CBC', STREAM_SECRET, 0, $iv);

if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('Decrypt failed');
}

// ── Build headers ke sumber video ─────────────────────────────
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'];

$curlHeaders = [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    'Accept: video/mp4,video/*,*/*;q=0.8',
    'Accept-Language: en-US,en;q=0.9',
    'Referer: ' . $scheme . '://' . $host . '/',
    'Origin: '  . $scheme . '://' . $host,
];

if (isset($_SERVER['HTTP_RANGE'])) {
    $curlHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}

// ── Streaming dari sumber via cURL ─────────────────────────────
$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => $curlHeaders,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 0,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_BUFFERSIZE     => 1024 * 512, // 512KB buffer
    CURLOPT_HEADERFUNCTION => function ($ch, $line) {
        $line = trim($line);
        if (empty($line)) return strlen($line) + 2;

        if (preg_match('/^HTTP\/[\d.]+\s+(\d+)/i', $line, $m)) {
            http_response_code((int) $m[1]);
            return strlen($line) + 2;
        }

        $passthrough = ['Content-Type', 'Content-Length', 'Content-Range', 'Accept-Ranges'];
        foreach ($passthrough as $h) {
            if (stripos($line, $h . ':') === 0) {
                header($line, true);
                break;
            }
        }
        return strlen($line) + 2;
    },
    CURLOPT_WRITEFUNCTION  => function ($ch, $data) {
        echo $data;
        if (ob_get_level()) ob_flush();
        flush();
        return strlen($data);
    },
]);

header('Accept-Ranges: bytes');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (ob_get_level()) ob_end_clean();

$result = curl_exec($ch);
$errno  = curl_errno($ch);
$error  = curl_error($ch);
curl_close($ch);

if ($result === false && $errno !== 0) {
    error_log('[video.php] cURL error ' . $errno . ': ' . $error . ' | URL: ' . $url);
}