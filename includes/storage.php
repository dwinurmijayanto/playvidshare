<?php
/**
 * Storage Helper — support multi-server + backward compat kode lama
 * File: includes/storage.php
 *
 * ════════════════════════════════════════════════════════════════
 * FORMAT KODE
 * ════════════════════════════════════════════════════════════════
 *
 * BARU (15 karakter):
 *   [2 char server][2 char bulan][1 char file-ke][10 char random]
 *
 *   Server prefix:
 *     s1 = Server 1
 *     s2 = Server 2
 *     s3 = Server 3
 *
 *   Bulan encoding (sama seperti sebelumnya):
 *     1a=Jan 2f=Feb 3m=Mar 4a=Apr 5m=Mei 6j=Jun
 *     7j=Jul 8a=Agt 9s=Sep 0o=Okt xn=Nov xd=Des
 *
 *   File-ke (1 char base-36): 1-9, a-z
 *
 *   Contoh: s11a1abcdefghij
 *            ^^ ^^ ^ ^^^^^^^^^^
 *            s1 Jan f1  random
 *
 * LAMA-13 (13 karakter):
 *   [2 char bulan][1 char file-ke][10 char random]
 *   → tidak mengandung info server, fallback scan file lama
 *
 * LAMA-8 (8 karakter):
 *   alfanumerik acak murni
 *   → fallback scan semua file JSON
 *
 * ════════════════════════════════════════════════════════════════
 * STRUKTUR FILE
 * ════════════════════════════════════════════════════════════════
 *
 *   data/
 *     s1_videos_2025_01_1.json   ← server 1, Januari 2025, file ke-1
 *     s1_videos_2025_01_2.json
 *     s2_videos_2025_01_1.json   ← server 2, Januari 2025, file ke-1
 *     s3_videos_2025_01_1.json   ← server 3, Januari 2025, file ke-1
 *     videos_2025_01_1.json      ← file lama (kode 13 char, tanpa prefix server)
 *
 * ════════════════════════════════════════════════════════════════
 * LOOKUP
 * ════════════════════════════════════════════════════════════════
 *
 *   Kode baru (15 char) → O(1): decode server+bulan+fileNum → buka 1 file
 *   Kode lama-13        → O(1) per file lama (decode bulan+fileNum, scan file lama)
 *   Kode lama-8         → scan semua file (backward compat)
 *
 * ════════════════════════════════════════════════════════════════
 * NEXT / PREV
 * ════════════════════════════════════════════════════════════════
 *
 *   Kode baru → hanya baca file dengan prefix server yang sama
 *   Kode lama → baca semua file lama (tanpa prefix server)
 */

define('DATA_DIR',        __DIR__ . '/../data');
define('FILE_SIZE_LIMIT', 512 * 1024); // 512 KB per file
define('CODE_RANDOM_LEN', 10);

// ════════════════════════════════════════════════════
// SERVER PREFIX
// ════════════════════════════════════════════════════

const VALID_SERVERS = ['s1', 's2', 's3'];

/**
 * Validasi server prefix.
 */
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

/**
 * Kode baru: 15 char, dimulai dengan server prefix valid.
 * [s1|s2|s3] + [2 bulan] + [1 file-ke] + [10 random]
 */
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

/**
 * Kode lama-13: 13 char, [2 bulan][1 file-ke][10 random]
 * TIDAK dimulai dengan server prefix.
 */
function isLegacy13Code(string $code): bool
{
    if (strlen($code) !== 13) return false;
    $month  = substr($code, 0, 2);
    $fileCh = $code[2];
    if (decodeMonth($month) === null) return false;
    return decodeFileNum($fileCh) >= 1;
}

/**
 * Kode lama-8: 8 char alfanumerik.
 */
function isLegacy8Code(string $code): bool
{
    return strlen($code) === 8 && ctype_alnum($code);
}

// ════════════════════════════════════════════════════
// FILE PATH
// ════════════════════════════════════════════════════

/**
 * Path file untuk kode BARU (dengan server prefix).
 *   data/s1_videos_2025_01_1.json
 */
