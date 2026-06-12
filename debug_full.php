<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/storage.php';

const STREAM_SECRET   = 'xK9#mP2$qL7@nR4!';
const INTERNAL_SECRET = 'INTERNAL_xK9#mP2$qL7@nR4!';

// ── Input ─────────────────────────────────────────────────────────────────
$code = trim($_GET['c'] ?? '');
if ($code === '') {
    exit('Tambahkan ?c=KODEMU di URL');
}

$video = getVideoByCode($code);
if (!$video) {
    exit('Video tidak ditemukan untuk code: ' . htmlspecialchars($code));
}

$url    = $video['url'] ?? '';
$host   = $_SERVER['HTTP_HOST'];
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

// ── Helper ────────────────────────────────────────────────────────────────
function badge(bool $ok, string $yes = 'YES', string $no = 'NO'): string
{
    $cls  = $ok ? 'ok' : 'err';
    $text = $ok ? $yes : $no;
    return "<span class=\"badge {$cls}\">{$text}</span>";
}

function row(string $label, string $value, string $class = ''): string
{
    $cls = $class ? " class=\"{$class}\"" : '';
    return "<tr><td>{$label}</td><td{$cls}>{$value}</td></tr>";
}

function curlRequest(string $targetUrl, array $headers, bool $sslVerify = true): array
{
    $ch = curl_init($targetUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => $sslVerify,
        CURLOPT_HEADER         => true,
    ]);

    $response = curl_exec($ch);
    $result   = [
        'errno'    => curl_errno($ch),
        'error'    => curl_error($ch),
        'info'     => curl_getinfo($ch),
        'response' => $response,
    ];
    curl_close($ch);

    return $result;
}

// ── Step 2: Enkripsi / Dekripsi ───────────────────────────────────────────
$iv        = random_bytes(16);
$encrypted = openssl_encrypt($url, 'AES-256-CBC', STREAM_SECRET, 0, $iv);
$token     = urlencode(base64_encode(base64_encode($iv) . '||' . $encrypted));

$payload             = base64_decode(urldecode($token));
[$ivBase64, $enc]    = explode('||', $payload, 2);
$ivDecoded           = base64_decode($ivBase64);
$decrypted           = openssl_decrypt($enc, 'AES-256-CBC', STREAM_SECRET, 0, $ivDecoded);

$tokenOk    = strlen($token) > 10;
$ivOk       = strlen($ivDecoded) === 16;
$decryptOk  = $decrypted === $url;

// ── Step 3: URL Sumber Langsung ───────────────────────────────────────────
$r3 = curlRequest($url, [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    'Accept: video/mp4,video/*,*/*;q=0.8',
    'Referer: ' . $scheme . '://' . $host . '/',
    'Range: bytes=0-1023',
]);

// ── Step 4: video.php via Loopback ────────────────────────────────────────
$r4 = curlRequest('http://127.0.0.1/video.php?t=' . $token, [
    'Host: '              . $host,
    'X-Internal-Secret: ' . INTERNAL_SECRET,
    'User-Agent: Mozilla/5.0',
    'Accept: video/mp4,video/*,*/*;q=0.8',
    'Range: bytes=0-1023',
], false);

$r4HeaderOnly = substr($r4['response'], 0, (int)($r4['info']['header_size'] ?? 500));

