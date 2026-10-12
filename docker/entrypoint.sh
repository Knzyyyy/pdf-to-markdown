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
#    config:cache akan GAGAL dengan pesan jelas bila APP_KEY belum diisi.
#    Itu memang perilaku yang diinginkan: lebih baik container gagal start
#    daripada berjalan dengan enkripsi cookie yang tidak valid.
# -----------------------------------------------------------------------------
gosu www-data php artisan config:cache --no-ansi
gosu www-data php artisan route:cache --no-ansi
gosu www-data php artisan view:cache --no-ansi

# Beri tahu bahwa rate limit memakai cache yang sama seperti storage.
gosu www-data php artisan about --only=environment 2>/dev/null || true

echo "[startup] apache start di port ${PORT}"

# exec supaya Apache menjadi PID 1 dan menerima signal stop dari Platform.
exec apache2-foreground