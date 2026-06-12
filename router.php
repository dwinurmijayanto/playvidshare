<?php
/**
 * Router — dispatches requests to the correct handler.
 * This file is included by .htaccess when the URL is not a real file.
 *
 * Routes:
 *   /               → index.php   (homepage / generator)
 *   /{8-char-code}  → player.php  (video player, kode lama)
 *   /{13-char-code} → player.php  (video player, kode lama v2)
 *   /{15-char-code} → player.php  (video player, kode baru multi-server)
 */
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path       = parse_url($requestUri, PHP_URL_PATH);
$path       = '/' . ltrim($path, '/');
// Strip trailing slash
$path = rtrim($path, '/') ?: '/';

// Match kode: 8 char (lama) | 13 char (lama v2) | 15 char (baru multi-server)
if (preg_match('#^/([a-zA-Z0-9]{8}|[a-zA-Z0-9]{13}|[a-zA-Z0-9]{15})$#', $path, $m)) {
    $_GET['code'] = $m[1];
    require __DIR__ . '/player.php';
    exit;
}

// Homepage
if ($path === '/' || $path === '/index.php') {
    require __DIR__ . '/index.php';
    exit;
}

// 404
http_response_code(404);
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>404 — VidShare</title>
<style>
  body{background:#080a0f;color:#eef1f8;font-family:monospace;
       display:flex;align-items:center;justify-content:center;min-height:100vh;}
  .box{text-align:center;}
  h1{font-size:4rem;color:#e8ff47;}
  p{color:#5a6177;margin-top:.5rem;}
  a{color:#47c8ff;}
</style>
</head>
<body>
<div class="box">
  <h1>404</h1>
  <p>Halaman tidak ditemukan.</p>
  <p><a href="/">← Kembali ke beranda</a></p>
</div>
</body>
</html>