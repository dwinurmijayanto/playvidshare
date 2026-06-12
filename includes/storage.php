<?php
/**
 * Storage Helper — HTTP-based, data di playdata.vidshare.my.id
 * File: includes/storage.php
 *
 * ════════════════════════════════════════════════════════════════
 * ARSITEKTUR
 * ════════════════════════════════════════════════════════════════
 *
 *   play.vidshare.my.id  (Vercel — PHP)
 *     ↓ GET  /data/{filename}   → baca file JSON
 *     ↓ PUT  /data/{filename}   → tulis/update file JSON
 *     ↓ GET  /list              → list semua file JSON
 *   playdata.vidshare.my.id  (VPS — REST API)
 *
 * ════════════════════════════════════════════════════════════════
 * FORMAT KODE (tidak berubah)
 * ════════════════════════════════════════════════════════════════
 *
 * BARU (15 karakter):
 *   [2 char server][2 char bulan][1 char file-ke][10 char random]
 *
 * LAMA-13 (13 karakter):
 *   [2 char bulan][1 char file-ke][10 char random]
 *
 * LAMA-8 (8 karakter):
 *   alfanumerik acak murni
 */

define('DATA_API_BASE',   'https://playdata.vidshare.my.id');
define('DATA_API_SECRET', 'xK9#mP2$qL7@nR4!');
define('FILE_SIZE_LIMIT', 512 * 1024);  // 512 KB per file (perkiraan, dicek dari API)
define('CODE_RANDOM_LEN', 10);

// ════════════════════════════════════════════════════
// HTTP CLIENT
// ════════════════════════════════════════════════════

/**
 * GET /data/{filename} → array data JSON, atau [] jika tidak ada.
 */
function apiGetFile(string $filename): array
{
    $url = DATA_API_BASE . '/data/' . rawurlencode($filename);
    $result = apiRequest('GET', $url);
    if ($result['status'] === 404) return [];
    if ($result['status'] !== 200) return [];
    $data = json_decode($result['body'], true);
    return is_array($data) ? $data : [];
}

/**
 * PUT /data/{filename} → tulis/replace isi file JSON.
 * Return true jika berhasil.
 */
function apiPutFile(string $filename, array $data): bool
{
    $url  = DATA_API_BASE . '/data/' . rawurlencode($filename);
    $body = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($body === false) return false;
    $result = apiRequest('PUT', $url, $body);
    if ($result['status'] === 200 || $result['status'] === 201) return true;

    $detail = $result['curl_error'] ?? $result['body'] ?? '';
    throw new RuntimeException(
        "apiPutFile gagal [{$filename}] — HTTP {$result['status']}: {$detail}"
    );
}

/**
 * HEAD /data/{filename} → cek apakah file ada + ukurannya.
 * Return ['exists' => bool, 'size' => int]
 */
function apiStatFile(string $filename): array
{
    $url    = DATA_API_BASE . '/data/' . rawurlencode($filename);
    $result = apiRequest('HEAD', $url);
    return [
        'exists' => $result['status'] === 200,
        'size'   => (int) ($result['headers']['content-length'] ?? 0),
    ];
}

/**
 * GET /list → list semua filename JSON yang tersedia.
 * Return array of string filename.
 */
function apiListFiles(): array
{
    $url    = DATA_API_BASE . '/list';
    $result = apiRequest('GET', $url);
    if ($result['status'] !== 200) return [];
    $data = json_decode($result['body'], true);
    return is_array($data['files'] ?? null) ? $data['files'] : [];
}

/**
 * HTTP request ke playdata API.
 * Semua request disertai header Authorization.
 */
function apiRequest(string $method, string $url, string $body = ''): array
{
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . DATA_API_SECRET,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HEADER         => true,
    ]);

    if ($body !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response   = curl_exec($ch);
    $curlError  = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    // curl_close() tidak dipanggil — deprecated sejak PHP 8.5, no-op sejak PHP 8.0

    if ($response === false) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'curl_error' => $curlError];
    }

    $rawHeaders  = substr($response, 0, $headerSize);
    $responseBody = substr($response, $headerSize);

    // Parse headers sederhana
    $headers = [];
    foreach (explode("\r\n", $rawHeaders) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }

    return ['status' => $statusCode, 'headers' => $headers, 'body' => $responseBody];
}

// ════════════════════════════════════════════════════
// SERVER PREFIX
// ════════════════════════════════════════════════════

const VALID_SERVERS = ['s1', 's2', 's3'];

function isValidServer(string $srv): bool
{
    return in_array($srv, VALID_SERVERS, true);
}

// ════════════════════════════════════════════════════
// BULAN ENCODING
// ════════════════════════════════════════════════════