function buildNewFilePath(string $server, int $year, int $month, int $fileNum): string
{
    return DATA_DIR . '/' . $server . '_videos_'
        . $year . '_'
        . str_pad($month, 2, '0', STR_PAD_LEFT) . '_'
        . $fileNum . '.json';
}

/**
 * Path file untuk kode LAMA (tanpa server prefix).
 *   data/videos_2025_01_1.json
 */
function buildLegacyFilePath(int $year, int $month, int $fileNum): string
{
    return DATA_DIR . '/videos_'
        . $year . '_'
        . str_pad($month, 2, '0', STR_PAD_LEFT) . '_'
        . $fileNum . '.json';
}

// ════════════════════════════════════════════════════
// DECODE KODE → INFO FILE
// ════════════════════════════════════════════════════

/**
 * Decode kode BARU (15 char) → ['server','year','month','fileNum','path']
 * Cari mundur maks 3 tahun jika file belum ada.
 */
function decodeNewCode(string $code): ?array
{
    $server  = substr($code, 0, 2);
    $month   = decodeMonth(substr($code, 2, 2));
    $fileNum = decodeFileNum($code[4]);
    if ($month === null || $fileNum < 1 || !isValidServer($server)) return null;

    $currentYear = (int) date('Y');
    for ($i = 0; $i <= 3; $i++) {
        $year = $currentYear - $i;
        $path = buildNewFilePath($server, $year, $month, $fileNum);
        if (file_exists($path)) {
            return compact('server', 'year', 'month', 'fileNum', 'path');
        }
    }

    // File belum ada → default tahun sekarang
    $year = $currentYear;
    return [
        'server'  => $server,
        'year'    => $year,
        'month'   => $month,
        'fileNum' => $fileNum,
        'path'    => buildNewFilePath($server, $year, $month, $fileNum),
    ];
}

/**
 * Decode kode LAMA-13 (13 char) → ['year','month','fileNum','path']
 * Scan file lama (tanpa prefix server).
 */
function decodeLegacy13Code(string $code): ?array
{
    $month   = decodeMonth(substr($code, 0, 2));
    $fileNum = decodeFileNum($code[2]);
    if ($month === null || $fileNum < 1) return null;

    $currentYear = (int) date('Y');
    for ($i = 0; $i <= 3; $i++) {
        $year = $currentYear - $i;
        $path = buildLegacyFilePath($year, $month, $fileNum);
        if (file_exists($path)) {
            return compact('year', 'month', 'fileNum', 'path');
        }
    }

    $year = $currentYear;
    return [
        'year'    => $year,
        'month'   => $month,
        'fileNum' => $fileNum,
        'path'    => buildLegacyFilePath($year, $month, $fileNum),
    ];
}

// ════════════════════════════════════════════════════
// FILE AKTIF (untuk write baru)
// ════════════════════════════════════════════════════

/**
 * File aktif bulan ini untuk server tertentu.
 * Jika sudah > FILE_SIZE_LIMIT → naikkan nomor file.
 *
 * @param  string $server  'server1' | 'server2' | 'server3' (nilai dari caller)
 *                         atau 's1' | 's2' | 's3' (prefix storage)
 */
function getActiveFile(string $server = 's1'): array
{
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);

    // Normalisasi: terima 'server1'/'server2'/'server3' atau 's1'/'s2'/'s3'
    $srv = normalizeServerPrefix($server);

    $year  = (int) date('Y');
    $month = (int) date('n');

    for ($num = 1; $num <= 35; $num++) {
        $path = buildNewFilePath($srv, $year, $month, $num);
        if (!file_exists($path) || filesize($path) < FILE_SIZE_LIMIT) {
            return [
                'path'    => $path,
                'year'    => $year,
                'month'   => $month,
                'fileNum' => $num,
                'server'  => $srv,
            ];
        }
    }

    // Fallback: tetap pakai file ke-35
    $path = buildNewFilePath($srv, $year, $month, 35);
    return [
        'path'    => $path,
        'year'    => $year,
        'month'   => $month,
        'fileNum' => 35,
        'server'  => $srv,
    ];
}

/**
 * Normalisasi input server → prefix 2 char (s1/s2/s3).
 * Menerima: 's1','s2','s3','server1','server2','server3','1','2','3'
 */
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
// JSON READ / WRITE (aman)
// ════════════════════════════════════════════════════

