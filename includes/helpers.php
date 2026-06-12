<?php
/**
 * Helper Functions
 * File: includes/helpers.php
 *
 * Disesuaikan dengan storage.php v3 (kode 15 karakter, multi-server)
 *
 * generateUniqueCode() TIDAK didefinisikan di sini — sudah ada di storage.php.
 * File ini menyediakan:
 *   - saveVideoFromUpload()   : simpan hasil action=upload (kedua/semua server paralel)
 *   - saveVideoFromS1()       : simpan hasil action=single_s1
 *   - saveVideoFromS2()       : simpan hasil action=single_s2
 *   - saveVideoFromS3()       : simpan hasil action=single_s3
 *   - saveVideoAfterUpload()  : hook ringkas untuk upload.php
 *   - buildPlayerUrl()        : bangun URL player dari kode
 *   - getBaseUrl()            : deteksi base URL dinamis
 *
 * ════════════════════════════════════════════════════════════════
 * PENTING — Setiap server mendapat kode BERBEDA
 * ════════════════════════════════════════════════════════════════
 *
 * Saat upload paralel ke 3 server, pemanggil wajib memanggil
 * generateUniqueCode() + saveVideo() terpisah per server:
 *
 *   $codeS1 = generateUniqueCode('s1');
 *   $codeS2 = generateUniqueCode('s2');
 *   $codeS3 = generateUniqueCode('s3');
 *
 * Jangan memakai 1 kode untuk 3 server — kode mengandung info
 * lokasi file, sehingga harus unik per server.
 */

require_once __DIR__ . '/storage.php';

// ════════════════════════════════════════════════════
// PLAYER URL BUILDER
// ════════════════════════════════════════════════════

/**
 * Bangun URL player dari kode.
 * Selalu menggunakan domain play.vidshare.my.id.
 */
function buildPlayerUrl(string $code): string
{
    return 'https://play.vidshare.my.id/' . $code;
}

// ════════════════════════════════════════════════════
// HELPER INTERNAL — simpan satu server
// ════════════════════════════════════════════════════

/**
 * Inti penyimpanan untuk satu server.
 * Cek duplikat URL di server yang sama, generate kode baru jika perlu.
 *
 * @param  string $cdnUrl   URL CDN video
 * @param  string $title    Judul video
 * @param  string $server   's1' | 's2' | 's3' | 'server1' | dst.
 * @return array  ['code', 'player_url', 'entry', 'duplicate']
 * @throws RuntimeException jika URL kosong
 */
function saveVideoForServer(string $cdnUrl, string $title, string $server): array
{
    $cdnUrl = trim($cdnUrl);
    if ($cdnUrl === '') {
        throw new RuntimeException("saveVideoForServer: CDN URL kosong untuk server {$server}.");
    }

    $title = trim($title) ?: 'Video';

    // Cek duplikat URL (scan semua file server yang sama saja)
    $existing = getVideoByUrlForServer($cdnUrl, $server);
    if ($existing) {
        return [
            'code'       => $existing['code'],
            'player_url' => buildPlayerUrl($existing['code']),
            'entry'      => $existing,
            'duplicate'  => true,
        ];
    }

    $code  = generateUniqueCode($server);
    $entry = saveVideo($code, $cdnUrl, $title);

    return [
        'code'       => $code,
        'player_url' => buildPlayerUrl($code),
        'entry'      => $entry,
        'duplicate'  => false,
    ];
}

/**
 * Cek duplikat URL hanya di file milik server tertentu.
 * Lebih efisien daripada getVideoByUrl() yang scan semua file.
 */
function getVideoByUrlForServer(string $url, string $server): ?array
{
    foreach (getServerDataFiles($server) as $file) {
        $data = loadFile($file);
        foreach ($data as $entry) {
            if (($entry['url'] ?? '') === $url) return $entry;
        }
    }
    return null;
}

// ════════════════════════════════════════════════════
// SIMPAN VIDEO PER SERVER
// ════════════════════════════════════════════════════

/**
 * Simpan video dari respons action=single_s1.
 *
 * @param  array  $s1Result  Respons JSON dari server 1
 * @param  string $title
 * @return array  ['code', 'player_url', 'entry', 'duplicate']
 * @throws RuntimeException jika tidak ada CDN URL
 */