const MONTH_ENCODE = [
    1=>'1a',2=>'2f',3=>'3m',4=>'4a',5=>'5m',6=>'6j',
    7=>'7j',8=>'8a',9=>'9s',10=>'0o',11=>'xn',12=>'xd',
];
const MONTH_DECODE = [
    '1a'=>1,'2f'=>2,'3m'=>3,'4a'=>4,'5m'=>5,'6j'=>6,
    '7j'=>7,'8a'=>8,'9s'=>9,'0o'=>10,'xn'=>11,'xd'=>12,
];

function encodeMonth(int $month): string
{
    return MONTH_ENCODE[$month] ?? '1a';
}

function decodeMonth(string $prefix): ?int
{
    return MONTH_DECODE[$prefix] ?? null;
}

// ════════════════════════════════════════════════════
// FILE NUMBERING (base-36: 1-9, a-z)
// ════════════════════════════════════════════════════

function encodeFileNum(int $n): string
{
    if ($n >= 1 && $n <= 9) return (string) $n;
    return chr(ord('a') + $n - 10);
}

function decodeFileNum(string $c): int
{
    if (ctype_digit($c) && $c !== '0') return (int) $c;
    $v = ord(strtolower($c)) - ord('a') + 10;
    return ($v >= 10) ? $v : 0;
}

// ════════════════════════════════════════════════════
// DETEKSI FORMAT KODE
// ════════════════════════════════════════════════════

function isNewFormatCode(string $code): bool
{
    if (strlen($code) !== 15) return false;
    $srv    = substr($code, 0, 2);
    $month  = substr($code, 2, 2);
    $fileCh = $code[4];
    if (!isValidServer($srv)) return false;
    if (decodeMonth($month) === null) return false;
    return decodeFileNum($fileCh) >= 1;
}

function isLegacy13Code(string $code): bool
{
    if (strlen($code) !== 13) return false;
    $month  = substr($code, 0, 2);
    $fileCh = $code[2];
    if (decodeMonth($month) === null) return false;
    return decodeFileNum($fileCh) >= 1;
}

function isLegacy8Code(string $code): bool
{
    return strlen($code) === 8 && ctype_alnum($code);
}

// ════════════════════════════════════════════════════
// FILENAME BUILDER (menggantikan path lokal)
// ════════════════════════════════════════════════════

function buildNewFilename(string $server, int $year, int $month, int $fileNum): string
{
    return $server . '_videos_'
        . $year . '_'
        . str_pad($month, 2, '0', STR_PAD_LEFT) . '_'
        . $fileNum . '.json';
}

function buildLegacyFilename(int $year, int $month, int $fileNum): string
{
    return 'videos_'
        . $year . '_'
        . str_pad($month, 2, '0', STR_PAD_LEFT) . '_'
        . $fileNum . '.json';
}

// ════════════════════════════════════════════════════
// DECODE KODE → INFO FILE
// ════════════════════════════════════════════════════

function decodeNewCode(string $code): ?array
{
    $server  = substr($code, 0, 2);
    $month   = decodeMonth(substr($code, 2, 2));
    $fileNum = decodeFileNum($code[4]);
    if ($month === null || $fileNum < 1 || !isValidServer($server)) return null;

    $currentYear = (int) date('Y');
    for ($i = 0; $i <= 3; $i++) {
        $year     = $currentYear - $i;
        $filename = buildNewFilename($server, $year, $month, $fileNum);
        $stat     = apiStatFile($filename);
        if ($stat['exists']) {
            return compact('server', 'year', 'month', 'fileNum', 'filename');
        }
    }

    // File belum ada → default tahun sekarang
    $year = $currentYear;
    return [
        'server'   => $server,
        'year'     => $year,
        'month'    => $month,
        'fileNum'  => $fileNum,
        'filename' => buildNewFilename($server, $year, $month, $fileNum),
    ];
}

function decodeLegacy13Code(string $code): ?array
{
    $month   = decodeMonth(substr($code, 0, 2));
    $fileNum = decodeFileNum($code[2]);
    if ($month === null || $fileNum < 1) return null;

    $currentYear = (int) date('Y');
    for ($i = 0; $i <= 3; $i++) {
        $year     = $currentYear - $i;
        $filename = buildLegacyFilename($year, $month, $fileNum);
        $stat     = apiStatFile($filename);
        if ($stat['exists']) {
            return compact('year', 'month', 'fileNum', 'filename');
        }
    }

    $year = $currentYear;
    return [
        'year'     => $year,
        'month'    => $month,
        'fileNum'  => $fileNum,
        'filename' => buildLegacyFilename($year, $month, $fileNum),
    ];
}

