<?php
declare(strict_types=1);

if (file_exists(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';
require_once 'includes/storage.php';
require_once 'includes/helpers.php';

// ── Konstanta ──────────────────────────────────────────────────
const BULK_LIMIT  = 20;
const TITLE_MAX   = 120;
const URL_TIMEOUT = 8;

// ── Helper: sanitasi mode ──────────────────────────────────────
function resolveMode(): string
{
    $mode = $_POST['mode'] ?? 'single';
    return in_array($mode, ['single', 'bulk'], true) ? $mode : 'single';
}

// ── Validasi URL video ─────────────────────────────────────────
function validateVideoUrl(string $url): bool
{
    static $videoExts = ['mp4','m4v','webm','ogg','ogv','mov','avi','mkv','mpeg','ts','m3u8'];
    static $videoMimes = [
    'video/mp4','video/webm','video/ogg','video/quicktime',
    'video/x-msvideo','video/x-matroska','video/mpeg','video/mp2t',
    'application/octet-stream',
    'application/vnd.apple.mpegurl',  // ← tambah ini
    'application/x-mpegurl',          // ← dan ini
    'audio/mpegurl',                  // ← dan ini
];

    // Cek ekstensi lebih dulu (cepat, tanpa HTTP request)
    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
    if (in_array($ext, $videoExts, true)) return true;

    // Fallback: HEAD request untuk cek Content-Type
    $ctx = stream_context_create([
        'http' => [
            'method'          => 'HEAD',
            'timeout'         => URL_TIMEOUT,
            'follow_location' => 1,
            'max_redirects'   => 5,
            'user_agent'      => 'Mozilla/5.0 (compatible; VidShare/1.0)',
            'ignore_errors'   => true,
        ],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $headers = @get_headers($url, true, $ctx);
    if ($headers === false) return false;

    $ct = $headers['Content-Type'] ?? $headers['content-type'] ?? '';
    if (is_array($ct)) $ct = end($ct);
    $ct = strtolower((string) $ct);

    foreach ($videoMimes as $mime) {
        if (str_contains($ct, $mime)) return true;
    }

    return false;
}

// ── State ──────────────────────────────────────────────────────
$message       = '';
$generated_url = '';
$error         = '';
$bulk_results  = [];
$active_mode   = resolveMode();

// ── Single mode ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $active_mode === 'single') {
    $mp4_url = trim($_POST['mp4_url'] ?? '');
    $title   = mb_substr(trim($_POST['title'] ?? 'Untitled Video'), 0, TITLE_MAX);

    if ($mp4_url === '') {
        $error = 'URL video tidak boleh kosong.';
    } elseif (!filter_var($mp4_url, FILTER_VALIDATE_URL)) {
        $error = 'Format URL tidak valid.';
    } elseif (!validateVideoUrl($mp4_url)) {
        $error = 'URL harus mengarah ke file video (mp4, webm, mov, dll).';
    } else {
        $existing = getVideoByUrl($mp4_url);
        if ($existing) {
            $code = $existing['code'];
        } else {
            $code = generateUniqueCode();
            saveVideo($code, $mp4_url, $title);
        }
        $generated_url = getBaseUrl() . '/' . $code;
        $message = 'Player berhasil dibuat!';
    }
}

// ── Bulk mode ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $active_mode === 'bulk') {
    $raw_urls = trim($_POST['bulk_urls'] ?? '');
    $lines    = array_filter(array_map('trim', explode("\n", $raw_urls)));

    if (empty($lines)) {
        $error = 'Masukkan minimal satu URL.';
    } else {
        $lines = array_slice($lines, 0, BULK_LIMIT);

        foreach ($lines as $line) {
            if (str_contains($line, '|')) {
                [$title_raw, $url_raw] = explode('|', $line, 2);
                $entry_title = mb_substr(trim($title_raw) ?: 'Untitled Video', 0, TITLE_MAX);
                $entry_url   = trim($url_raw);
            } else {
                $entry_title = 'Untitled Video';
                $entry_url   = $line;
            }

            if (!filter_var($entry_url, FILTER_VALIDATE_URL)) {
                $bulk_results[] = ['url' => $entry_url, 'status' => 'error', 'msg' => 'Format URL tidak valid'];
                continue;
            }
            if (!validateVideoUrl($entry_url)) {
                $bulk_results[] = ['url' => $entry_url, 'status' => 'error', 'msg' => 'Bukan file video yang valid'];
                continue;
            }

            $existing = getVideoByUrl($entry_url);
            if ($existing) {
                $code = $existing['code'];
            } else {
                $code = generateUniqueCode();
                saveVideo($code, $entry_url, $entry_title);
            }

            $bulk_results[] = [
                'url'        => $entry_url,
                'title'      => $entry_title,
                'player_url' => getBaseUrl() . '/' . $code,
                'status'     => 'ok',
            ];
        }
    }
}

// ── Helper render ──────────────────────────────────────────────
$ok_count  = count(array_filter($bulk_results, fn($r) => $r['status'] === 'ok'));
$err_count = count($bulk_results) - $ok_count;

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VidShare — MP4 Player Generator</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#080a0f;--surface:#0e1117;--border:#1e2535;--accent:#e8ff47;--accent2:#47c8ff;--text:#eef1f8;--muted:#5a6177;--error:#ff5757;--success:#47ffb2}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2rem 1rem;position:relative;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(232,255,71,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(232,255,71,.04) 1px,transparent 1px);background-size:48px 48px;pointer-events:none;z-index:0}
body::after{content:'';position:fixed;width:600px;height:600px;background:radial-gradient(circle,rgba(71,200,255,.07) 0%,transparent 70%);top:-200px;right:-200px;pointer-events:none;z-index:0}
.blob2{position:fixed;width:500px;height:500px;background:radial-gradient(circle,rgba(232,255,71,.05) 0%,transparent 70%);bottom:-150px;left:-150px;pointer-events:none;z-index:0}
.container{position:relative;z-index:1;width:100%;max-width:620px}
.header{text-align:center;margin-bottom:3rem}
.logo{display:inline-flex;align-items:center;gap:.6rem;margin-bottom:1rem}
.logo-icon{width:44px;height:44px;background:var(--accent);border-radius:10px;display:flex;align-items:center;justify-content:center}
.logo-text{font-family:'Syne',sans-serif;font-weight:800;font-size:1.6rem;letter-spacing:-.02em}
.logo-text span{color:var(--accent)}
.tagline{color:var(--muted);font-size:.8rem;letter-spacing:.1em;text-transform:uppercase}
.card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:2.5rem;position:relative;overflow:hidden}
.card::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,var(--accent2),transparent);opacity:.4}
.mode-tabs{display:flex;gap:.4rem;margin-bottom:1.8rem;background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:.3rem}
.mode-tab{flex:1;padding:.55rem 1rem;border:none;border-radius:7px;cursor:pointer;font-family:'DM Mono',monospace;font-size:.78rem;font-weight:500;letter-spacing:.05em;text-transform:uppercase;color:var(--muted);background:transparent;transition:background .2s,color .2s}
.mode-tab.active{background:var(--border);color:var(--text)}
.form-group{margin-bottom:1.5rem}
label{display:block;font-size:.75rem;font-weight:500;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:.6rem}
input[type="text"],input[type="url"],textarea{width:100%;background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:.85rem 1.1rem;color:var(--text);font-family:'DM Mono',monospace;font-size:.9rem;transition:border-color .2s,box-shadow .2s;outline:none;resize:vertical}
input:focus,textarea:focus{border-color:var(--accent2);box-shadow:0 0 0 3px rgba(71,200,255,.1)}
input::placeholder,textarea::placeholder{color:var(--muted)}
textarea{min-height:150px;line-height:1.6}
.hint{font-size:.72rem;color:var(--muted);margin-top:.4rem;line-height:1.5}
.btn{width:100%;padding:1rem;background:var(--accent);border:none;border-radius:10px;color:#080a0f;font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;cursor:pointer;letter-spacing:.02em;transition:transform .15s,box-shadow .15s,opacity .15s;display:flex;align-items:center;justify-content:center;gap:.5rem}
.btn:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 8px 24px rgba(232,255,71,.25)}
.btn:disabled{opacity:.6;cursor:not-allowed}
.btn-spinner{display:none;width:16px;height:16px;border:2px solid rgba(8,10,15,.3);border-top-color:#080a0f;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.result{margin-top:1.5rem;background:rgba(71,255,178,.05);border:1px solid rgba(71,255,178,.2);border-radius:10px;padding:1.25rem;animation:fadeUp .3s ease}
.result-label{font-size:.7rem;text-transform:uppercase;letter-spacing:.1em;color:var(--success);margin-bottom:.6rem}
.result-url{display:flex;align-items:center;gap:.5rem}
.result-url a{flex:1;color:var(--text);text-decoration:none;font-size:.85rem;word-break:break-all;transition:color .15s}
.result-url a:hover{color:var(--success)}
.copy-btn{flex-shrink:0;background:rgba(71,255,178,.1);border:1px solid rgba(71,255,178,.2);border-radius:6px;padding:.4rem .7rem;color:var(--success);font-family:'DM Mono',monospace;font-size:.75rem;cursor:pointer;transition:background .15s}
.copy-btn:hover{background:rgba(71,255,178,.2)}
.alert{margin-top:1rem;padding:.9rem 1.1rem;border-radius:10px;font-size:.85rem;animation:fadeUp .3s ease}
.alert-error{background:rgba(255,87,87,.08);border:1px solid rgba(255,87,87,.25);color:var(--error)}
.bulk-results{margin-top:1.5rem;animation:fadeUp .3s ease}
.bulk-summary{font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.8rem}
.bulk-summary .ok-count{color:var(--success)}
.bulk-summary .err-count{color:var(--error)}
.bulk-item{background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:.8rem 1rem;margin-bottom:.5rem;display:flex;align-items:center;gap:.75rem}
.bulk-badge{font-size:.7rem;flex-shrink:0}
.bulk-item.ok .bulk-badge{color:var(--success)}
.bulk-item.error .bulk-badge{color:var(--error)}
.bulk-item-body{flex:1;min-width:0}
.bulk-item-title{font-size:.8rem;color:var(--muted);margin-bottom:.2rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bulk-item-url{display:flex;align-items:center;gap:.5rem}
.bulk-item-url a{color:var(--text);text-decoration:none;font-size:.82rem;word-break:break-all;transition:color .15s}
.bulk-item-url a:hover{color:var(--success)}
.bulk-item-err{color:var(--error);font-size:.82rem}
.bulk-copy-all{width:100%;margin-top:.8rem;padding:.65rem;background:rgba(71,255,178,.08);border:1px solid rgba(71,255,178,.2);border-radius:8px;color:var(--success);font-family:'DM Mono',monospace;font-size:.8rem;cursor:pointer;transition:background .15s}
.bulk-copy-all:hover{background:rgba(71,255,178,.15)}
.counter{float:right;font-size:.72rem;color:var(--muted);margin-top:.4rem}
.features{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-top:2rem}
.feature{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:1.2rem;text-align:center}
.feature-icon{font-size:1.5rem;margin-bottom:.5rem}
.feature-text{font-size:.72rem;color:var(--muted);line-height:1.4}
.footer{margin-top:2.5rem;text-align:center;color:var(--muted);font-size:.75rem}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:480px){.card{padding:1.5rem}.features{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="blob2"></div>
<div class="container">

  <div class="header">
    <div class="logo">
      <div class="logo-icon">
        <svg viewBox="0 0 24 24" fill="none"><path d="M8 5l11 7-11 7V5z" fill="#080a0f"/></svg>
      </div>
      <span class="logo-text">Vid<span>Share</span></span>
    </div>
    <p class="tagline">Generate Shareable Video Player</p>
  </div>

  <div class="card">

    <div class="mode-tabs" role="tablist">
      <button type="button" role="tab" aria-selected="<?= $active_mode === 'single' ? 'true' : 'false' ?>"
              class="mode-tab <?= $active_mode === 'single' ? 'active' : '' ?>"
              onclick="switchMode('single')">Single URL</button>
      <button type="button" role="tab" aria-selected="<?= $active_mode === 'bulk' ? 'true' : 'false' ?>"
              class="mode-tab <?= $active_mode === 'bulk' ? 'active' : '' ?>"
              onclick="switchMode('bulk')">Bulk URL</button>
    </div>

    <!-- ── Single form ────────────────────────────────── -->
    <div id="panel-single" role="tabpanel" style="display:<?= $active_mode === 'bulk' ? 'none' : 'block' ?>">
      <form method="POST" id="singleForm" onsubmit="handleSingleSubmit(event)">
        <input type="hidden" name="mode" value="single">
        <div class="form-group">
          <label for="title">Judul Video (opsional)</label>
          <input type="text" name="title" id="title" maxlength="<?= TITLE_MAX ?>"
                 placeholder="Masukkan judul video..."
                 value="<?= htmlspecialchars($_POST['title'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="mp4_url">URL MP4 Video *</label>
          <input type="url" name="mp4_url" id="mp4_url" required
                 placeholder="https://example.com/video.mp4"
                 value="<?= htmlspecialchars($_POST['mp4_url'] ?? '') ?>">
        </div>

        <button type="submit" class="btn" id="submitBtn">
          <div class="btn-spinner" id="btnSpinner"></div>
          <svg id="btnIcon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <path d="M5 12h14M12 5l7 7-7 7"/>
          </svg>
          <span id="btnText">Generate Player URL</span>
        </button>

        <?php if ($error && $active_mode === 'single'): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($generated_url): ?>
        <div class="result">
          <div class="result-label">✓ <?= htmlspecialchars($message) ?></div>
          <div class="result-url">
            <a href="<?= htmlspecialchars($generated_url) ?>" target="_blank" id="generatedUrl">
              <?= htmlspecialchars($generated_url) ?>
            </a>
            <button type="button" class="copy-btn" onclick="copyText('<?= htmlspecialchars($generated_url) ?>', this)">Copy</button>
          </div>
        </div>
        <?php endif; ?>
      </form>
    </div>

    <!-- ── Bulk form ──────────────────────────────────── -->
    <div id="panel-bulk" role="tabpanel" style="display:<?= $active_mode === 'bulk' ? 'block' : 'none' ?>">
      <form method="POST" id="bulkForm" onsubmit="handleBulkSubmit(event)">
        <input type="hidden" name="mode" value="bulk">
        <div class="form-group">
          <label for="bulk_urls">URL Video (satu per baris, maks. <?= BULK_LIMIT ?>)</label>
          <textarea name="bulk_urls" id="bulk_urls"
                    placeholder="https://example.com/video1.mp4&#10;Judul Saya | https://example.com/video2.mp4"
                    oninput="updateCounter()"><?= htmlspecialchars($_POST['bulk_urls'] ?? '') ?></textarea>
          <div class="hint">Format: <code>URL</code> atau <code>Judul | URL</code> per baris.</div>
          <div class="counter"><span id="urlCount">0</span> / <?= BULK_LIMIT ?> URL</div>
        </div>

        <button type="submit" class="btn" id="bulkSubmitBtn">
          <div class="btn-spinner" id="bulkBtnSpinner"></div>
          <svg id="bulkBtnIcon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <path d="M4 6h16M4 12h16M4 18h10"/>
          </svg>
          <span id="bulkBtnText">Generate Semua Player URL</span>
        </button>

        <?php if ($error && $active_mode === 'bulk'): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($bulk_results)): ?>
        <div class="bulk-results">
          <div class="bulk-summary">
            Hasil:
            <span class="ok-count"><?= $ok_count ?> berhasil</span>
            <?php if ($err_count > 0): ?>
              &nbsp;·&nbsp;<span class="err-count"><?= $err_count ?> gagal</span>
            <?php endif; ?>
          </div>

          <?php foreach ($bulk_results as $r): ?>
          <div class="bulk-item <?= $r['status'] ?>">
            <span class="bulk-badge"><?= $r['status'] === 'ok' ? '✓' : '✗' ?></span>
            <div class="bulk-item-body">
              <?php if ($r['status'] === 'ok'): ?>
                <div class="bulk-item-title"><?= htmlspecialchars($r['title']) ?></div>
                <div class="bulk-item-url">
                  <a href="<?= htmlspecialchars($r['player_url']) ?>" target="_blank">
                    <?= htmlspecialchars($r['player_url']) ?>
                  </a>
                  <button type="button" class="copy-btn"
                          onclick="copyText('<?= htmlspecialchars($r['player_url']) ?>', this)">Copy</button>
                </div>
              <?php else: ?>
                <div class="bulk-item-title" title="<?= htmlspecialchars($r['url']) ?>">
                  <?= htmlspecialchars(mb_strimwidth($r['url'], 0, 55, '…')) ?>
                </div>
                <div class="bulk-item-err"><?= htmlspecialchars($r['msg']) ?></div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>

          <?php if ($ok_count > 1): ?>
          <button type="button" class="bulk-copy-all" id="copyAllBtn" onclick="copyAll()">
            ↓ Copy semua URL yang berhasil (<?= $ok_count ?>)
          </button>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </form>
    </div>

  </div><!-- /.card -->

  <div class="features">
    <div class="feature"><div class="feature-icon">⚡</div><div class="feature-text">Auto play saat halaman dibuka</div></div>
    <div class="feature"><div class="feature-icon">🔗</div><div class="feature-text">URL pendek &amp; mudah dishare</div></div>
    <div class="feature"><div class="feature-icon">📱</div><div class="feature-text">Responsive di semua device</div></div>
  </div>
  <div class="footer">play.vidshare.my.id — MP4 Video Player Generator</div>
</div>

<script>
// ── Mode switch ───────────────────────────────────────────────
function switchMode(mode) {
  const isSingle = mode === 'single';
  document.getElementById('panel-single').style.display = isSingle ? 'block' : 'none';
  document.getElementById('panel-bulk').style.display   = isSingle ? 'none'  : 'block';

  document.querySelectorAll('.mode-tab').forEach(tab => {
    const isActive = tab.textContent.trim().toLowerCase().startsWith(mode);
    tab.classList.toggle('active', isActive);
    tab.setAttribute('aria-selected', String(isActive));
  });

  document.getElementById('singleForm').querySelector('[name="mode"]').value = mode;
  document.getElementById('bulkForm').querySelector('[name="mode"]').value   = mode;
}

// ── Bulk: counter baris ───────────────────────────────────────
function updateCounter() {
  const count = document.getElementById('bulk_urls').value
    .split('\n').filter(l => l.trim().length > 0).length;
  document.getElementById('urlCount').textContent = Math.min(count, <?= BULK_LIMIT ?>);
}
document.addEventListener('DOMContentLoaded', updateCounter);

// ── Single submit ─────────────────────────────────────────────
function handleSingleSubmit(e) {
  setLoading('singleForm', true, 'Memproses...');
}

// ── Bulk submit ───────────────────────────────────────────────
function handleBulkSubmit(e) {
  const lines = document.getElementById('bulk_urls').value
    .split('\n').filter(l => l.trim().length > 0);
  if (lines.length === 0) {
    e.preventDefault();
    alert('Masukkan minimal satu URL.');
    return;
  }
  const n = Math.min(lines.length, <?= BULK_LIMIT ?>);
  setLoading('bulkForm', true, `Memproses ${n} URL…`);
}

// ── Generic loading state (FIXED) ────────────────────────────
function setLoading(formId, on, label) {
  const isBulk  = formId === 'bulkForm';
  const btn     = document.getElementById(isBulk ? 'bulkSubmitBtn' : 'submitBtn');
  const spinner = document.getElementById(isBulk ? 'bulkBtnSpinner' : 'btnSpinner');
  const icon    = document.getElementById(isBulk ? 'bulkBtnIcon'   : 'btnIcon');
  const text    = document.getElementById(isBulk ? 'bulkBtnText'   : 'btnText');

  btn.disabled          = on;
  spinner.style.display = on ? 'block' : 'none';
  icon.style.display    = on ? 'none'  : 'block';
  if (label) text.textContent = label;
}

// ── Copy helper ───────────────────────────────────────────────
function copyText(text, btn) {
  const original = btn.textContent;
  const done = () => {
    btn.textContent = 'Copied!';
    setTimeout(() => btn.textContent = original, 2000);
  };

  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(done).catch(() => fallbackCopy(text, done));
  } else {
    fallbackCopy(text, done);
  }
}

function fallbackCopy(text, cb) {
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.style.cssText = 'position:fixed;opacity:0;pointer-events:none';
  document.body.appendChild(ta);
  ta.focus(); ta.select();
  try { document.execCommand('copy'); cb(); } catch {}
  document.body.removeChild(ta);
}

function copyAll() {
  const urls = [...document.querySelectorAll('.bulk-item.ok .bulk-item-url a')]
    .map(a => a.textContent.trim()).join('\n');
  const btn = document.getElementById('copyAllBtn');
  copyText(urls, btn);
}
</script>
</body>
</html>