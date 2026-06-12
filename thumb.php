<?php
/**
 * thumb.php — On-demand Video Thumbnail Generator
 * File: /thumb.php  (root, sejajar player.php)
 *
 * Dioptimalkan untuk: Ubuntu + FFmpeg 6.1.1 (/usr/bin/ffmpeg)
 *
 * ─── URL Parameter ───────────────────────────────────────────
 *   ?c=CODE              → thumbnail standar (frame detik ke-2, 640×360)
 *   ?c=CODE&t=5          → ambil frame di detik ke-5
 *   ?c=CODE&w=1280&h=720 → ukuran output custom
 *   ?c=CODE&refresh=1    → hapus cache dan generate ulang
 *                          (jika THUMB_REFRESH_KEY diisi, butuh &key=xxx)
 *
 * ─── Alur ────────────────────────────────────────────────────
 *   1. Validasi kode → lookup URL video dari storage
 *   2. Cek external URL cache → /thumbs/{code}.url
 *   3. Jika ada → 302 redirect ke URL external (img.vidshare.my.id)
 *   4. Jika tidak → generate dengan FFmpeg → upload ke external → simpan URL → redirect
 *   5. Fallback SVG placeholder jika semua gagal
 *
 * ─── Caching ─────────────────────────────────────────────────
 *   URL external disimpan di /thumbs/{code}.url (plaintext, 1 baris)
 *   File JPEG lokal /thumbs/{code}.jpg hanya sebagai temporary, dihapus setelah upload
 *   HTTP Cache-Control: 7 hari
 */

declare(strict_types=1);

// ── Load dependensi ──────────────────────────────────────────
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
require_once __DIR__ . '/includes/storage.php';
require_once __DIR__ . '/includes/helpers.php';

// ── Konstanta ─────────────────────────────────────────────────
const THUMB_DIR          = __DIR__ . '/thumbs';
const THUMB_FFMPEG       = '/usr/bin/ffmpeg';
const THUMB_DEFAULT_W    = 640;
const THUMB_DEFAULT_H    = 360;
const THUMB_QUALITY      = 3;
const THUMB_DEFAULT_SEEK = 2;
const THUMB_MAX_SEEK     = 120;
const THUMB_TIMEOUT      = 25;
const THUMB_CACHE_TTL    = 604800;
const THUMB_REFRESH_KEY  = '';

// ── Watermark config ──────────────────────────────────────────
const THUMB_WM_OPACITY = 0.60;
const THUMB_WM_POS     = 'center';

// ── External upload config ────────────────────────────────────
// URL upload8_api_lib.php yang ada di server img.vidshare.my.id
// Karena server sama bisa pakai path lokal, atau bisa pakai HTTP
const THUMB_UPLOAD_API = 'https://img.vidshare.my.id/upload8_api.php';

// ── Ambil & sanitasi parameter ───────────────────────────────
$code    = trim($_GET['c'] ?? '');
$seek    = max(0, min((int)($_GET['t'] ?? THUMB_DEFAULT_SEEK), THUMB_MAX_SEEK));
$width   = max(64,  min((int)($_GET['w'] ?? THUMB_DEFAULT_W),  1920));
$height  = max(36,  min((int)($_GET['h'] ?? THUMB_DEFAULT_H),  1080));
$refresh = isset($_GET['refresh']);

// ── Validasi kode ─────────────────────────────────────────────
if (
    empty($code) ||
    !preg_match('/^[a-zA-Z0-9]{8}$|^[a-zA-Z0-9]{13}$|^[a-zA-Z0-9]{15}$/', $code)
) {
    servePlaceholder($width, $height, 'Kode tidak valid', '');
    exit;
}

// ── Lookup video dari storage ─────────────────────────────────
$video = getVideoByCode($code);
if (!$video || empty($video['url'])) {
    servePlaceholder($width, $height, 'Video tidak ditemukan', '');
    exit;
}

$videoUrl   = $video['url'];
$videoTitle = $video['title'] ?? 'VidShare';
$wmText     = 'VidShare · ' . $code;

