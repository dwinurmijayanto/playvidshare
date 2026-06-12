# VidShare — MP4 Player Generator
> play.vidshare.my.id

Generate shareable video player URL dari link MP4 manapun.

## 📁 Struktur File

```
vidshare/
├── index.php              ← Halaman utama (form input URL)
├── player.php             ← Halaman video player
├── router.php             ← URL dispatcher
├── config.php             ← Konfigurasi (BASE_URL, timezone)
├── .htaccess              ← Rewrite rules (Apache)
├── nginx.conf.example     ← Contoh config Nginx
├── includes/
│   ├── storage.php        ← JSON storage helper
│   └── helpers.php        ← Generate kode unik, base URL
└── data/
    └── videos.json        ← Database video (auto-dibuat)
```

## 🚀 Cara Deploy

### 1. Upload ke Server

Upload seluruh folder `vidshare/` ke root domain Anda.
Contoh: `/var/www/html/` atau `/home/user/public_html/`

### 2. Set Permission

```bash
chmod 755 data/
chmod 644 data/videos.json   # akan dibuat otomatis
```

### 3. Edit config.php

```php
define('BASE_URL', 'https://play.vidshare.my.id');
```

### 4. Apache (sudah ada .htaccess)

Pastikan `mod_rewrite` aktif:
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

Di `httpd.conf` atau VirtualHost, pastikan:
```apache
AllowOverride All
```

### 5. Nginx

Gunakan `nginx.conf.example` sebagai referensi config server block Anda.

---

## 🎯 Cara Pakai

1. Buka `https://play.vidshare.my.id`
2. Masukkan judul (opsional) dan URL MP4
3. Klik **Generate Player URL**
4. Copy URL yang dihasilkan — contoh: `https://play.vidshare.my.id/aB3xYz9K`
5. Share URL tersebut — video akan auto-play saat dibuka

---

## ⌨️ Keyboard Shortcuts (di halaman player)

| Tombol | Aksi |
|--------|------|
| `Space` / `K` | Play / Pause |
| `→` | Maju 10 detik |
| `←` | Mundur 10 detik |
| `↑` | Volume naik |
| `↓` | Volume turun |
| `M` | Mute/Unmute |
| `F` | Fullscreen |

---

## 🔧 Kebutuhan Server

- PHP 7.4+ (disarankan PHP 8.x)
- Apache dengan `mod_rewrite` ATAU Nginx
- Permission write ke folder `data/`
- HTTPS direkomendasikan untuk autoplay

---

## 📝 Catatan Autoplay

Browser modern memblokir autoplay dengan suara. Video akan:
1. Otomatis play dalam kondisi **muted**
2. Setelah 300ms, suara akan otomatis diaktifkan
3. Jika autoplay diblokir sepenuhnya, muncul tombol play overlay

---

## 🛡️ Keamanan

- Folder `data/` diproteksi dari akses langsung via `.htaccess`
- Input URL divalidasi sebelum disimpan
- Kode unik menggunakan `random_int()` (cryptographically secure)
- `htmlspecialchars()` digunakan pada semua output

---

Made with ⚡ VidShare