function saveVideoFromS1(array $s1Result, string $title = 'Video'): array
{
    $cdnUrl = $s1Result['cdn_url']
           ?? $s1Result['primary']['cdn_url']
           ?? null;

    if (!$cdnUrl) {
        throw new RuntimeException('Tidak ada CDN URL dari Server 1.');
    }

    return saveVideoForServer($cdnUrl, $title, 's1');
}

/**
 * Simpan video dari respons action=single_s2.
 *
 * @param  array  $s2Result  Respons JSON dari server 2
 * @param  string $title
 * @return array  ['code', 'player_url', 'entry', 'duplicate']
 * @throws RuntimeException jika tidak ada CDN URL
 */
function saveVideoFromS2(array $s2Result, string $title = 'Video'): array
{
    $cdnUrl = $s2Result['cdn_url']
           ?? $s2Result['primary']['cdn_url']
           ?? null;

    if (!$cdnUrl) {
        throw new RuntimeException('Tidak ada CDN URL dari Server 2.');
    }

    return saveVideoForServer($cdnUrl, $title, 's2');
}

/**
 * Simpan video dari respons action=single_s3.
 *
 * @param  array  $s3Result  Respons JSON dari server 3
 * @param  string $title
 * @return array  ['code', 'player_url', 'entry', 'duplicate']
 * @throws RuntimeException jika tidak ada CDN URL
 */
function saveVideoFromS3(array $s3Result, string $title = 'Video'): array
{
    $cdnUrl = $s3Result['cdn_url']
           ?? $s3Result['primary']['cdn_url']
           ?? null;

    if (!$cdnUrl) {
        throw new RuntimeException('Tidak ada CDN URL dari Server 3.');
    }

    return saveVideoForServer($cdnUrl, $title, 's3');
}

// ════════════════════════════════════════════════════
// SIMPAN VIDEO DARI UPLOAD PARALEL (semua server)
// ════════════════════════════════════════════════════

/**
 * Simpan video dari respons action=upload (upload paralel ke semua server).
 *
 * Format $uploadResult yang diterima (contoh):
 * [
 *   's1' => ['cdn_url' => '...'],
 *   's2' => ['cdn_url' => '...'],
 *   's3' => ['cdn_url' => '...'],
 * ]
 *
 * Atau format lama (hanya 1 server):
 * ['cdn_url' => '...', 'primary' => ['cdn_url' => '...']]
 *
 * Return:
 * [
 *   's1' => ['code'=>..., 'player_url'=>..., 'entry'=>..., 'duplicate'=>...],
 *   's2' => ['code'=>..., 'player_url'=>..., 'entry'=>..., 'duplicate'=>...],
 *   's3' => ['code'=>..., 'player_url'=>..., 'entry'=>..., 'duplicate'=>...],
 *   'primary_code'       => '...',   // kode s1 (atau server pertama yang berhasil)
 *   'primary_player_url' => '...',
 * ]
 *
 * Server yang tidak ada CDN URL-nya akan dilewati (null di return).
 *
 * @param  array  $uploadResult
 * @param  string $title
 * @return array
 */