function loadFile(string $path): array
{
    if (!file_exists($path)) return [];
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function safeWriteFile(string $path, array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;

    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $tmp = $path . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }

    $verify = json_decode((string) file_get_contents($tmp), true);
    if (!is_array($verify)) { @unlink($tmp); return false; }
    if (!rename($tmp, $path)) { @unlink($tmp); return false; }
    return true;
}

/**
 * Semua file data baru (dengan prefix server), diurutkan terbaru duluan.
 */
function getAllNewDataFiles(): array
{
    if (!is_dir(DATA_DIR)) return [];
    $files = [];
    foreach (VALID_SERVERS as $srv) {
        $found = glob(DATA_DIR . '/' . $srv . '_videos_*.json') ?: [];
        $files = array_merge($files, $found);
    }
    rsort($files);
    return $files;
}

/**
 * File data lama (tanpa prefix server) — untuk backward compat.
 */
function getAllLegacyDataFiles(): array
{
    if (!is_dir(DATA_DIR)) return [];
    // Cocokkan hanya file yang TIDAK diawali prefix server
    $all   = glob(DATA_DIR . '/videos_*.json') ?: [];
    rsort($all);
    return $all;
}

/**
 * Semua file data (baru + lama), diurutkan terbaru duluan.
 */
function getAllDataFiles(): array
{
    return array_merge(getAllNewDataFiles(), getAllLegacyDataFiles());
}

/**
 * File data untuk server tertentu saja, diurutkan terbaru duluan.
 */
function getServerDataFiles(string $server): array
{
    if (!is_dir(DATA_DIR)) return [];
    $srv   = normalizeServerPrefix($server);
    $files = glob(DATA_DIR . '/' . $srv . '_videos_*.json') ?: [];
    rsort($files);
    return $files;
}

// ════════════════════════════════════════════════════
// PUBLIC API
// ════════════════════════════════════════════════════

/**
 * Generate kode unik 15 karakter (format baru, dengan server prefix).
 *
 * @param  string $server  's1' | 's2' | 's3' | 'server1' | 'server2' | 'server3' | '1' | '2' | '3'
 */
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
        $code = $srv . $monthPfx . $filePfx . $random; // 2+2+1+10 = 15 char
        if (!codeExists($code)) return $code;
    }

    throw new RuntimeException('Tidak bisa generate kode unik setelah 100 percobaan.');
}

/**
 * Cek apakah kode sudah ada.
 */
function codeExists(string $code): bool
{
    if (isNewFormatCode($code)) {
        $info = decodeNewCode($code);
        if (!$info || !file_exists($info['path'])) return false;
        return isset(loadFile($info['path'])[$code]);
    }

    if (isLegacy13Code($code)) {
        $info = decodeLegacy13Code($code);
        if (!$info || !file_exists($info['path'])) return false;
        return isset(loadFile($info['path'])[$code]);
    }

    // Kode lama-8: scan semua file
    foreach (getAllDataFiles() as $file) {
        if (isset(loadFile($file)[$code])) return true;
    }
    return false;
}

/**
 * Simpan video baru.
 *
 * @param  string $code    Kode unik (hasil generateUniqueCode)
 * @param  string $url     URL video
 * @param  string $title   Judul video
 * @return array           Entry yang disimpan
 */
function saveVideo(string $code, string $url, string $title = 'Untitled'): array
{
    // Tentukan path file tujuan
    if (isNewFormatCode($code)) {
        $info = decodeNewCode($code);
        if (!$info) throw new InvalidArgumentException('Format kode tidak valid: ' . $code);
        $path = $info['path'];
    } elseif (isLegacy13Code($code)) {
        $info = decodeLegacy13Code($code);
        $path = $info ? $info['path'] : getActiveFile()['path'];
    } else {
        // Kode lama-8: simpan ke file aktif s1 sebagai fallback
        $path = getActiveFile('s1')['path'];
    }

    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);

    $fp = fopen($path, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if ($fp) fclose($fp);
        throw new RuntimeException('Tidak bisa mengunci file storage: ' . $path);
    }

    $raw    = stream_get_contents($fp);
    $videos = json_decode($raw, true);
    if (!is_array($videos)) $videos = [];

    $entry = [
        'code'       => $code,
        'url'        => $url,
        'title'      => $title,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    $videos[$code] = $entry;

    $json = json_encode($videos, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json !== false) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $json);
    }

    flock($fp, LOCK_UN);
    fclose($fp);

    return $entry;
}