// ── Siapkan direktori cache ───────────────────────────────────
if (!is_dir(THUMB_DIR)) {
    mkdir(THUMB_DIR, 0755, true);
}

$urlCacheFile = THUMB_DIR . '/' . $code . '.url';   // menyimpan URL external
$jpgCacheFile = THUMB_DIR . '/' . $code . '.jpg';   // temp lokal, dihapus setelah upload

// ── Validasi izin refresh ─────────────────────────────────────
if ($refresh && THUMB_REFRESH_KEY !== '') {
    $refresh = (($_GET['key'] ?? '') === THUMB_REFRESH_KEY);
}

// ── Hapus cache jika refresh diminta ─────────────────────────
if ($refresh) {
    @unlink($urlCacheFile);
    @unlink($jpgCacheFile);
}

// ── Serve dari cache URL external jika tersedia ──────────────
if (file_exists($urlCacheFile)) {
    $externalUrl = trim(file_get_contents($urlCacheFile));
    if (!empty($externalUrl) && filter_var($externalUrl, FILTER_VALIDATE_URL)) {
        header('Location: ' . $externalUrl, true, 302);
        header('Cache-Control: public, max-age=' . THUMB_CACHE_TTL);
        exit;
    }
    // Cache URL rusak, hapus dan regenerate
    @unlink($urlCacheFile);
}

// ── Generate thumbnail dengan FFmpeg ke file lokal temp ───────
$ok = generateThumbnail($videoUrl, $jpgCacheFile, $seek, $width, $height, $wmText);

if ($ok && file_exists($jpgCacheFile) && filesize($jpgCacheFile) > 512) {

    // ── Upload ke external storage ────────────────────────────
    $externalUrl = uploadThumbnailToExternal($jpgCacheFile, $code);

    if ($externalUrl) {
        // Simpan URL external ke cache .url
        file_put_contents($urlCacheFile, $externalUrl);

        // Hapus file lokal — tidak perlu lagi, sudah di external
        @unlink($jpgCacheFile);

        // Redirect ke URL external
        header('Location: ' . $externalUrl, true, 302);
        header('Cache-Control: public, max-age=' . THUMB_CACHE_TTL);
        exit;
    }

    // Upload external gagal → serve file lokal sebagai fallback
    serveJpeg($jpgCacheFile);
    exit;
}

// ── Fallback: SVG placeholder ─────────────────────────────────
@unlink($jpgCacheFile);
servePlaceholder($width, $height, $videoTitle, $wmText);
exit;


// ════════════════════════════════════════════════════════════
// FUNGSI
// ════════════════════════════════════════════════════════════

/**
 * Upload file JPEG ke external storage via upload8_api.php
 * Menggunakan upload_proxy.php karena kita punya FILE bukan URL.
 *
 * @param  string $filePath  Path file .jpg lokal
 * @param  string $code      Kode video (untuk logging)
 * @return string|null       URL external jika berhasil, null jika gagal
 */
function uploadThumbnailToExternal(string $filePath, string $code): ?string
{
    // Pakai upload_proxy.php karena kita upload FILE (bukan URL)
    $uploadEndpoint = 'https://img.vidshare.my.id/upload_proxy.php';

    $curlFile = new CURLFile($filePath, 'image/jpeg', $code . '.jpg');

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $uploadEndpoint,
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
        error_log('[thumb.php] Upload external gagal (curl): ' . $curlError);
        return null;
    }

    $json = json_decode($response, true);

    if (!$json || empty($json['success']) || empty($json['direct_link'])) {
        error_log('[thumb.php] Upload external gagal (response): ' . substr($response, 0, 256));
        return null;
    }

    return $json['direct_link'];
}

/**
 * Generate thumbnail dari video URL menggunakan FFmpeg + watermark.
 */
function generateThumbnail(
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

    $cmd = THUMB_FFMPEG . ' ' . $args . ' 2>/dev/null';

    return runCommand($cmd, THUMB_TIMEOUT);
}

