<?php
/**
 * generate_thumb.php — CLI helper untuk auto-generate + upload thumbnail.
 *
 * Dipanggil dari stream.php di background:
 *   php generate_thumb.php <code>
 *
 * Alur:
 *   1. Generate thumbnail JPEG lokal via FFmpeg + watermark
 *   2. Upload ke external storage (img.vidshare.my.id/upload_proxy.php)
 *   3. Simpan URL external di /thumbs/{code}.url
 *   4. Hapus file JPEG lokal
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$code = trim($argv[1] ?? '');

if (
    empty($code) ||
    !preg_match('/^[a-zA-Z0-9]{8}$|^[a-zA-Z0-9]{13}$|^[a-zA-Z0-9]{15}$/', $code)
) {
    fwrite(STDERR, "[generate_thumb] Kode tidak valid: '{$code}'\n");
    exit(1);
}

// ── Load dependensi ───────────────────────────────────────────
$root = __DIR__;
if (file_exists($root . '/config.php'))           require_once $root . '/config.php';
require_once $root . '/includes/storage.php';
require_once $root . '/includes/helpers.php';

// ── Konstanta ─────────────────────────────────────────────────
if (!defined('THUMB_DIR'))          define('THUMB_DIR',          $root . '/thumbs');
if (!defined('THUMB_FFMPEG'))       define('THUMB_FFMPEG',       '/usr/bin/ffmpeg');
if (!defined('THUMB_DEFAULT_W'))    define('THUMB_DEFAULT_W',    640);
if (!defined('THUMB_DEFAULT_H'))    define('THUMB_DEFAULT_H',    360);
if (!defined('THUMB_QUALITY'))      define('THUMB_QUALITY',      3);
if (!defined('THUMB_DEFAULT_SEEK')) define('THUMB_DEFAULT_SEEK', 2);
if (!defined('THUMB_TIMEOUT'))      define('THUMB_TIMEOUT',      25);
if (!defined('THUMB_WM_OPACITY'))   define('THUMB_WM_OPACITY',   0.60);
if (!defined('THUMB_UPLOAD_API'))   define('THUMB_UPLOAD_API',   'https://img.vidshare.my.id/upload_proxy.php');

// ── Siapkan direktori ─────────────────────────────────────────
if (!is_dir(THUMB_DIR)) mkdir(THUMB_DIR, 0755, true);

$urlCacheFile = THUMB_DIR . '/' . $code . '.url';
$jpgCacheFile = THUMB_DIR . '/' . $code . '.jpg';

// ── Cek apakah URL external sudah ada ────────────────────────
if (file_exists($urlCacheFile)) {
    $existing = trim(file_get_contents($urlCacheFile));
    if (!empty($existing) && filter_var($existing, FILTER_VALIDATE_URL)) {
        echo "[generate_thumb] Sudah ada cache URL: {$code}\n";
        exit(0);
    }
    @unlink($urlCacheFile);
}

// ── Lookup video ──────────────────────────────────────────────
$video = getVideoByCode($code);
if (!$video || empty($video['url'])) {
    fwrite(STDERR, "[generate_thumb] Video tidak ditemukan: {$code}\n");
    exit(1);
}

$videoUrl = $video['url'];
$wmText   = 'VidShare · ' . $code;

// ── Generate JPEG lokal ───────────────────────────────────────
$ok = generateThumbnailCli(
    $videoUrl, $jpgCacheFile,
    THUMB_DEFAULT_SEEK, THUMB_DEFAULT_W, THUMB_DEFAULT_H,
    $wmText
);

if (!$ok || !file_exists($jpgCacheFile) || filesize($jpgCacheFile) <= 512) {
    @unlink($jpgCacheFile);
    fwrite(STDERR, "[generate_thumb] FFmpeg gagal: {$code}\n");
    exit(1);
}

echo "[generate_thumb] FFmpeg OK: {$code} (" . filesize($jpgCacheFile) . " bytes)\n";

// ── Upload ke external ────────────────────────────────────────
$externalUrl = uploadToExternal($jpgCacheFile, $code);

if ($externalUrl) {
    file_put_contents($urlCacheFile, $externalUrl);
    @unlink($jpgCacheFile);
    echo "[generate_thumb] Upload OK: {$externalUrl}\n";
    exit(0);
} else {
    // Upload gagal: biarkan JPG lokal ada sebagai fallback untuk thumb.php
    fwrite(STDERR, "[generate_thumb] Upload external gagal, JPG lokal disimpan sebagai fallback: {$code}\n");
    exit(1);
}


// ════════════════════════════════════════════════════════════
// FUNGSI
// ════════════════════════════════════════════════════════════

function uploadToExternal(string $filePath, string $code): ?string
{
    $curlFile = new CURLFile($filePath, 'image/jpeg', $code . '.jpg');

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => THUMB_UPLOAD_API,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['images[]' => $curlFile],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'VidShare-Thumb/1.0',
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'X-Requested-With: XMLHttpRequest',
        ],
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || !$response) {
        fwrite(STDERR, "[generate_thumb] cURL upload error: {$curlError}\n");
        return null;
    }

    $json = json_decode($response, true);

    if (!$json || empty($json['success']) || empty($json['direct_link'])) {
        fwrite(STDERR, "[generate_thumb] Upload response error: " . substr($response, 0, 200) . "\n");
        return null;
    }

    return $json['direct_link'];
}

function generateThumbnailCli(
    string $videoUrl,
    string $outFile,
    int    $seek,
    int    $w,
    int    $h,
    string $wmText = ''
): bool {
    $vf = sprintf(
        'scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2:black',
        $w, $h, $w, $h
    );

    if ($wmText !== '') {
        $escapedText = str_replace(
            ["'", ':', '\\'],
            ["\u{2019}", '\\:', '\\\\'],
            $wmText
        );
        $vf .= sprintf(
            ",drawtext=text='%s'"
            . ":fontcolor=white@%.2f"
            . ":fontsize=w*0.022"
            . ":x=(w-text_w)/2"
            . ":y=(h-text_h)/2"
            . ":shadowcolor=black@0.70"
            . ":shadowx=1"
            . ":shadowy=1",
            $escapedText,
            THUMB_WM_OPACITY
        );
    }

    $args = implode(' ', [
        '-hide_banner',
        '-loglevel', 'error',
        '-ss',       escapeshellarg((string)$seek),
        '-i',        escapeshellarg($videoUrl),
        '-vframes',  '1',
        '-q:v',      escapeshellarg((string)THUMB_QUALITY),
        '-vf',       escapeshellarg($vf),
        '-f',        'image2',
        '-y',
        escapeshellarg($outFile),
    ]);

    $cmd  = THUMB_FFMPEG . ' ' . $args . ' 2>/dev/null';
    $desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];

    $proc = @proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, "[generate_thumb] proc_open gagal\n");
        return false;
    }

    fclose($pipes[0]);
    $start    = time();
    $exitCode = -1;

    while (true) {
        $status = proc_get_status($proc);
        if (!$status['running']) { $exitCode = $status['exitcode']; break; }
        if ((time() - $start) >= THUMB_TIMEOUT) {
            $pid = (int)($status['pid'] ?? 0);
            if ($pid > 0) @posix_kill($pid, 9);
            fwrite(STDERR, "[generate_thumb] FFmpeg timeout\n");
            break;
        }
        usleep(80000);
    }

    $stderr = stream_get_contents($pipes[2], 512);
    if ($stderr) fwrite(STDERR, "[generate_thumb] FFmpeg: " . trim($stderr) . "\n");

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    return $exitCode === 0;
}