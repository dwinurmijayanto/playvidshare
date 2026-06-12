<?php
require_once __DIR__ . '/includes/storage.php';
require_once __DIR__ . '/includes/helpers.php';

$code = isset($_GET['code']) ? trim($_GET['code']) : '';

// ── Validasi kode: 8 char (lama), 13 char (lama), atau 15 char (baru) ──
if (
    empty($code) ||
    !preg_match('/^[a-zA-Z0-9]{8}$|^[a-zA-Z0-9]{13}$|^[a-zA-Z0-9]{15}$/', $code)
) {
    http_response_code(404); showError('Kode video tidak valid.'); exit;
}

$video = getVideoByCode($code);
if (!$video) { http_response_code(404); showError('Video tidak ditemukan.'); exit; }

$title    = htmlspecialchars($video['title'] ?? 'Video Player');
$base_url = 'https://play.vidshare.my.id';
$share_url = $base_url . '/' . $code;

// ── Info server (hanya untuk kode baru 15 char) ────────────────
$server_label = '';
if (isNewFormatCode($code)) {
    $srv_map = ['s1' => 'Server 1', 's2' => 'Server 2', 's3' => 'Server 3'];
    $server_label = $srv_map[substr($code, 0, 2)] ?? '';
}

// ── Watermark ──────────────────────────────────────────────────
$watermark_text    = 'VidShare · ' . $code;
$watermark_opacity = 0.60;
$watermark_pos     = 'center'; // top-left | top-right | bottom-left | bottom-right | center

// ── Adjacent videos ───────────────────────────────────────────
// getAdjacentVideos() otomatis membatasi scope ke server yang sama
// untuk kode baru, atau file lama untuk kode lama.
$adjacent   = getAdjacentVideos($code);
$prev_video = $adjacent['prev'] ?? null;
$next_video = $adjacent['next'] ?? null;

