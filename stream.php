<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/storage.php';

define('STREAM_SECRET',   'xK9#mP2$qL7@nR4!');
define('INTERNAL_SECRET', 'INTERNAL_xK9#mP2$qL7@nR4!');

// ── Validasi kode ─────────────────────────────────────────────
$code = trim($_GET['c'] ?? '');
if ($code === '' || !preg_match('/^[a-zA-Z0-9]{8}$|^[a-zA-Z0-9]{13}$|^[a-zA-Z0-9]{15}$/', $code)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    exit('Invalid code');
}

// ── Ambil data video ──────────────────────────────────────────
$video = getVideoByCode($code);
if (!$video) {
    http_response_code(404);
    header('Content-Type: text/plain');
    exit('Video not found');
}

$url = $video['url'] ?? null;
if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(500);
    header('Content-Type: text/plain');
    exit('Invalid video URL');
}

// ── Deteksi HLS ───────────────────────────────────────────────
$urlPath = parse_url($url, PHP_URL_PATH) ?? '';
$isHls   = (bool) preg_match('/\.(m3u8|m3u)(\?.*)?$/i', $urlPath);

if ($isHls) {
    header('Location: ' . $url, true, 302);
    exit;
}

// ── Auto-generate thumbnail di background (non-blocking) ─────
// Cek file .url (cache URL external) — lebih cepat dari cek .jpg
// Hanya spawn proses jika belum ada cache sama sekali
$thumbDir     = __DIR__ . '/thumbs';
$urlCacheFile = $thumbDir . '/' . $code . '.url';
$jpgCacheFile = $thumbDir . '/' . $code . '.jpg';

$hasUrlCache = file_exists($urlCacheFile) && filesize($urlCacheFile) > 0;
$hasJpgCache = file_exists($jpgCacheFile) && filesize($jpgCacheFile) > 512;

if (!$hasUrlCache && !$hasJpgCache) {
    $phpBin      = PHP_BINARY;
    $thumbScript = __DIR__ . '/generate_thumb.php';

    if (file_exists($thumbScript)) {
        $cmd  = sprintf(
            '%s %s %s > /dev/null 2>&1 &',
            escapeshellarg($phpBin),
            escapeshellarg($thumbScript),
            escapeshellarg($code)
        );
        $desc = [['pipe','r'],['pipe','w'],['pipe','w']];
        $proc = @proc_open($cmd, $desc, $pipes);
        if (is_resource($proc)) {
            foreach ($pipes as $p) { @fclose($p); }
            // Tidak proc_close — biarkan jalan di background
        }
    }
}

// ── MP4: enkripsi URL → token → proxy via video.php ──────────
$iv        = random_bytes(16);
$encrypted = openssl_encrypt($url, 'AES-256-CBC', STREAM_SECRET, 0, $iv);
$token     = urlencode(base64_encode(base64_encode($iv) . '||' . $encrypted));

$host        = $_SERVER['HTTP_HOST'];
$internalUrl = 'http://127.0.0.1/video.php?t=' . $token;

$curlHeaders = [
    'Host: '              . $host,
    'X-Internal-Secret: ' . INTERNAL_SECRET,
    'User-Agent: '        . ($_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0'),
    'Accept: video/mp4,video/*,*/*;q=0.8',
];

if (isset($_SERVER['HTTP_RANGE'])) {
    $curlHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}

$ch = curl_init($internalUrl);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => $curlHeaders,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT        => 0,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_BUFFERSIZE     => 1024 * 512,
    CURLOPT_HEADERFUNCTION => function ($ch, $line) {
        $line = trim($line);
        if (empty($line)) return strlen($line) + 2;
        if (preg_match('/^HTTP\/[\d.]+\s+(\d+)/i', $line, $m)) {
            http_response_code((int) $m[1]);
            return strlen($line) + 2;
        }
        foreach (['Content-Type','Content-Length','Content-Range','Accept-Ranges'] as $h) {
            if (stripos($line, $h . ':') === 0) { header($line, true); break; }
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
    error_log('[stream.php] cURL error ' . $errno . ': ' . $error);
}