/**
 * Ambil video by code.
 *
 * Kode baru (15 char) → O(1): decode server+bulan+fileNum → buka 1 file
 * Kode lama-13        → decode bulan+fileNum → buka file lama
 * Kode lama-8         → scan semua file
 */
function getVideoByCode(string $code): ?array
{
    if (isNewFormatCode($code)) {
        $info = decodeNewCode($code);
        if (!$info || !file_exists($info['path'])) return null;
        return loadFile($info['path'])[$code] ?? null;
    }

    if (isLegacy13Code($code)) {
        $info = decodeLegacy13Code($code);
        if (!$info || !file_exists($info['path'])) return null;
        return loadFile($info['path'])[$code] ?? null;
    }

    // Kode lama-8: scan semua file (baru + lama)
    foreach (getAllDataFiles() as $file) {
        $data = loadFile($file);
        if (isset($data[$code])) return $data[$code];
    }
    return null;
}

/**
 * Cek duplikat URL.
 * Scan semua file (baru + lama).
 */
function getVideoByUrl(string $url): ?array
{
    foreach (getAllDataFiles() as $file) {
        $data = loadFile($file);
        foreach ($data as $entry) {
            if (($entry['url'] ?? '') === $url) return $entry;
        }
    }
    return null;
}

// ════════════════════════════════════════════════════
// ADJACENT VIDEOS (prev / next)
// ════════════════════════════════════════════════════

/**
 * Ambil video sebelum dan sesudah berdasarkan created_at.
 *
 * Strategi:
 *   - Kode baru (15 char): hanya baca file server yang sama
 *   - Kode lama (8/13 char): hanya baca file lama (tanpa prefix server)
 *
 * Ini memastikan next/prev tidak melompat antar server.
 */
function getAdjacentVideos(string $code): array
{
    $all = [];

    if (isNewFormatCode($code)) {
        // Ambil hanya dari server yang sama
        $server = substr($code, 0, 2); // 's1' / 's2' / 's3'
        $files  = getServerDataFiles($server);
    } else {
        // Kode lama: ambil hanya dari file lama (tanpa prefix server)
        $files = getAllLegacyDataFiles();
    }

    foreach ($files as $file) {
        $data = loadFile($file);
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

    // Sort ascending by created_at
    usort($all, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));

    // Cari posisi video saat ini
    $pos   = null;
    $total = count($all);
    for ($i = 0; $i < $total; $i++) {
        if ($all[$i]['code'] === $code) { $pos = $i; break; }
    }

    if ($pos === null) return ['prev' => null, 'next' => null];

    $prev = $pos > 0           ? ['code' => $all[$pos-1]['code'], 'title' => $all[$pos-1]['title']] : null;
    $next = $pos < $total - 1  ? ['code' => $all[$pos+1]['code'], 'title' => $all[$pos+1]['title']] : null;

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
    $totalSize  = 0;
    $detail     = [];

    foreach ($allFiles as $file) {
        $data  = loadFile($file);
        $count = count($data);
        $size  = filesize($file);
        $totalEntry += $count;
        $totalSize  += $size;

        $newCodes    = 0;
        $legacy13    = 0;
        $legacy8     = 0;
        $serverCount = ['s1'=>0,'s2'=>0,'s3'=>0];

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

        $detail[] = [
            'file'       => basename($file),
            'entries'    => $count,
            'new_codes'  => $newCodes,
            'servers'    => $serverCount,
            'legacy_13'  => $legacy13,
            'legacy_8'   => $legacy8,
            'size'       => round($size / 1024, 1) . ' KB',
        ];
    }

    return [
        'total_files'   => count($allFiles),
        'new_files'     => count($newFiles),
        'legacy_files'  => count($legacyFiles),
        'total_entries' => $totalEntry,
        'total_size'    => round($totalSize / 1024, 1) . ' KB',
        'files'         => $detail,
    ];
}