// ════════════════════════════════════════════════════
// FILE AKTIF (untuk write baru)
// ════════════════════════════════════════════════════

function getActiveFile(string $server = 's1'): array
{
    $srv   = normalizeServerPrefix($server);
    $year  = (int) date('Y');
    $month = (int) date('n');

    for ($num = 1; $num <= 35; $num++) {
        $filename = buildNewFilename($srv, $year, $month, $num);
        $stat     = apiStatFile($filename);
        if (!$stat['exists'] || $stat['size'] < FILE_SIZE_LIMIT) {
            return compact('filename', 'year', 'month', 'num') + ['server' => $srv, 'fileNum' => $num];
        }
    }

    // Fallback: file ke-35
    $filename = buildNewFilename($srv, $year, $month, 35);
    return [
        'filename' => $filename,
        'year'     => $year,
        'month'    => $month,
        'fileNum'  => 35,
        'server'   => $srv,
    ];
}

function normalizeServerPrefix(string $input): string
{
    $input = strtolower(trim($input));
    $map = [
        's1' => 's1', 's2' => 's2', 's3' => 's3',
        'server1' => 's1', 'server2' => 's2', 'server3' => 's3',
        '1' => 's1', '2' => 's2', '3' => 's3',
    ];
    return $map[$input] ?? 's1';
}

// ════════════════════════════════════════════════════
// LOAD / WRITE (via API, menggantikan loadFile / safeWriteFile)
// ════════════════════════════════════════════════════

function loadFile(string $filename): array
{
    return apiGetFile($filename);
}

function safeWriteFile(string $filename, array $data): bool
{
    return apiPutFile($filename, $data);
}

// ════════════════════════════════════════════════════
// LIST FILE (via API, menggantikan glob)
// ════════════════════════════════════════════════════

function getAllNewDataFiles(): array
{
    $all = apiListFiles();
    $files = array_filter($all, function ($f) {
        foreach (VALID_SERVERS as $srv) {
            if (str_starts_with($f, $srv . '_videos_')) return true;
        }
        return false;
    });
    rsort($files);
    return array_values($files);
}

function getAllLegacyDataFiles(): array
{
    $all = apiListFiles();
    $files = array_filter($all, fn($f) => str_starts_with($f, 'videos_'));
    rsort($files);
    return array_values($files);
}

function getAllDataFiles(): array
{
    return array_merge(getAllNewDataFiles(), getAllLegacyDataFiles());
}

function getServerDataFiles(string $server): array
{
    $srv = normalizeServerPrefix($server);
    $all = apiListFiles();
    $files = array_filter($all, fn($f) => str_starts_with($f, $srv . '_videos_'));
    rsort($files);
    return array_values($files);
}

// ════════════════════════════════════════════════════
// PUBLIC API (logika tidak berubah, hanya path → filename)
// ════════════════════════════════════════════════════

function generateUniqueCode(string $server = 's1'): string
{
    $srv    = normalizeServerPrefix($server);
    $active = getActiveFile($srv);

    $monthPfx = encodeMonth($active['month']);
    $filePfx  = encodeFileNum($active['fileNum']);
    $charset  = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $max      = strlen($charset) - 1;

    for ($attempt = 0; $attempt < 100; $attempt++) {
        $random = '';
        for ($i = 0; $i < CODE_RANDOM_LEN; $i++) {
            $random .= $charset[random_int(0, $max)];
        }
        $code = $srv . $monthPfx . $filePfx . $random;
        if (!codeExists($code)) return $code;
    }

    throw new RuntimeException('Tidak bisa generate kode unik setelah 100 percobaan.');
}

function codeExists(string $code): bool
{
    if (isNewFormatCode($code)) {
        $info = decodeNewCode($code);
        if (!$info) return false;
        $stat = apiStatFile($info['filename']);
        if (!$stat['exists']) return false;
        return isset(loadFile($info['filename'])[$code]);
    }

    if (isLegacy13Code($code)) {
        $info = decodeLegacy13Code($code);
        if (!$info) return false;
        $stat = apiStatFile($info['filename']);
        if (!$stat['exists']) return false;
        return isset(loadFile($info['filename'])[$code]);
    }

    foreach (getAllDataFiles() as $filename) {
        if (isset(loadFile($filename)[$code])) return true;
    }
    return false;
}

