<?php
/**
 * play.vidshare.my.id/api/save.php
 *
 * Endpoint internal — dipanggil oleh upload.vidshare.my.id
 * setelah upload ke S1/S2 berhasil.
 *
 * Method : POST
 * Headers: X-VidShare-Secret: <SAVE_SECRET>
 * Body   : url=<cdn_url>&title=<judul>
 *
 * Response JSON:
 *   { success: true,  code, player_url, duplicate }
 *   { success: false, error }
 */

declare(strict_types=1);

// ── Hanya izinkan POST ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'error' => 'Method not allowed']));
}

// ── Secret key — wajib cocok dengan upload.vidshare.my.id ────
define('SAVE_SECRET', 'VS_SAVE_xK9#mP2$qL7@nR4!');

$incoming = $_SERVER['HTTP_X_VIDSHARE_SECRET'] ?? '';
if (!hash_equals(SAVE_SECRET, $incoming)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'error' => 'Unauthorized']));
}

// ── Load storage ──────────────────────────────────────────────
require_once __DIR__ . '/../includes/storage.php';

// ── Input ─────────────────────────────────────────────────────
$url   = trim($_POST['url']   ?? '');
$title = trim($_POST['title'] ?? 'Video') ?: 'Video';
$title = mb_substr($title, 0, 120);

if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'error' => 'URL tidak valid']));
}

// ── Cek duplikat ──────────────────────────────────────────────
header('Content-Type: application/json');

try {
    $existing = getVideoByUrl($url);
    if ($existing) {
        echo json_encode([
            'success'    => true,
            'code'       => $existing['code'],
            'player_url' => 'https://play.vidshare.my.id/' . $existing['code'],
            'duplicate'  => true,
        ]);
        exit;
    }

    // ── Simpan baru ───────────────────────────────────────────
    $code  = generateUniqueCode();
    saveVideo($code, $url, $title);

    echo json_encode([
        'success'    => true,
        'code'       => $code,
        'player_url' => 'https://play.vidshare.my.id/' . $code,
        'duplicate'  => false,
    ]);

} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