/**
 * Jalankan shell command dengan batas waktu menggunakan proc_open.
 */
function runCommand(string $cmd, int $timeout): bool
{
    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = @proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) {
        error_log('[thumb.php] proc_open gagal: ' . $cmd);
        return false;
    }

    fclose($pipes[0]);

    $start    = time();
    $exitCode = -1;

    while (true) {
        $status = proc_get_status($proc);

        if (!$status['running']) {
            $exitCode = $status['exitcode'];
            break;
        }

        if ((time() - $start) >= $timeout) {
            $pid = (int)($status['pid'] ?? 0);
            if ($pid > 0) @posix_kill($pid, 9);
            error_log(sprintf('[thumb.php] FFmpeg timeout setelah %ds', $timeout));
            break;
        }

        usleep(80000);
    }

    $stderr = stream_get_contents($pipes[2], 512);
    if ($stderr) {
        error_log('[thumb.php] FFmpeg stderr: ' . trim($stderr));
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    return $exitCode === 0;
}

/**
 * Serve file JPEG dari disk dengan HTTP caching.
 * Dipakai sebagai fallback jika upload external gagal.
 */
function serveJpeg(string $filePath): void
{
    $mtime = (int)filemtime($filePath);
    $size  = (int)filesize($filePath);
    $etag  = '"' . substr(md5($filePath . $mtime . $size), 0, 16) . '"';

    $ifNoneMatch = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    $ifModSince  = trim($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');

    if (
        ($ifNoneMatch && $ifNoneMatch === $etag) ||
        ($ifModSince && strtotime($ifModSince) >= $mtime)
    ) {
        http_response_code(304);
        exit;
    }

    header_remove();
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . $size);
    header('Cache-Control: public, max-age=' . THUMB_CACHE_TTL . ', immutable');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('ETag: ' . $etag);
    header('X-Thumb-Source: local-fallback');
    header('X-Content-Type-Options: nosniff');

    readfile($filePath);
}

/**
 * Serve SVG placeholder bergaya VidShare + watermark center.
 */
