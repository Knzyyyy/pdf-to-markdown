#!/usr/bin/env sh
# =============================================================================
#  Startup script untuk PDF to Markdown Converter.
#
#  Dipanggil otomatis oleh ENTRYPOINT di Dockerfile.
#
#  Yang dilakukan di sini (dan BUKAN saat build):
#   1. Menyesuaikan port Apache dengan variabel PORT dari Platform
#   2. Menyesuaikan DocumentRoot ke folder public
#   3. Membersihkan cache lama, lalu membuat cache config dan route
#   4. Menjalankan Apache di depan
#
#  Kenapa cache dibuat saat start, bukan saat build:
#  cache config dan route MEMBLOKIR environment variable. Kalau dibuat
#  saat build, aplikasi akan memakai nilai env build (yang kosong) dan
#  mengabaikan APP_KEY / APP_URL / CACHE_STORE milik Platform.
# =============================================================================

set -eu

PORT="${PORT:-8080}"
DOCROOT="${APACHE_DOCUMENT_ROOT:-/var/www/html/public}"

# -----------------------------------------------------------------------------
# 0. Cek APP_KEY, gagal secepat mungkin.
#
#    Kenapa perlu dicek di sini, bukan mengandalkan artisan:
#    sudah diuji bahwa `php artisan config:cache` TIDAK gagal saat APP_KEY
#    kosong. Perintahnya tetap exit 0, key kosong ikut tersimpan ke cache,
#    lalu aplikasi berjalan dengan enkripsi cookie yang tidak valid. Gejalanya
#    baru muncul nanti: session tidak bisa dibaca dan pengguna selalu
#    terlihat "logout terus" tanpa pesan error yang jelas.
#
#    Jadi tanpa menebak, container sengaja dihentikan di sini.
#    APP_KEY hanya dibaca untuk dicek ada atau tidak; nilainya tidak pernah
#    dicetak ke log.
# -----------------------------------------------------------------------------
if [ -z "${APP_KEY:-}" ]; then
    {
        echo "[startup] ERROR: APP_KEY belum diisi."
        echo "[startup]"
        echo "[startup] Buat APP_KEY dengan:"
        echo "[startup]     php artisan key:generate --show"
        echo "[startup]"
        echo "[startup] Lalu jalankan ulang, contoh:"
        echo "[startup]     docker run -e APP_KEY=base64:xxxxxxxx... pdf-to-markdown"
        echo "[startup]"
        echo "[startup] Di Render/Railway, isi variabel APP_KEY di dashboard."
    } >&2

    exit 1
fi

echo "[startup] APP_KEY terbaca (${#APP_KEY} karakter)"
echo "[startup] port=${PORT} documentroot=${DOCROOT}"