function saveVideo(string $code, string $url, string $title = 'Untitled'): array
{
    if (isNewFormatCode($code)) {
        $info = decodeNewCode($code);
        if (!$info) throw new InvalidArgumentException('Format kode tidak valid: ' . $code);
        $filename = $info['filename'];
    } elseif (isLegacy13Code($code)) {
        $info     = decodeLegacy13Code($code);
        $filename = $info ? $info['filename'] : getActiveFile()['filename'];
    } else {
        $filename = getActiveFile('s1')['filename'];
    }

    // Baca data existing, tambah entry baru, tulis balik
    $videos        = loadFile($filename);
    $entry         = [
        'code'       => $code,
        'url'        => $url,
        'title'      => $title,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    $videos[$code] = $entry;

    safeWriteFile($filename, $videos); // exception dilempar langsung oleh apiPutFile jika gagal

    return $entry;
}

function getVideoByCode(string $code): ?array
{
    if (isNewFormatCode($code)) {
        $info = decodeNewCode($code);
        if (!$info) return null;
        $stat = apiStatFile($info['filename']);
        if (!$stat['exists']) return null;
        return loadFile($info['filename'])[$code] ?? null;
    }

    if (isLegacy13Code($code)) {
        $info = decodeLegacy13Code($code);
        if (!$info) return null;
        $stat = apiStatFile($info['filename']);
        if (!$stat['exists']) return null;
        return loadFile($info['filename'])[$code] ?? null;
    }

    foreach (getAllDataFiles() as $filename) {
        $data = loadFile($filename);
        if (isset($data[$code])) return $data[$code];
    }
    return null;
}

function getVideoByUrl(string $url): ?array
{
    foreach (getAllDataFiles() as $filename) {
        $data = loadFile($filename);
        foreach ($data as $entry) {
            if (($entry['url'] ?? '') === $url) return $entry;
        }
    }
    return null;
}

// ════════════════════════════════════════════════════
// ADJACENT VIDEOS (prev / next)
// ════════════════════════════════════════════════════

function getAdjacentVideos(string $code): array
{
    $all = [];

    if (isNewFormatCode($code)) {
        $server = substr($code, 0, 2);
        $files  = getServerDataFiles($server);
    } else {
        $files = getAllLegacyDataFiles();
    }

    foreach ($files as $filename) {
        $data = loadFile($filename);
        foreach ($data as $entry) {
            if (!isset($entry['code'], $entry['created_at'])) continue;
            $all[] = [
                'code'       => $entry['code'],
                'title'      => $entry['title'] ?? 'Untitled',
                'created_at' => $entry['created_at'],
            ];
        }
    }

    if (empty($all)) return ['prev' => null, 'next' => null];

    usort($all, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));

    $pos   = null;
    $total = count($all);
    for ($i = 0; $i < $total; $i++) {
        if ($all[$i]['code'] === $code) { $pos = $i; break; }
    }

    if ($pos === null) return ['prev' => null, 'next' => null];

    $prev = $pos > 0          ? ['code' => $all[$pos-1]['code'], 'title' => $all[$pos-1]['title']] : null;
    $next = $pos < $total - 1 ? ['code' => $all[$pos+1]['code'], 'title' => $all[$pos+1]['title']] : null;

    return ['prev' => $prev, 'next' => $next];
}

// ════════════════════════════════════════════════════
// ADMIN / DEBUG
// ════════════════════════════════════════════════════

function getStorageInfo(): array
{
    $newFiles    = getAllNewDataFiles();
    $legacyFiles = getAllLegacyDataFiles();
    $allFiles    = array_merge($newFiles, $legacyFiles);

    $totalEntry = 0;
    $detail     = [];

    foreach ($allFiles as $filename) {
        $data  = loadFile($filename);
        $count = count($data);
        $totalEntry += $count;

        $newCodes    = 0;
        $legacy13    = 0;
        $legacy8     = 0;
        $serverCount = ['s1' => 0, 's2' => 0, 's3' => 0];

        foreach (array_keys($data) as $c) {
            if (isNewFormatCode($c)) {
                $newCodes++;
                $srv = substr($c, 0, 2);
                if (isset($serverCount[$srv])) $serverCount[$srv]++;
            } elseif (isLegacy13Code($c)) {
                $legacy13++;
            } else {
                $legacy8++;
            }
        }

        $stat = apiStatFile($filename);

        $detail[] = [
            'file'      => $filename,
            'entries'   => $count,
            'new_codes' => $newCodes,
            'servers'   => $serverCount,
            'legacy_13' => $legacy13,
            'legacy_8'  => $legacy8,
            'size'      => $stat['exists'] ? round($stat['size'] / 1024, 1) . ' KB' : '?',
        ];
    }

    return [
        'data_api'      => DATA_API_BASE,
        'total_files'   => count($allFiles),
        'new_files'     => count($newFiles),
        'legacy_files'  => count($legacyFiles),
        'total_entries' => $totalEntry,
        'files'         => $detail,
    ];
}