function servePlaceholder(int $w, int $h, string $title = 'VidShare', string $wmText = ''): void
{
    if (mb_strlen($title) > 40) {
        $title = mb_substr($title, 0, 37) . '…';
    }
    $safeTitle  = htmlspecialchars($title,  ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $safeWmText = htmlspecialchars($wmText, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    $cx = round($w / 2, 1);
    $cy = round($h / 2, 1);
    $r  = (int)(min($w, $h) / 7);

    $triX1 = round($cx - $r * 0.28, 1);
    $triY1 = round($cy - $r * 0.50, 1);
    $triX2 = round($cx + $r * 0.58, 1);
    $triY2 = $cy;
    $triX3 = $triX1;
    $triY3 = round($cy + $r * 0.50, 1);

    $glowR     = (int)($r * 1.8);
    $fontSize  = max(11, (int)($w * 0.024));
    $labelY    = (int)($cy + $r + $fontSize * 2.2);

    $wmFontSize   = max(9, (int)($w * 0.022));
    $wmOpacityStr = number_format(THUMB_WM_OPACITY, 2, '.', '');

    $logoBoxSz = max(16, (int)($w * 0.028));
    $logoX     = 14;
    $logoY     = $h - $logoBoxSz - 10;
    $logoTextX = $logoX + $logoBoxSz + 8;
    $logoTextY = (int)($logoY + $logoBoxSz / 2);
    $logoFs    = max(9, (int)($w * 0.018));

    $ltx1 = round($logoX + $logoBoxSz * 0.28, 1);
    $lty1 = round($logoY + $logoBoxSz * 0.22, 1);
    $ltx2 = round($logoX + $logoBoxSz * 0.82, 1);
    $lty2 = round($logoY + $logoBoxSz * 0.50, 1);
    $ltx3 = $ltx1;
    $lty3 = round($logoY + $logoBoxSz * 0.78, 1);

    $gridLines = '';
    $spacing   = 48;
    for ($y = 0; $y <= $h; $y += $spacing) {
        $gridLines .= "<line x1=\"0\" y1=\"{$y}\" x2=\"{$w}\" y2=\"{$y}\"/>\n";
    }
    for ($x = 0; $x <= $w; $x += $spacing) {
        $gridLines .= "<line x1=\"{$x}\" y1=\"0\" x2=\"{$x}\" y2=\"{$h}\"/>\n";
    }

    $wmBlock = '';
    if ($safeWmText !== '') {
        $wmBlur  = round($wmFontSize * 0.6, 1);
        $wmBlock = <<<WM
  <filter id="wm_drop" x="-20%" y="-20%" width="140%" height="140%">
    <feDropShadow dx="0" dy="0" stdDeviation="{$wmBlur}" flood-color="rgba(0,0,0,0.70)"/>
  </filter>
WM;
    }

    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$w} {$h}" width="{$w}" height="{$h}">
  <defs>
    <radialGradient id="bg" cx="50%" cy="40%" r="70%">
      <stop offset="0%" stop-color="#0e1117"/>
      <stop offset="100%" stop-color="#050508"/>
    </radialGradient>
    <radialGradient id="glow" cx="50%" cy="50%" r="50%">
      <stop offset="0%" stop-color="#e8ff47" stop-opacity="0.18"/>
      <stop offset="100%" stop-color="#e8ff47" stop-opacity="0"/>
    </radialGradient>
    <filter id="f_blur" x="-50%" y="-50%" width="200%" height="200%">
      <feGaussianBlur stdDeviation="10"/>
    </filter>
{$wmBlock}  </defs>
  <rect width="{$w}" height="{$h}" fill="url(#bg)"/>
  <g stroke="#e8ff47" stroke-opacity="0.04" stroke-width="1">
{$gridLines}  </g>
  <circle cx="{$cx}" cy="{$cy}" r="{$glowR}" fill="url(#glow)" filter="url(#f_blur)"/>
  <circle cx="{$cx}" cy="{$cy}" r="{$r}"
          fill="rgba(14,17,23,0.7)"
          stroke="#e8ff47" stroke-opacity="0.3" stroke-width="1.5"/>
  <polygon
    points="{$triX1},{$triY1} {$triX2},{$triY2} {$triX3},{$triY3}"
    fill="#e8ff47" fill-opacity="0.9"/>
  <text
    x="{$cx}" y="{$labelY}"
    font-family="'DM Mono','Courier New',monospace"
    font-size="{$fontSize}"
    fill="#eef1f8" fill-opacity="0.55"
    text-anchor="middle">{$safeTitle}</text>
SVG;

    if ($safeWmText !== '') {
        $svg .= <<<WM_SVG
  <text
    x="{$cx}" y="{$cy}"
    font-family="'DM Mono','Courier New',monospace"
    font-size="{$wmFontSize}"
    font-weight="500"
    fill="#ffffff" fill-opacity="{$wmOpacityStr}"
    text-anchor="middle"
    dominant-baseline="middle"
    filter="url(#wm_drop)">{$safeWmText}</text>
WM_SVG;
    }

    $svg .= <<<LOGO_SVG
  <rect x="{$logoX}" y="{$logoY}" width="{$logoBoxSz}" height="{$logoBoxSz}"
        rx="4" fill="#e8ff47"/>
  <polygon
    points="{$ltx1},{$lty1} {$ltx2},{$lty2} {$ltx3},{$lty3}"
    fill="#050508"/>
  <text
    x="{$logoTextX}" y="{$logoTextY}"
    font-family="'DM Mono','Courier New',monospace"
    font-size="{$logoFs}"
    fill="#eef1f8" fill-opacity="0.35"
    dominant-baseline="middle">VidShare</text>
</svg>
LOGO_SVG;

    header_remove();
    header('Content-Type: image/svg+xml; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');
    header('X-Thumb-Source: placeholder');
    header('X-Content-Type-Options: nosniff');
    echo $svg;
}