// ── HTML Output ───────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Debug — <?= htmlspecialchars($code) ?></title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', system-ui, sans-serif; font-size: 14px;
         background: #f5f5f4; color: #1c1c1b; padding: 2rem; }
  h1   { font-size: 18px; font-weight: 500; margin-bottom: 1.5rem; color: #444; }
  .section { background: #fff; border: 1px solid #e5e5e4; border-radius: 10px;
             margin-bottom: 1rem; overflow: hidden; }
  .section-header { display: flex; align-items: center; gap: 8px; padding: .6rem 1rem;
                    background: #fafaf9; border-bottom: 1px solid #e5e5e4; }
  .step-num   { font-size: 11px; color: #999; }
  .step-title { font-size: 13px; font-weight: 500; }
  .badge { font-size: 11px; font-weight: 500; padding: 2px 10px; border-radius: 20px; }
  .ok  { background: #dcfce7; color: #166534; }
  .err { background: #fee2e2; color: #991b1b; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: .4rem 1rem; vertical-align: top; }
  td:first-child { color: #777; width: 36%; white-space: nowrap; }
  tr:not(:last-child) td { border-bottom: 1px solid #f0f0ef; }
  .val-ok   { color: #166534; font-weight: 500; }
  .val-err  { color: #991b1b; font-weight: 500; }
  .val-muted { color: #aaa; }
  .headers  { font-family: monospace; font-size: 12px; line-height: 1.9;
              white-space: pre; padding: .75rem 1rem; color: #555;
              border-top: 1px solid #f0f0ef; overflow-x: auto; }
</style>
</head>
<body>

<h1>🔍 Debug — <code><?= htmlspecialchars($code) ?></code></h1>

<!-- Step 1 -->
<div class="section">
  <div class="section-header">
    <span class="step-num">Step 1</span>
    <span class="step-title">Data video</span>
    <?= badge(true) ?>
  </div>
  <table>
    <?= row('Code', htmlspecialchars($code)) ?>
    <?= row('URL',  htmlspecialchars($url)) ?>
  </table>
</div>

<!-- Step 2 -->
<div class="section">
  <div class="section-header">
    <span class="step-num">Step 2</span>
    <span class="step-title">Enkripsi / dekripsi</span>
    <?= badge($tokenOk && $ivOk && $decryptOk) ?>
  </div>
  <table>
    <?= row('Token valid',    $tokenOk   ? '<span class="val-ok">YES</span>'  : '<span class="val-err">NO</span>') ?>
    <?= row('IV length',      $ivOk      ? '<span class="val-ok">16 bytes ✓</span>' : '<span class="val-err">' . strlen($ivDecoded) . ' bytes ✗</span>') ?>
    <?= row('Decrypt match',  $decryptOk ? '<span class="val-ok">YES</span>'  : '<span class="val-err">NO</span>') ?>
  </table>
</div>

<!-- Step 3 -->
<?php
$code3 = (int)$r3['info']['http_code'];
$ok3   = $code3 >= 200 && $code3 < 300 || $code3 === 206;
?>
<div class="section">
  <div class="section-header">
    <span class="step-num">Step 3</span>
    <span class="step-title">URL sumber langsung</span>
    <?= badge($ok3 && !$r3['errno'], (string)$code3, 'ERROR') ?>
  </div>
  <table>
    <?= row('HTTP code',    '<span class="' . ($ok3 ? 'val-ok' : 'val-err') . '">' . $code3 . '</span>') ?>
    <?= row('cURL error',   $r3['errno'] ? '<span class="val-err">[' . $r3['errno'] . '] ' . htmlspecialchars($r3['error']) . '</span>' : '<span class="val-muted">none</span>') ?>
    <?= row('Content-Type', htmlspecialchars($r3['info']['content_type'] ?? '—')) ?>
    <?= row('Downloaded',   number_format((int)$r3['info']['size_download']) . ' bytes') ?>
    <?= row('Redirect',     $r3['info']['redirect_url'] ?: '<span class="val-muted">none</span>') ?>
    <?= row('Final URL',    htmlspecialchars($r3['info']['url'])) ?>
  </table>
</div>

<!-- Step 4 -->
<?php
$code4 = (int)$r4['info']['http_code'];
$ok4   = $code4 >= 200 && $code4 < 300 || $code4 === 206;
?>
<div class="section">
  <div class="section-header">
    <span class="step-num">Step 4</span>
    <span class="step-title">video.php via loopback</span>
    <?= badge($ok4 && !$r4['errno'], (string)$code4, 'ERROR') ?>
  </div>
  <table>
    <?= row('HTTP code',  '<span class="' . ($ok4 ? 'val-ok' : 'val-err') . '">' . $code4 . '</span>') ?>
    <?= row('cURL error', $r4['errno'] ? '<span class="val-err">[' . $r4['errno'] . '] ' . htmlspecialchars($r4['error']) . '</span>' : '<span class="val-muted">none</span>') ?>
    <?= row('Downloaded', number_format((int)$r4['info']['size_download']) . ' bytes') ?>
  </table>
  <div class="headers"><?= htmlspecialchars($r4HeaderOnly) ?></div>
</div>

</body>
</html>