function saveVideoFromUpload(array $uploadResult, string $title = 'Video'): array
{
    $title  = trim($title) ?: 'Video';
    $result = [];

    // Deteksi apakah format multi-server (key 's1'/'s2'/'s3') atau format lama
    $isMultiServer = isset($uploadResult['s1']) || isset($uploadResult['s2']) || isset($uploadResult['s3']);

    if ($isMultiServer) {
        // ── Format multi-server ─────────────────────────
        foreach (['s1', 's2', 's3'] as $srv) {
            if (!isset($uploadResult[$srv])) {
                $result[$srv] = null;
                continue;
            }

            $cdnUrl = $uploadResult[$srv]['cdn_url'] ?? null;
            if (!$cdnUrl) {
                $result[$srv] = null;
                continue;
            }

            try {
                $result[$srv] = saveVideoForServer($cdnUrl, $title, $srv);
            } catch (RuntimeException $e) {
                error_log("saveVideoFromUpload [{$srv}]: " . $e->getMessage());
                $result[$srv] = null;
            }
        }
    } else {
        // ── Format lama (single / parallel lama) ────────
        // Coba ambil dari berbagai field yang mungkin ada
        $cdnS1 = $uploadResult['cdn_url']
              ?? $uploadResult['primary']['cdn_url']
              ?? null;
        $cdnS2 = $uploadResult['s2_cdn_url']
              ?? $uploadResult['secondary']['cdn_url']
              ?? null;

        if ($cdnS1) {
            try { $result['s1'] = saveVideoForServer($cdnS1, $title, 's1'); }
            catch (RuntimeException $e) { error_log('saveVideoFromUpload [s1]: ' . $e->getMessage()); $result['s1'] = null; }
        } else {
            $result['s1'] = null;
        }

        if ($cdnS2) {
            try { $result['s2'] = saveVideoForServer($cdnS2, $title, 's2'); }
            catch (RuntimeException $e) { error_log('saveVideoFromUpload [s2]: ' . $e->getMessage()); $result['s2'] = null; }
        } else {
            $result['s2'] = null;
        }

        $result['s3'] = null;
    }

    // Tentukan primary (kode pertama yang berhasil: s1 → s2 → s3)
    $primary = $result['s1'] ?? $result['s2'] ?? $result['s3'] ?? null;

    $result['primary_code']       = $primary['code']       ?? null;
    $result['primary_player_url'] = $primary['player_url'] ?? null;

    return $result;
}

// ════════════════════════════════════════════════════
// HOOK RINGKAS UNTUK upload.php
// ════════════════════════════════════════════════════

/**
 * Hook ringkas untuk dipanggil di upload.php sebelum jsonOut().
 *
 * Contoh pemakaian di upload.php (upload paralel):
 *
 *   // Setelah dapat response dari semua server:
 *   $uploadResult = [
 *       's1' => ['cdn_url' => $s1CdnUrl],
 *       's2' => ['cdn_url' => $s2CdnUrl],
 *       's3' => ['cdn_url' => $s3CdnUrl],
 *   ];
 *   try {
 *       $saved = saveVideoAfterUpload($uploadResult, $title);
 *       $data['stored_s1']    = $saved['s1'];
 *       $data['stored_s2']    = $saved['s2'];
 *       $data['stored_s3']    = $saved['s3'];
 *       $data['stored_code']  = $saved['primary_code'];
 *       $data['stored_url']   = $saved['primary_player_url'];
 *   } catch (Exception $e) {
 *       error_log('Storage error: ' . $e->getMessage());
 *   }
 *   jsonOut($data);
 *
 * Atau saat upload single server:
 *
 *   $saved = saveVideoAfterUpload(['cdn_url' => $cdnUrl], $title);
 *   $data['stored_code'] = $saved['primary_code'];
 *   $data['stored_url']  = $saved['primary_player_url'];
 *
 * @param  array  $responseData  Array hasil upload
 * @param  string $title
 * @return array  Hasil saveVideoFromUpload()
 */
function saveVideoAfterUpload(array $responseData, string $title = 'Video'): array
{
    return saveVideoFromUpload($responseData, $title);
}

// ════════════════════════════════════════════════════
// BASE URL
// ════════════════════════════════════════════════════

/**
 * Deteksi base URL dinamis — support Cloudflare, reverse proxy, dll.
 */
function getBaseUrl(): string
{
    $host = $_SERVER['REDIRECT_REAL_HOST']
         ?? $_SERVER['HTTP_X_FORWARDED_HOST']
         ?? $_SERVER['HTTP_HOST']
         ?? 'play.vidshare.my.id';

    // Ambil entry pertama jika ada beberapa nilai
    // misal: "upload.vbi1.eu.cc, proxy.internal"
    $host = trim(explode(',', $host)[0]);

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || ($_SERVER['HTTP_X_FORWARDED_SSL']   ?? '') === 'on'
            || ($_SERVER['SERVER_PORT']             ?? '') === '443';

    $scheme = $isHttps ? 'https' : 'http';
    return $scheme . '://' . $host;
}