# -----------------------------------------------------------------------------# 1. Apache harus membaca port dari environment variable.#    File konfigurasi Apache tidak bisa melakukan interpolasi ${PORT},#    jadi nilainya ditulis lewat sed setiap kali container start.##    Dua jebakan yang sudah dihindari di sini:##    a) Anchor "$" milik regex harus di-escape menjadi "\$". Tanpa escape,#       shell membaca "$!" sebagai variabel dan menghapusnya, sehingga#       ekspresi sed jadi tidak lengkap dan port TIDAKAKAN terganti.##    b) Semua perintah wajib idempoten, karena entrypoint ini berjalan lagi#       setiap container start ulang. Kalau tidak, DocumentRoot akan#       menjadi /var/www/html/public/public pada start kedua.# -----------------------------------------------------------------------------sed -ri "s!^Listen[[:space:]]*[0-9]+\$!Listen ${PORT}!" /etc/apache2/ports.conf
sed -ri "s!<VirtualHost[[:space:]]+\*:[0-9]+>!<VirtualHost *:${PORT}>!g" \
    /etc/apache2/sites-available/*.conf

# DocumentRoot diarahkan ke folder public.
#
# Perhatikan polanya: "/var/www/html" harus DIKUTI akhir baris (atau spasi),
# bukan "/public". Kalau polanya longgar, start kedua akan mengubah
# /var/www/html/public menjadi /var/www/html/public/public.
#
# Bentuk aslinya di image php:apache adalah "DocumentRoot /var/www/html"
# di akhir baris TANPA tanda ">", jadi pola wajib menerima akhir baris.
sed -ri "s#(DocumentRoot[[:space:]]+)/var/www/html([[:space:]]*\$)#\1${DOCROOT}\2#" \
    /etc/apache2/sites-available/*.conf

# Blok <Directory> selalu ditutup dengan ">", jadi polanya bisa lebih sederhana.
sed -ri "s#(<Directory[[:space:]]+)/var/www/html[[:space:]]*>#\1${DOCROOT}>#" \
    /etc/apache2/sites-available/*.conf

# -----------------------------------------------------------------------------
# 2. Pastikan folder yang ditulis aplikasi ada dan bisa ditulis www-data.
#    Folder ini tidak ikut dari git karena isinya di-ignore.
# -----------------------------------------------------------------------------
mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache

# -----------------------------------------------------------------------------
# 2b. session.cookie_secure mengikuti environment variable.
#
#     php.ini tidak bisa membaca env var secara langsung, jadi nilainya
#     ditulis ke file .ini terpisah. File ini berada di conf.d, sehingga
#     otomatis terbaca oleh PHP tanpa perlu restart.
#
#     1 -> hanya HTTPS. Benar untuk Render/Railway.
#     0 -> izinkan HTTP. WAJIB untuk smoke test lewat http://localhost,
#          kalau tidak cookie session tidak akan pernah terkirim.
#
#     Nilainya divalidasi ketat supaya hanya "0" atau "1" yang diterima.
#     Nilai lain diabaikan supaya konfigurasi salah tidak diam-diam membuat
#     cookie berjalan tanpa proteksi.
# -----------------------------------------------------------------------------
SESSION_SECURE_COOKIE="${SESSION_SECURE_COOKIE:-1}"

case "$SESSION_SECURE_COOKIE" in
    0|1) ;;
    *)
        echo "[startup] PERINGATAN: SESSION_SECURE_COOKIE=\"${SESSION_SECURE_COOKIE}\" tidak valid." >&2
        echo "[startup] Nilai yang dipakai: 1 (hanya HTTPS)." >&2
        SESSION_SECURE_COOKIE=1
        ;;
esac

printf 'session.cookie_secure = %s\n' "$SESSION_SECURE_COOKIE" \
    > /usr/local/etc/php/conf.d/zz-session-secure.ini

echo "[startup] session.cookie_secure=${SESSION_SECURE_COOKIE}"

# -----------------------------------------------------------------------------
# 3. Cache lama dibuang dulu.
#    Kalau tidak, hasil build lama masih dipakai dan env baru diabaikan.
# -----------------------------------------------------------------------------
gosu www-data php artisan config:clear --no-ansi || true
gosu www-data php artisan route:clear --no-ansi || true
gosu www-data php artisan view:clear --no-ansi || true

# Cache dari build sebelumnya tidak boleh ikut ke image, jadi hapus juga
# artefak yang mungkin tertinggal di bootstrap/cache.
rm -f bootstrap/cache/config.php bootstrap/cache/routes-*.php

# package:discover wajib jalan karena tahap "vendor" memakai --no-scripts.
# package:discover harus lebih dulu, sebelum config:cache.
gosu www-data php artisan package:discover --ansi --no-interaction

# -----------------------------------------------------------------------------
# 4. Cache untuk produksi.
#
#    CATATAN: config:cache sendiri TIDAK gagal ketika APP_KEY kosong. Itu
#    sudah diuji: perintahnya tetap exit 0 dan key kosong ikut tersimpan ke
#    cache. Kegagalan datang dari pengecekan APP_KEY di bagian 0 di atas.
#
#    Karena itu pengecekan itu harus jadi, bukan sekadar andal belaka.
# -----------------------------------------------------------------------------
gosu www-data php artisan config:cache --no-ansi
gosu www-data php artisan route:cache --no-ansi
gosu www-data php artisan view:cache --no-ansi

# Beri tahu bahwa rate limit memakai cache yang sama seperti storage.
gosu www-data php artisan about --only=environment 2>/dev/null || true

echo "[startup] apache start di port ${PORT}"

# exec supaya Apache menjadi PID 1 dan menerima signal stop dari Platform.
exec apache2-foreground