$prev_json = $prev_video
    ? json_encode(['code' => $prev_video['code'], 'title' => $prev_video['title']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    : 'null';
$next_json = $next_video
    ? json_encode(['code' => $next_video['code'], 'title' => $next_video['title']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    : 'null';

// ── Deteksi HLS ───────────────────────────────────────────────
$video_url = $video['url'] ?? '';
$url_path  = parse_url($video_url, PHP_URL_PATH) ?? '';
$is_hls    = (bool) preg_match('/\.(m3u8|m3u)(\?.*)?$/i', $url_path);

// ── Semua stream lewat stream.php — URL asli tidak dikirim ke JS ──
$stream_url   = $base_url . '/stream.php?c=' . $code;
$download_url = $base_url . '/stream.php?c=' . $code . '&dl=1';

// ── Ads ───────────────────────────────────────────────────────
$ads = [
    [
        'text'  => '💎 VidShare Premium — Bandwidth lebih cepat, kualitas lebih tinggi!',
        'url'   => 'https://play.vidshare.my.id/',
        'label' => 'Upgrade',
    ],
];
$ads_json = json_encode($ads, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// ══════════════════════════════════════════════════════════════
// ERROR PAGE (404)
// ══════════════════════════════════════════════════════════════
function showError(string $msg): void { ?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>404</title>
<style>
  body{background:#050508;color:#eef1f8;font-family:monospace;
       display:flex;align-items:center;justify-content:center;min-height:100vh}
  .b{text-align:center}h1{font-size:4rem;color:#e8ff47}
  p{color:#5a6177;margin-top:.5rem}a{color:#47c8ff}
</style>
</head>
<body><div class="b">
  <h1>404</h1>
  <p><?= htmlspecialchars($msg) ?></p>
  <p><a href="/">← Beranda</a></p>
</div></body></html>
<?php }
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#050508">
<title><?= $title ?> — VidShare</title>
<meta property="og:image" content="<?= $base_url ?>/thumb.php?c=<?= $code ?>">
<meta name="twitter:image"  content="<?= $base_url ?>/thumb.php?c=<?= $code ?>">
<meta property="og:title"       content="<?= $title ?>">
<meta property="og:type"        content="video.other">
<meta property="og:url"         content="<?= htmlspecialchars($share_url) ?>">
<meta property="og:image" content="<?= $base_url ?>/thumb.php?c=<?= $code ?>">
<meta property="og:image:width"  content="640">
<meta property="og:image:height" content="360">
<meta name="twitter:image"       content="<?= $base_url ?>/thumb.php?c=<?= $code ?>">
<meta property="og:description" content="Tonton video ini di VidShare Player">
<meta name="twitter:card"       content="player">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/hls.js/1.5.7/hls.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

:root{
  --topbar:  52px;
  --adbar:   38px;
  --infobar: 58px;
  --bg:      #050508;
  --panel:   #0b0c12;
  --border:  #191b26;
  --accent:  #e8ff47;
  --accent2: #47c8ff;
  --text:    #eef1f8;
  --muted:   #44495e;
  --ad-bg:   #0d0f1a;
  --ad-text: #f0f2ff;
  --ad-accent:#ffcc33;
}

html,body{height:100%;background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;overflow:hidden}

/* ─── TOPBAR ─────────────────────────────────────── */
.topbar{
  position:fixed;top:0;left:0;right:0;height:var(--topbar);
  background:rgba(11,12,18,.93);backdrop-filter:blur(14px);
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;
  padding:0 1.25rem;z-index:100;
}
.logo{display:flex;align-items:center;gap:.5rem;text-decoration:none;color:var(--text)}
.logo-icon{width:30px;height:30px;background:var(--accent);border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.logo-icon svg{width:16px;height:16px}
.logo-name{font-family:'Syne',sans-serif;font-weight:800;font-size:1.05rem;letter-spacing:-.02em}
.logo-name span{color:var(--accent)}
.topbar-actions{display:flex;align-items:center;gap:.5rem}
.share-btn{
  display:flex;align-items:center;gap:.35rem;padding:.38rem .85rem;
  background:transparent;border:1px solid var(--border);border-radius:8px;
  color:var(--muted);font-family:'DM Mono',monospace;font-size:.76rem;
  cursor:pointer;transition:border-color .2s,color .2s,background .2s;white-space:nowrap;
}
.share-btn:hover{border-color:var(--accent2);color:var(--accent2);background:rgba(71,200,255,.06)}

/* ─── Download button ────────────────────────────── */
.dl-btn{
  display:flex;align-items:center;gap:.35rem;padding:.38rem .85rem;
  background:transparent;border:1px solid var(--border);border-radius:8px;
  color:var(--muted);font-family:'DM Mono',monospace;font-size:.76rem;
  cursor:pointer;transition:border-color .2s,color .2s,background .2s;white-space:nowrap;
}
.dl-btn:hover:not(:disabled){border-color:var(--accent);color:var(--accent);background:rgba(232,255,71,.06)}
.dl-btn:disabled{opacity:.38;cursor:default}
.dl-btn.downloading{border-color:var(--accent);color:var(--accent)}
.dl-btn.downloading .dl-btn-icon{animation:dlSpin .9s linear infinite}
@keyframes dlSpin{to{transform:rotate(360deg)}}

/* ─── Download progress bar ──────────────────────── */
.dl-progress{
  position:fixed;
  bottom:calc(var(--infobar) + var(--adbar) + .8rem);
  left:50%;transform:translateX(-50%) translateY(6px);
  background:var(--panel);border:1px solid var(--border);border-radius:10px;
  padding:.7rem 1.15rem;font-size:.78rem;color:var(--text);
  opacity:0;pointer-events:none;transition:opacity .28s,transform .28s;z-index:999;
  min-width:240px;max-width:340px;
}
.dl-progress.show{opacity:1;transform:translateX(-50%) translateY(0);pointer-events:auto}
.dl-progress-label{display:flex;justify-content:space-between;align-items:center;gap:1rem}
.dl-progress-text{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1}
.dl-progress-pct{color:var(--accent);font-size:.72rem;flex-shrink:0;min-width:2.5rem;text-align:right}
.dl-progress-bar-wrap{height:3px;background:rgba(255,255,255,.08);border-radius:2px;margin-top:.55rem;overflow:hidden}
.dl-progress-bar{height:100%;background:var(--accent);border-radius:2px;width:0%;transition:width .15s linear}
.dl-cancel{
  margin-top:.5rem;font-size:.68rem;color:var(--muted);background:none;border:none;
  cursor:pointer;padding:0;transition:color .2s;font-family:'DM Mono',monospace;
}
.dl-cancel:hover{color:#e85050}

/* ─── HLS badge ──────────────────────────────────── */
.hls-badge{
  display:none;align-items:center;gap:.3rem;
  padding:.22rem .55rem;background:rgba(71,200,255,.1);
  border:1px solid rgba(71,200,255,.3);border-radius:5px;
  font-size:.62rem;color:var(--accent2);letter-spacing:.06em;text-transform:uppercase;
  flex-shrink:0;
}
.hls-badge.show{display:flex}
.hls-dot{width:5px;height:5px;border-radius:50%;background:var(--accent2);
  animation:introPulse 1.4s ease-in-out infinite;}

/* ─── Server badge ───────────────────────────────── */
.srv-badge{
  display:none;align-items:center;gap:.3rem;
  padding:.22rem .55rem;
  background:rgba(232,255,71,.07);
  border:1px solid rgba(232,255,71,.25);border-radius:5px;
  font-size:.62rem;color:var(--accent);letter-spacing:.06em;text-transform:uppercase;
  flex-shrink:0;
}
.srv-badge.show{display:flex}

/* ─── VIDEO STAGE ──────────────────────────────── */
.stage{
  position:fixed;
  top:var(--topbar);left:0;right:0;
  bottom:calc(var(--adbar) + var(--infobar));
  display:flex;align-items:center;justify-content:center;background:#000;
  cursor:pointer;
}

#vid{width:100%;height:100%;object-fit:contain;display:block;}

/* ─── Watermark Canvas ───────────────────────────── */
#wmCanvas{
  position:absolute;inset:0;width:100%;height:100%;
  pointer-events:none;z-index:6;
}

.tap-ripple{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;z-index:5}
.ripple-icon{width:68px;height:68px;background:rgba(0,0,0,.5);border-radius:50%;display:flex;align-items:center;justify-content:center;opacity:0;transform:scale(.6)}
.ripple-icon.pop{animation:ripplePop .42s ease forwards}
@keyframes ripplePop{0%{opacity:0;transform:scale(.6)}30%{opacity:1;transform:scale(1.06)}70%{opacity:1;transform:scale(1)}100%{opacity:0;transform:scale(1.1)}}

.play-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.42);z-index:10;cursor:pointer;opacity:0;pointer-events:none;transition:opacity .25s}
.play-overlay.show{opacity:1;pointer-events:all}
.play-circle{width:72px;height:72px;background:var(--accent);border-radius:50%;display:flex;align-items:center;justify-content:center;transition:transform .18s,box-shadow .18s}
.play-overlay:hover .play-circle{transform:scale(1.1);box-shadow:0 0 36px rgba(232,255,71,.4)}
.play-circle svg{width:28px;height:28px;margin-left:4px}

.intro-badge{
  position:absolute;top:.85rem;left:.85rem;z-index:20;
  display:flex;align-items:center;gap:.38rem;
  background:rgba(0,0,0,.68);backdrop-filter:blur(8px);
  border:1px solid rgba(232,255,71,.35);border-radius:6px;
  padding:.3rem .7rem;pointer-events:none;
  opacity:0;transform:translateY(-4px);
  transition:opacity .3s,transform .3s;
}
.intro-badge.show{opacity:1;transform:translateY(0)}
.intro-badge-dot{width:7px;height:7px;border-radius:50%;background:var(--accent);animation:introPulse 1.1s ease-in-out infinite;}
@keyframes introPulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.7)}}
.intro-badge-text{font-size:.68rem;font-weight:500;color:var(--accent);letter-spacing:.08em;text-transform:uppercase}

#vid.intro-fade{animation:introFadeOut .4s ease forwards}
@keyframes introFadeOut{from{opacity:1}to{opacity:0}}
#vid.main-fade{animation:mainFadeIn .4s ease forwards}
@keyframes mainFadeIn{from{opacity:0}to{opacity:1}}

/* ─── NEXT / PREV overlay nav ───────────────────── */
.nav-btn{
  position:absolute;top:50%;transform:translateY(-50%);
  width:44px;height:64px;
  display:flex;align-items:center;justify-content:center;
  background:rgba(5,5,8,.55);backdrop-filter:blur(8px);
  border:1px solid rgba(255,255,255,.1);
  color:rgba(255,255,255,.75);
  cursor:pointer;z-index:12;
  opacity:0;
  transition:opacity .22s,background .2s,color .2s,transform .22s;
  border-radius:6px;
}
.nav-btn.prev-btn{left:.75rem;border-radius:8px 6px 6px 8px;}
.nav-btn.next-btn{right:.75rem;border-radius:6px 8px 8px 6px;}
.nav-btn:hover{background:rgba(11,12,18,.85);color:var(--accent);border-color:rgba(232,255,71,.35);}
.nav-btn:disabled{opacity:0!important;cursor:default;pointer-events:none;}
.nav-btn svg{width:20px;height:20px;flex-shrink:0}

.stage:hover .nav-btn:not(:disabled){opacity:1}
.stage.is-paused .nav-btn:not(:disabled){opacity:1}
.stage.nav-show .nav-btn:not(:disabled){opacity:1}

@media(hover:none){
  .nav-btn:not(:disabled){opacity:.45}
  .nav-btn:not(:disabled):active{
    opacity:1;
    background:rgba(11,12,18,.9);
    color:var(--accent);
    border-color:rgba(232,255,71,.4);
  }
}

.nav-btn .nav-tip{
  position:absolute;
  background:rgba(11,12,18,.9);border:1px solid var(--border);border-radius:6px;
  font-size:.68rem;color:var(--text);white-space:nowrap;padding:.3rem .6rem;
  pointer-events:none;opacity:0;transition:opacity .18s;max-width:160px;
  overflow:hidden;text-overflow:ellipsis;
}
.nav-btn.prev-btn .nav-tip{left:calc(100% + 8px)}
.nav-btn.next-btn .nav-tip{right:calc(100% + 8px)}
.nav-btn:hover .nav-tip{opacity:1}

.nav-transition{
  position:absolute;inset:0;z-index:25;
  background:#000;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1rem;
  opacity:0;pointer-events:none;transition:opacity .3s;
}
.nav-transition.show{opacity:1;pointer-events:all}
.nav-transition-spinner{
  width:36px;height:36px;border-radius:50%;
  border:2px solid rgba(255,255,255,.1);border-top-color:var(--accent);
  animation:spinRing .8s linear infinite;
}
.nav-transition-text{font-size:.78rem;color:var(--muted);}
.nav-transition-title{font-family:'Syne',sans-serif;font-weight:700;font-size:.95rem;color:var(--text);max-width:260px;text-align:center;}

/* ─── IKLAN BERJALAN ────────────────────────────── */
.adbar{
  position:fixed;left:0;right:0;bottom:var(--infobar);height:var(--adbar);
  background:var(--ad-bg);
  border-top:1px solid rgba(255,204,51,.18);border-bottom:1px solid rgba(255,204,51,.1);
  display:flex;align-items:center;overflow:hidden;z-index:90;
}
.ad-label{
  flex-shrink:0;display:flex;align-items:center;gap:.4rem;padding:0 .75rem;height:100%;
  background:var(--ad-accent);color:#0a0a0a;font-family:'Syne',sans-serif;font-weight:800;
  font-size:.65rem;letter-spacing:.1em;text-transform:uppercase;white-space:nowrap;
  border-right:2px solid rgba(0,0,0,.2);
}
.ad-label svg{width:13px;height:13px;flex-shrink:0}
.ad-track-wrap{
  flex:1;overflow:hidden;height:100%;position:relative;
  mask-image:linear-gradient(90deg,transparent 0,#000 3%,#000 97%,transparent 100%);
  -webkit-mask-image:linear-gradient(90deg,transparent 0,#000 3%,#000 97%,transparent 100%);
}
.ad-track{display:flex;align-items:center;height:100%;white-space:nowrap;will-change:transform;}
.ad-track:hover{animation-play-state:paused}
@keyframes adScroll{0%{transform:translateX(0)}100%{transform:translateX(-50%)}}
.ad-item{
  display:inline-flex;align-items:center;gap:.7rem;padding:0 2.5rem;
  color:var(--ad-text);font-size:.78rem;cursor:pointer;text-decoration:none;transition:color .2s;
}
.ad-item:hover{color:var(--ad-accent)}
.ad-dot{width:5px;height:5px;background:var(--ad-accent);border-radius:50%;flex-shrink:0;opacity:.6}
.ad-cta{
  display:inline-flex;align-items:center;padding:.18rem .6rem;
  background:rgba(255,204,51,.15);border:1px solid rgba(255,204,51,.3);border-radius:4px;
  color:var(--ad-accent);font-size:.68rem;font-weight:500;white-space:nowrap;margin-left:.3rem;transition:background .2s;
}
.ad-item:hover .ad-cta{background:rgba(255,204,51,.28)}

/* ─── INFOBAR ───────────────────────────────────── */
.infobar{
  position:fixed;bottom:0;left:0;right:0;height:var(--infobar);
  background:rgba(11,12,18,.96);backdrop-filter:blur(14px);
  border-top:1px solid var(--border);
  display:flex;align-items:center;padding:0 1.25rem;gap:.75rem;z-index:100;
}
.vid-title{
  flex:1;font-family:'Syne',sans-serif;font-weight:700;font-size:.95rem;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text);min-width:0;
}
.code-pill{
  flex-shrink:0;font-size:.67rem;color:var(--muted);
  background:rgba(255,255,255,.04);border:1px solid var(--border);
  border-radius:5px;padding:.2rem .55rem;letter-spacing:.04em;
}

/* ─── TOAST ─────────────────────────────────────── */
.toast{
  position:fixed;
  bottom:calc(var(--infobar) + var(--adbar) + .8rem);
  left:50%;transform:translateX(-50%) translateY(6px);
  background:var(--panel);border:1px solid var(--border);border-radius:9px;
  padding:.65rem 1.2rem;font-size:.8rem;color:var(--accent2);
  opacity:0;pointer-events:none;transition:opacity .28s,transform .28s;z-index:999;white-space:nowrap;
}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}

/* ─── Error overlay ──────────────────────────────── */
.error-overlay{
  position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;
  background:rgba(0,0,0,.82);z-index:15;
  opacity:0;pointer-events:none;transition:opacity .3s;gap:1rem;padding:1.5rem;
}
.error-overlay.show{opacity:1;pointer-events:all}
.error-icon{
  width:52px;height:52px;border-radius:50%;
  background:rgba(232,80,80,.15);border:1px solid rgba(232,80,80,.35);
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
  transition:background .3s,border-color .3s;
}
.error-icon svg{width:22px;height:22px;color:#e85050;transition:color .3s}
.error-icon.deleted{background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.18)}
.error-icon.deleted svg{color:#6b7280}
.error-title.deleted{color:#9ca3af}
.error-title{font-family:'Syne',sans-serif;font-weight:700;font-size:1rem;color:var(--text);text-align:center;transition:color .3s}
.error-msg{font-size:.75rem;color:var(--muted);text-align:center;max-width:280px;line-height:1.6;}
.error-badge{
  display:inline-flex;align-items:center;gap:.35rem;
  background:rgba(232,255,71,.08);border:1px solid rgba(232,255,71,.2);
  border-radius:5px;padding:.28rem .7rem;font-size:.7rem;color:var(--accent);
}
.retry-btn{
  display:flex;align-items:center;gap:.45rem;
  padding:.55rem 1.4rem;background:var(--accent);border:none;border-radius:8px;
  color:#050508;font-family:'DM Mono',monospace;font-size:.8rem;font-weight:500;
  cursor:pointer;transition:opacity .2s,transform .15s;margin-top:.25rem;
}
.retry-btn:hover{opacity:.88;transform:scale(1.03)}
.retry-btn:disabled{opacity:.35;cursor:default;transform:none}
.retry-btn svg{width:14px;height:14px;flex-shrink:0}

/* ─── Buffering spinner ──────────────────────────── */
.buffer-spinner{
  position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
  pointer-events:none;z-index:8;opacity:0;transition:opacity .3s;
}
.buffer-spinner.show{opacity:1}
.spinner-ring{
  width:42px;height:42px;border-radius:50%;
  border:2px solid rgba(255,255,255,.12);
  border-top-color:var(--accent);
  animation:spinRing .8s linear infinite;
}
@keyframes spinRing{to{transform:rotate(360deg)}}

/* ─── MOBILE ─────────────────────────────────────── */
@media(max-width:480px){
  :root{--topbar:46px;--adbar:34px;--infobar:50px}
  .logo-name{font-size:.92rem}
  .share-btn span,.dl-btn span{display:none}
  .share-btn,.dl-btn{padding:.38rem .52rem}
  .vid-title{font-size:.82rem}.code-pill{display:none}
  .ad-item{font-size:.72rem;padding:0 1.8rem}.ad-cta{display:none}
  .ad-label{padding:0 .55rem;font-size:.6rem}
  .error-title{font-size:.88rem}.error-msg{font-size:.7rem}
  .nav-btn{width:36px;height:52px;}
  .nav-btn svg{width:16px;height:16px;}
  .nav-btn .nav-tip{display:none}
  .srv-badge span{display:none}
}
@media(max-width:360px){
  :root{--topbar:42px;--adbar:30px;--infobar:46px}
  .ad-item{font-size:.68rem;padding:0 1.4rem}
}
@supports(padding-bottom:env(safe-area-inset-bottom)){
  .infobar{padding-bottom:calc(.6rem + env(safe-area-inset-bottom));height:calc(var(--infobar) + env(safe-area-inset-bottom))}
}
</style>
</head>
<body>

<header class="topbar">
  <a href="/" class="logo">
    <div class="logo-icon">
      <svg viewBox="0 0 24 24" fill="none"><path d="M8 5l11 7-11 7V5z" fill="#050508"/></svg>
    </div>
    <span class="logo-name">Vid<span>Share</span></span>
  </a>
  <div class="topbar-actions">
    <button class="dl-btn" id="dlBtn" onclick="downloadVideo()" title="Download video">
      <svg class="dl-btn-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
        <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/>
      </svg>
      <span>Download</span>
    </button>
    <button class="share-btn" onclick="shareVideo()">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
        <path d="M4 12v8a2 2 0 002 2h12a2 2 0 002-2v-8M16 6l-4-4-4 4M12 2v13"/>
      </svg>
      <span>Share</span>
    </button>
  </div>
</header>

<div class="stage" id="stage" onclick="handleStageClick(event)">
  <video id="vid" muted playsinline preload="metadata" crossorigin="anonymous"></video>

  <!-- Watermark canvas overlay -->
  <canvas id="wmCanvas"></canvas>

  <div class="intro-badge" id="introBadge">
    <span class="intro-badge-dot"></span>
    <span class="intro-badge-text">Intro</span>
  </div>

  <!-- Prev / Next navigation -->
  <button class="nav-btn prev-btn" id="prevBtn" onclick="navigateVideo('prev',event)" disabled>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="15 18 9 12 15 6"/>
    </svg>
    <span class="nav-tip" id="prevTip"></span>
  </button>
  <button class="nav-btn next-btn" id="nextBtn" onclick="navigateVideo('next',event)" disabled>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="9 18 15 12 9 6"/>
    </svg>
    <span class="nav-tip" id="nextTip"></span>
  </button>

  <!-- Nav loading transition -->
  <div class="nav-transition" id="navTransition">
    <div class="nav-transition-spinner"></div>
    <div class="nav-transition-text">Memuat video berikutnya…</div>
    <div class="nav-transition-title" id="navTransitionTitle"></div>
  </div>

  <div class="tap-ripple">
    <div class="ripple-icon" id="rippleIcon">
      <svg id="rippleSvg" width="28" height="28" viewBox="0 0 24 24" fill="white">
        <path d="M8 5l11 7-11 7V5z"/>
      </svg>
    </div>
  </div>

  <div class="play-overlay" id="playOverlay" onclick="manualPlay(event)">
    <div class="play-circle">
      <svg viewBox="0 0 24 24" fill="none"><path d="M8 5l11 7-11 7V5z" fill="#050508"/></svg>
    </div>
  </div>

  <div class="buffer-spinner" id="bufferSpinner">
    <div class="spinner-ring"></div>
  </div>

  <div class="error-overlay" id="errorOverlay">
    <div class="error-icon" id="errorIcon">
      <svg id="errorIconSvg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8" x2="12" y2="12"/>
        <line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
    </div>
    <div class="error-title" id="errorTitle">Gagal memuat video</div>
    <div class="error-msg"   id="errorMsg">Terjadi kesalahan saat memuat video.</div>
    <div class="error-badge" id="errorBadge" style="display:none"></div>
    <button class="retry-btn" id="retryBtn" onclick="retryLoad()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
        <polyline points="1 4 1 10 7 10"/>
        <path d="M3.51 15a9 9 0 102.13-9.36L1 10"/>
      </svg>
      Coba Lagi
    </button>
  </div>
</div>

<!-- Adbar -->
<div class="adbar">
  <div class="ad-label">
    <svg viewBox="0 0 24 24" fill="currentColor">
      <path d="M12 2a10 10 0 100 20A10 10 0 0012 2zm0 18a8 8 0 110-16 8 8 0 010 16zm-1-5h2v2h-2zm0-8h2v6h-2z"/>
    </svg>
    IKLAN
  </div>
  <div class="ad-track-wrap">
    <div class="ad-track" id="adTrack"></div>
  </div>
</div>

<footer class="infobar">
  <span class="vid-title" id="vidTitleEl"><?= $title ?></span>
  <!-- Server badge: tampil hanya untuk kode baru -->
  <div class="srv-badge<?= $server_label ? ' show' : '' ?>" id="srvBadge" title="<?= htmlspecialchars($server_label) ?>">
    <?= htmlspecialchars($server_label) ?>
  </div>
  <!-- HLS badge: tampil saat stream HLS -->
  <div class="hls-badge" id="hlsBadge">
    <span class="hls-dot"></span>HLS
  </div>
  <span class="code-pill" id="codePillEl"><?= htmlspecialchars($code) ?></span>
</footer>

<div class="toast" id="toast"></div>

<!-- Download progress UI -->
<div class="dl-progress" id="dlProgress">
  <div class="dl-progress-label">
    <span class="dl-progress-text" id="dlProgressText">Menyiapkan download…</span>
    <span class="dl-progress-pct"  id="dlProgressPct"></span>
  </div>
  <div class="dl-progress-bar-wrap">
    <div class="dl-progress-bar" id="dlProgressBar"></div>
  </div>
  <button class="dl-cancel" id="dlCancelBtn" onclick="cancelDownload()">✕ Batalkan</button>
</div>

<script>
/* ── Data dari PHP ── */
const ADS_DATA     = <?= $ads_json ?>;
const SHARE        = <?= json_encode($share_url,        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const TITLE        = <?= json_encode($video['title'] ?? 'VidShare', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const STREAM_URL   = <?= json_encode($stream_url,       JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const DOWNLOAD_URL = <?= json_encode($download_url,     JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const IS_HLS       = <?= $is_hls ? 'true' : 'false' ?>;
const INTRO_URL    = 'https://play.vidshare.my.id/intro.mp4';
const BASE_URL     = 'https://play.vidshare.my.id';
const CODE         = <?= json_encode($code, JSON_UNESCAPED_SLASHES) ?>;
const SERVER_LABEL = <?= json_encode($server_label, JSON_UNESCAPED_UNICODE) ?>;

/* ── Watermark config ── */
const WM_TEXT    = <?= json_encode($watermark_text,    JSON_UNESCAPED_UNICODE) ?>;
const WM_OPACITY = <?= json_encode($watermark_opacity) ?>;
const WM_POS     = <?= json_encode($watermark_pos)     ?>;

/* ── Adjacent videos (sudah dibatasi per server di PHP) ── */
const PREV_VIDEO = <?= $prev_json ?>;
const NEXT_VIDEO = <?= $next_json ?>;

/* ── Refs ── */
const vid            = document.getElementById('vid');
const overlay        = document.getElementById('playOverlay');
const introBadge     = document.getElementById('introBadge');
const hlsBadge       = document.getElementById('hlsBadge');
const ripIcon        = document.getElementById('rippleIcon');
const ripSvg         = document.getElementById('rippleSvg');
const errorOverlay   = document.getElementById('errorOverlay');
const errorIcon      = document.getElementById('errorIcon');
const errorIconSvg   = document.getElementById('errorIconSvg');
const errorTitle     = document.getElementById('errorTitle');
const errorMsg       = document.getElementById('errorMsg');
const errorBadge     = document.getElementById('errorBadge');
const retryBtn       = document.getElementById('retryBtn');
const bufferSpinner  = document.getElementById('bufferSpinner');
const dlBtn          = document.getElementById('dlBtn');
const dlProgress     = document.getElementById('dlProgress');
const dlProgressText = document.getElementById('dlProgressText');
const dlProgressPct  = document.getElementById('dlProgressPct');
const dlProgressBar  = document.getElementById('dlProgressBar');
const wmCanvas       = document.getElementById('wmCanvas');
const stage          = document.getElementById('stage');
const prevBtn        = document.getElementById('prevBtn');
const nextBtn        = document.getElementById('nextBtn');
const prevTip        = document.getElementById('prevTip');
const nextTip        = document.getElementById('nextTip');
const navTransition      = document.getElementById('navTransition');
const navTransitionTitle = document.getElementById('navTransitionTitle');

/* ── State ── */
let _isIntro      = true;
let _unmuted      = false;
let _introRetried = false;
let _mainRetried  = false;
let _bufferTimer  = null;
let _hls          = null;
let _dlController = null;
let _wmRaf        = null;

const ICON_WARNING = `<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>`;
const ICON_TRASH   = `<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/>`;

const ERROR_MSGS = {
  1: { title: 'Pemutaran dibatalkan',  msg: 'Pemutaran dihentikan oleh browser.',                                deleted: false },
  2: { title: 'Kesalahan jaringan',    msg: 'Koneksi bermasalah. Periksa internet kamu.',                        deleted: false },
  3: { title: 'Gagal mendekode video', msg: 'File video tidak dapat diproses oleh browser.',                     deleted: false },
  4: { title: 'Video Telah Dihapus',   msg: 'Video ini sudah tidak tersedia atau telah dihapus oleh pemiliknya.',deleted: true  },
};

/* ══ WATERMARK CANVAS ════════════════════════════════════════ */
const wmCtx = wmCanvas.getContext('2d');

function drawWatermark() {
  const W = wmCanvas.width  = stage.clientWidth;
  const H = wmCanvas.height = stage.clientHeight;
  wmCtx.clearRect(0, 0, W, H);
  if (!WM_TEXT) { _wmRaf = requestAnimationFrame(drawWatermark); return; }

  const vidW = vid.videoWidth  || W;
  const vidH = vid.videoHeight || H;
  const scale = Math.min(W / vidW, H / vidH);
  const rW    = vidW * scale;
  const rH    = vidH * scale;
  const rX    = (W - rW) / 2;
  const rY    = (H - rH) / 2;

  const fontSize = Math.max(11, Math.round(rW * 0.022));
  wmCtx.font = `500 ${fontSize}px 'DM Mono', monospace`;
  wmCtx.textBaseline = 'alphabetic';

  const padding = Math.round(fontSize * 1.1);
  const textW   = wmCtx.measureText(WM_TEXT).width;
  const textH   = fontSize;

  let tx, ty;
  switch (WM_POS) {
    case 'top-left':    tx = rX + padding;              ty = rY + padding + textH;   break;
    case 'top-right':   tx = rX + rW - padding - textW; ty = rY + padding + textH;   break;
    case 'bottom-left': tx = rX + padding;              ty = rY + rH - padding;      break;
    case 'center':      tx = rX + (rW - textW) / 2;    ty = rY + (rH + textH) / 2; break;
    default:            tx = rX + rW - padding - textW; ty = rY + rH - padding;      break;
  }

  wmCtx.shadowColor   = 'rgba(0,0,0,0.7)';
  wmCtx.shadowBlur    = fontSize * 0.6;
  wmCtx.shadowOffsetX = 0;
  wmCtx.shadowOffsetY = 0;
  wmCtx.globalAlpha   = WM_OPACITY;
  wmCtx.fillStyle     = '#ffffff';
  wmCtx.fillText(WM_TEXT, tx, ty);
  wmCtx.globalAlpha   = 1;
  wmCtx.shadowColor   = 'transparent';
  wmCtx.shadowBlur    = 0;

  _wmRaf = requestAnimationFrame(drawWatermark);
}

drawWatermark();

/* ══ NEXT / PREV NAV ═════════════════════════════════════════
 * PREV_VIDEO dan NEXT_VIDEO sudah dibatasi scope per server
 * oleh getAdjacentVideos() di PHP — tidak perlu logika tambahan.
 * ════════════════════════════════════════════════════════════ */
(function initNav() {
  if (PREV_VIDEO) { prevBtn.disabled = false; prevTip.textContent = '← ' + PREV_VIDEO.title; }
  if (NEXT_VIDEO) { nextBtn.disabled = false; nextTip.textContent = NEXT_VIDEO.title + ' →'; }
})();

let _navShowTimer;
function showNavTemporary() {
  stage.classList.add('nav-show');
  clearTimeout(_navShowTimer);
  _navShowTimer = setTimeout(() => stage.classList.remove('nav-show'), 3200);
}
stage.addEventListener('touchstart', showNavTemporary, { passive: true });

function navigateVideo(dir, e) {
  if (e) e.stopPropagation();
  const target = dir === 'prev' ? PREV_VIDEO : NEXT_VIDEO;
  if (!target) return;
  navTransitionTitle.textContent = target.title;
  navTransition.querySelector('.nav-transition-text').textContent =
    dir === 'prev' ? 'Memuat video sebelumnya…' : 'Memuat video berikutnya…';
  navTransition.classList.add('show');
  vid.pause();
  if (_dlController) { _dlController.abort(); _dlController = null; }
  if (_hls) { _hls.destroy(); _hls = null; }
  setTimeout(() => { window.location.href = BASE_URL + '/' + target.code; }, 350);
}

document.addEventListener('keydown', e => {
  if (e.target.tagName === 'INPUT') return;
  if ((e.key === '[') || (e.key === 'ArrowLeft'  && e.shiftKey)) { navigateVideo('prev', null); return; }
  if ((e.key === ']') || (e.key === 'ArrowRight' && e.shiftKey)) { navigateVideo('next', null); return; }
});

(function initSwipe() {
  let sx = 0, sy = 0;
  stage.addEventListener('touchstart', e => { sx = e.touches[0].clientX; sy = e.touches[0].clientY; }, { passive: true });
  stage.addEventListener('touchend',   e => {
    const dx = e.changedTouches[0].clientX - sx;
    const dy = e.changedTouches[0].clientY - sy;
    if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) {
      if (dx < 0) navigateVideo('next', null);
      else         navigateVideo('prev', null);
    }
  }, { passive: true });
})();

vid.addEventListener('pause', () => { stage.classList.add('is-paused');    overlay.classList.add('show'); });
vid.addEventListener('play',  () => { stage.classList.remove('is-paused'); overlay.classList.remove('show'); });

/* ══ IKLAN ═══════════════════════════════════════════════════ */
(function initAd() {
  const track = document.getElementById('adTrack');
  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function buildItems() {
    return ADS_DATA.map(ad => {
      const ctaHtml = ad.label ? `<span class="ad-cta">${escHtml(ad.label)}</span>` : '';
      if (ad.url) {
        return `<a href="${escHtml(ad.url)}" target="_blank" rel="noopener" class="ad-item">
          <span class="ad-dot"></span>${escHtml(ad.text)}${ctaHtml}</a>`;
      }
      return `<span class="ad-item"><span class="ad-dot"></span>${escHtml(ad.text)}${ctaHtml}</span>`;
    }).join('');
  }
  const items = buildItems();
  track.innerHTML = items + items;
  requestAnimationFrame(() => requestAnimationFrame(() => {
    const totalW = track.scrollWidth / 2;
    const dur    = Math.max(6, totalW / 120);
    track.style.animation = `adScroll ${dur}s linear infinite`;
  }));
})();

/* ══ STREAM ATTACH ═══════════════════════════════════════════ */
function attachMainStream() {
  if (_hls) { _hls.destroy(); _hls = null; }

  if (IS_HLS) {
    if (typeof Hls !== 'undefined' && Hls.isSupported()) {
      hlsBadge.classList.add('show');
      _hls = new Hls({ maxBufferLength:30, maxMaxBufferLength:60, enableWorker:true, startLevel:-1 });
      _hls.loadSource(STREAM_URL);
      _hls.attachMedia(vid);
      _hls.on(Hls.Events.MANIFEST_PARSED, () => {
        vid.play().catch(() => overlay.classList.add('show'));
      });
      _hls.on(Hls.Events.ERROR, (_e, data) => {
        if (!data.fatal) return;
        hideSpinner();
        if (data.type === Hls.ErrorTypes.NETWORK_ERROR) {
          if (!_mainRetried) { _mainRetried = true; showToast('⏳ Mencoba kembali…'); _hls.startLoad(); }
          else showError('Kesalahan jaringan HLS', 'Stream tidak dapat dijangkau.', false, false);
        } else if (data.type === Hls.ErrorTypes.MEDIA_ERROR) {
          if (!_mainRetried) { _mainRetried = true; showToast('🔄 Memulihkan media…'); _hls.recoverMediaError(); }
          else showError('Gagal dekode stream', 'Stream tidak dapat diputar di browser ini.', false, false);
        } else {
          showError('Stream gagal', 'Format tidak didukung atau stream tidak tersedia.', false, false);
          _hls.destroy(); _hls = null;
        }
      });
    } else if (vid.canPlayType('application/vnd.apple.mpegurl')) {
      hlsBadge.classList.add('show');
      vid.src = STREAM_URL;
      vid.play().catch(() => overlay.classList.add('show'));
    } else {
      showError('Browser tidak mendukung HLS', 'Coba buka di Chrome, Firefox, atau Safari terbaru.', false, false);
    }
    dlBtn.disabled = true;
    dlBtn.title    = 'Download tidak tersedia untuk stream HLS';
  } else {
    vid.src = STREAM_URL;
    vid.play().catch(() => overlay.classList.add('show'));
  }
}

/* ══ INTRO → MAIN ════════════════════════════════════════════ */
function loadIntro() {
  _isIntro = true;
  vid.src  = INTRO_URL;
  vid.muted = true;
  vid.currentTime = 0;
  hideError();
  const p = vid.play();
  if (p !== undefined) {
    p.then(() => {
      introBadge.classList.add('show');
      setTimeout(() => { if (!_unmuted) { vid.muted = false; _unmuted = true; } }, 400);
    }).catch(() => overlay.classList.add('show'));
  }
}

function switchToMain() {
  vid.classList.add('intro-fade');
  setTimeout(() => {
    _isIntro = false;
    introBadge.classList.remove('show');
    vid.classList.remove('intro-fade');
    hideError();
    vid.muted = false;
    vid.addEventListener('canplay', () => {
      vid.classList.add('main-fade');
      setTimeout(() => vid.classList.remove('main-fade'), 400);
    }, { once: true });
    attachMainStream();
  }, 380);
}

const SKIP_INTRO = true;
SKIP_INTRO ? switchToMain() : loadIntro();

vid.addEventListener('ended', () => {
  if (_isIntro) {
    switchToMain();
  } else {
    if (NEXT_VIDEO) {
      showToast('▶️ Memuat video berikutnya…');
      setTimeout(() => navigateVideo('next', null), 1500);
    } else {
      vid.currentTime = 0; vid.play();
    }
  }
});

function manualPlay(e) {
  if (e) e.stopPropagation();
  vid.muted = false; _unmuted = true;
  vid.play();
  overlay.classList.remove('show');
  if (_isIntro) introBadge.classList.add('show');
}

/* ══ ERROR DISPLAY ═══════════════════════════════════════════ */
function showError(titleText, msgText, showRetry, deleted = false) {
  hideSpinner();
  overlay.classList.remove('show');
  if (deleted) {
    errorIcon.classList.add('deleted');
    errorIconSvg.innerHTML = ICON_TRASH;
    errorTitle.classList.add('deleted');
  } else {
    errorIcon.classList.remove('deleted');
    errorIconSvg.innerHTML = ICON_WARNING;
    errorTitle.classList.remove('deleted');
  }
  errorTitle.textContent = titleText;
  errorMsg.textContent   = msgText;
  retryBtn.style.display   = (showRetry && !deleted) ? '' : 'none';
  errorBadge.style.display = (!showRetry || deleted) ? '' : 'none';
  if (!showRetry || deleted) {
    errorBadge.textContent = deleted
      ? 'Hubungi pemilik konten untuk info lebih lanjut'
      : 'Hubungi admin jika masalah berlanjut';
  }
  errorOverlay.classList.add('show');
}

function hideError() { errorOverlay.classList.remove('show'); retryBtn.disabled = false; }

function showSpinner() {
  clearTimeout(_bufferTimer);
  _bufferTimer = setTimeout(() => bufferSpinner.classList.add('show'), 400);
}
function hideSpinner() {
  clearTimeout(_bufferTimer);
  bufferSpinner.classList.remove('show');
}

/* ══ RETRY ═══════════════════════════════════════════════════ */
function retryLoad() {
  retryBtn.disabled = true;
  hideError();
  if (_isIntro) {
    if (!_introRetried) {
      _introRetried = true;
      showToast('🔄 Mencoba kembali intro…');
      setTimeout(() => loadIntro(), 800);
    } else {
      showToast('⚡ Intro dilewati, memuat video…');
      setTimeout(() => switchToMain(), 400);
    }
    return;
  }
  if (!_mainRetried) {
    _mainRetried = true;
    showToast('🔄 Mencoba kembali memuat video…');
    setTimeout(() => {
      if (IS_HLS) attachMainStream();
      else { vid.load(); vid.play().catch(() => overlay.classList.add('show')); }
    }, 800);
  } else {
    showError('Video tidak dapat dimuat', 'Sudah mencoba 2 kali namun tetap gagal. Coba muat ulang halaman.', false, false);
  }
}

/* ══ NATIVE VIDEO ERROR ══════════════════════════════════════ */
vid.addEventListener('error', () => {
  if (_hls) return;
  hideSpinner();
  const c    = vid.error ? vid.error.code : 0;
  const info = ERROR_MSGS[c] || { title: 'Video tidak dapat dimuat', msg: 'Terjadi kesalahan yang tidak diketahui.', deleted: false };
  if (_isIntro) {
    if (!_introRetried) {
      showToast('⚠️ Intro gagal — mencoba kembali…');
      setTimeout(() => { _introRetried = true; loadIntro(); }, 1500);
    } else {
      showToast('⚡ Intro dilewati, memuat video…');
      setTimeout(() => switchToMain(), 400);
    }
  } else {
    if (info.deleted) showError(info.title, info.msg, false, true);
    else if (!_mainRetried) showError(info.title, info.msg + ' Kamu dapat mencoba kembali sekali.', true, false);
    else showError('Gagal setelah 2 percobaan', 'Coba muat ulang halaman atau periksa koneksi internet.', false, false);
  }
});

vid.addEventListener('waiting', () => { if (!errorOverlay.classList.contains('show')) showSpinner(); });
vid.addEventListener('stalled', () => {
  if (!errorOverlay.classList.contains('show')) { showSpinner(); showToast('⏳ Koneksi lambat, buffering…'); }
});
vid.addEventListener('playing', () => { hideSpinner(); hideError(); });
vid.addEventListener('canplay', () => { hideSpinner(); });

/* ══ STAGE CLICK ═════════════════════════════════════════════ */
function handleStageClick(e) {
  if (e.target === overlay      || overlay.contains(e.target))      return;
  if (e.target === errorOverlay || errorOverlay.contains(e.target)) return;
  if (e.target === prevBtn      || prevBtn.contains(e.target))      return;
  if (e.target === nextBtn      || nextBtn.contains(e.target))      return;
  togglePlay();
}

function togglePlay() {
  if (vid.paused) { vid.play(); showRipple('play'); }
  else            { vid.pause(); showRipple('pause'); }
}

function showRipple(state) {
  ripSvg.innerHTML = state === 'pause'
    ? '<rect x="6" y="4" width="4" height="16" fill="white"/><rect x="14" y="4" width="4" height="16" fill="white"/>'
    : '<path d="M8 5l11 7-11 7V5z" fill="white"/>';
  ripIcon.classList.remove('pop');
  void ripIcon.offsetWidth;
  ripIcon.classList.add('pop');
}

/* ══ KEYBOARD SHORTCUTS ══════════════════════════════════════ */
document.addEventListener('keydown', e => {
  if (e.target.tagName === 'INPUT') return;
  switch (e.key) {
    case ' ': case 'k': e.preventDefault(); togglePlay(); break;
    case 'm': vid.muted = !vid.muted; break;
    case 'f':
      if (!document.fullscreenElement)
        (vid.requestFullscreen || vid.webkitRequestFullscreen || vid.mozRequestFullScreen).call(vid);
      else
        (document.exitFullscreen || document.webkitExitFullscreen).call(document);
      break;
    case 'ArrowRight':
      if (!e.shiftKey && !_isIntro && isFinite(vid.duration)) { vid.currentTime = Math.min(vid.duration, vid.currentTime + 10); showRipple('play'); }
      break;
    case 'ArrowLeft':
      if (!e.shiftKey && !_isIntro) { vid.currentTime = Math.max(0, vid.currentTime - 10); showRipple('pause'); }
      break;
    case 'ArrowUp':   vid.volume = Math.min(1, vid.volume + .1); break;
    case 'ArrowDown': vid.volume = Math.max(0, vid.volume - .1); break;
  }
});

/* ══ SHARE ═══════════════════════════════════════════════════ */
function shareVideo() {
  if (navigator.share) {
    navigator.share({ title: TITLE, url: SHARE }).catch(() => {});
  } else {
    copyText(SHARE);
    showToast('🔗 Link disalin!');
  }
}

function copyText(t) {
  if (navigator.clipboard) { navigator.clipboard.writeText(t); return; }
  const a = document.createElement('textarea');
  a.value = t; document.body.appendChild(a); a.select();
  document.execCommand('copy'); document.body.removeChild(a);
}

/* ══ DOWNLOAD ════════════════════════════════════════════════ */
async function downloadVideo() {
  if (IS_HLS) { showToast('⚠️ Stream HLS tidak bisa didownload langsung.'); return; }
  if (_dlController) return;

  const safeName = (TITLE || 'video').replace(/[^\w\s\-().]/g, '').trim() || 'video';

  dlBtn.disabled = true;
  dlBtn.classList.add('downloading');
  dlBtn.querySelector('.dl-btn-icon').innerHTML =
    '<circle cx="12" cy="12" r="10" stroke-dasharray="60" stroke-dashoffset="0" style="animation:spinRing .9s linear infinite;transform-origin:center"/>';

  dlProgressText.textContent    = 'Menghubungkan ke server…';
  dlProgressPct.textContent     = '';
  dlProgressBar.style.width     = '0%';
  dlProgressBar.style.animation = '';
  dlProgress.classList.add('show');

  _dlController = new AbortController();

  try {
    const resp = await fetch(DOWNLOAD_URL, {
      signal: _dlController.signal,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    if (!resp.ok) throw new Error('HTTP ' + resp.status);

    const contentLength = resp.headers.get('Content-Length');
    const total = contentLength ? parseInt(contentLength, 10) : 0;
    let received = 0;
    const chunks = [];

    dlProgressText.textContent = 'Mendownload video…';

    if (!total) {
      if (!document.getElementById('_dlKf')) {
        const s = document.createElement('style');
        s.id = '_dlKf';
        s.textContent = '@keyframes dlIndeterminate{0%{width:0%;margin-left:0}50%{width:60%;margin-left:20%}100%{width:0%;margin-left:100%}}';
        document.head.appendChild(s);
      }
      dlProgressBar.style.animation = 'dlIndeterminate 1.4s ease-in-out infinite';
    }

    const reader = resp.body.getReader();
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      if (_dlController.signal.aborted) break;
      chunks.push(value);
      received += value.length;
      if (total) {
        const pct = Math.min(95, Math.round(received / total * 100));
        dlProgressBar.style.animation = '';
        dlProgressBar.style.width     = pct + '%';
        dlProgressPct.textContent     = pct + '%';
        dlProgressText.textContent    = 'Mendownload ' + formatBytes(received) + ' / ' + formatBytes(total);
      } else {
        dlProgressText.textContent = 'Mendownload ' + formatBytes(received) + '…';
      }
    }

    if (_dlController.signal.aborted) throw new DOMException('Aborted', 'AbortError');

    const rawBlob = new Blob(chunks, { type: 'video/mp4' });
    dlProgressBar.style.animation = '';
    dlProgressBar.style.width     = '100%';
    dlProgressPct.textContent     = '100%';
    dlProgressText.textContent    = 'Download selesai!';

    triggerBlobDownload(rawBlob, safeName + '.mp4');
    showToast('✅ Download selesai!');

  } catch (err) {
    if (err.name === 'AbortError') {
      showToast('⛔ Download dibatalkan.');
    } else {
      console.warn('[download] fetch gagal, fallback anchor:', err.message);
      const a    = document.createElement('a');
      a.href     = DOWNLOAD_URL;
      a.download = safeName + '.mp4';
      a.target   = '_blank';
      document.body.appendChild(a); a.click(); document.body.removeChild(a);
      showToast('⬇️ Download dimulai di tab baru…');
    }
  } finally {
    _dlController = null;
    dlBtn.disabled = false;
    dlBtn.classList.remove('downloading');
    dlBtn.querySelector('.dl-btn-icon').innerHTML =
      '<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/>';
    setTimeout(() => {
      dlProgress.classList.remove('show');
      dlProgressBar.style.animation = '';
    }, 1400);
  }
}

function triggerBlobDownload(blob, filename) {
  const url = URL.createObjectURL(blob);
  const a   = document.createElement('a');
  a.href = url; a.download = filename;
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
  setTimeout(() => URL.revokeObjectURL(url), 60000);
}

function cancelDownload() {
  if (_dlController) { _dlController.abort(); _dlController = null; }
}

function formatBytes(b) {
  if (b < 1024)       return b + ' B';
  if (b < 1048576)    return (b / 1024).toFixed(1) + ' KB';
  if (b < 1073741824) return (b / 1048576).toFixed(1) + ' MB';
  return (b / 1073741824).toFixed(2) + ' GB';
}

/* ══ TOAST ═══════════════════════════════════════════════════ */
let _toastTimer;
function showToast(msg) {
  const el = document.getElementById('toast');
  el.textContent = msg; el.classList.add('show');
  clearTimeout(_toastTimer);
  _toastTimer = setTimeout(() => el.classList.remove('show'), 2800);
}
</script>
</body>
</html>