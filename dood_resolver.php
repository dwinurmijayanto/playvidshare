<?php
/**
 * dood_resolver.php
 * Dipanggil via AJAX dari player page.
 * GET /dood_resolver.php?url=ENCODED_EMBED_URL
 *
 * Response:
 *   { "status": "ok",    "video_url": "...", "title": "..." }
 *   { "status": "error", "message":   "..." }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// ── Domain Doodstream yang dikenal ────────────────────────────
const DOOD_DOMAINS = [
    'doodstream.com','doodstream.co',
    'dood.la','dood.pm','dood.re','dood.sh','dood.so',
    'dood.to','dood.watch','dood.wf','dood.ws','dood.yt',
    'dood.cx','dood.one','dood.pro','doods.pro',
    'dooood.com','dooood.co','ds2play.com','playmogo.com',
];

// ── Validasi parameter ────────────────────────────────────────
$raw = trim($_GET['url'] ?? '');
if (!$raw) out_error('Parameter url kosong.');

$embedUrl = filter_var($raw, FILTER_VALIDATE_URL) ? $raw : null;
if (!$embedUrl) out_error('Format URL tidak valid.');

// Cek apakah domain termasuk Doodstream
$host = strtolower(preg_replace('/^www\./', '', parse_url($embedUrl, PHP_URL_HOST) ?? ''));
$isDood = false;
foreach (DOOD_DOMAINS as $d) {
    if ($host === $d || str_ends_with($host, '.' . $d)) { $isDood = true; break; }
}
if (!$isDood) out_error('Domain bukan Doodstream yang dikenal.');

// Normalisasi: /d/ → /e/ (pastikan halaman embed, bukan download)
$embedUrl = preg_replace('#/(d)/#', '/e/', $embedUrl, 1);

// ── Langkah 1: Fetch halaman embed ───────────────────────────
$html = curl_get($embedUrl);
if (!$html) out_error('Gagal mengambil halaman embed. Mungkin video private/dihapus.');

// Ambil judul
$title = 'Video';
if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $tm)) {
    $title = html_entity_decode(trim(preg_replace('/\s*[\|\-–]\s*Dood.*$/i', '', $tm[1])));
}

// ── Langkah 2: Ekstrak path pass_md5 dari HTML ───────────────
// Sama persis dengan bookmarklet: /pass_md5/[a-zA-Z0-9\-_.]* 
if (!preg_match('#(/pass_md5/[a-zA-Z0-9\-_./]*)#', $html, $m)) {
    out_error('Token pass_md5 tidak ditemukan. Video mungkin belum siap atau sudah dihapus.');
}
$pass_md5_path = $m[1];

// Ekstrak token dari URL pass_md5 (parameter ?token=xxx)
$token = '';
if (preg_match('/[?&]token=([a-z0-9]+)/i', $html, $tm2)) {
    $token = $tm2[1];
} elseif (preg_match('/[?&]token=([a-z0-9]+)/i', $pass_md5_path, $tm3)) {
    $token = $tm3[1];
}

// Build URL pass_md5 lengkap
$scheme   = parse_url($embedUrl, PHP_URL_SCHEME);
$authority = $scheme . '://' . parse_url($embedUrl, PHP_URL_HOST);
$pass_md5_url = (str_starts_with($pass_md5_path, 'http')) 
    ? $pass_md5_path 
    : $authority . $pass_md5_path;

// ── Langkah 3: Fetch pass_md5 dengan Referer ─────────────────
// Ini WAJIB — Doodstream cek Referer sebelum kasih base URL video
$base_video = curl_get($pass_md5_url, referer: $embedUrl);
if (!$base_video) out_error('Gagal mengambil base URL video dari pass_md5.');

$base_video = trim($base_video);

// ── Langkah 4: Rakit final URL ───────────────────────────────
// Format: {base}{10_random_chars}?token={token}&expiry={timestamp_ms}
$chars   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
$rand10  = '';
for ($i = 0; $i < 10; $i++) {
    $rand10 .= $chars[random_int(0, strlen($chars) - 1)];
}
$expiry    = (int)(microtime(true) * 1000);
$video_url = $base_video . $rand10 . '?token=' . $token . '&expiry=' . $expiry;

// ── Sukses ───────────────────────────────────────────────────
echo json_encode([
    'status'    => 'ok',
    'video_url' => $video_url,
    'title'     => $title,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit;

// ── Helper functions ──────────────────────────────────────────
function curl_get(string $url, string $referer = ''): ?string
{
    $ch = curl_init($url);
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/124.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.5',
        'Connection: keep-alive',
    ];
    if ($referer) $headers[] = 'Referer: ' . $referer;

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING       => 'gzip, deflate',
        CURLOPT_COOKIEFILE     => '',  // aktifkan cookie jar in-memory
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $err || $code >= 400) return null;
    return $body;
}

function out_error(string $msg): never
{
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}