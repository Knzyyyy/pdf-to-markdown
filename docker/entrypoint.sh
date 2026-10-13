#!/usr/bin/env sh
# =============================================================================
#  Startup script untuk PDF to Markdown Converter.
#
#  Dipanggil otomatis sebagai perintah default (CMD) di Dockerfile.
#
#  ENTRYPOINT bawaan image (docker-php-entrypoint) sengaja tidak ditimpa,
#  sehingga perintah lain tetap bisa jalan:
#      docker run <image> php artisan --version
#      docker run <image> bash
#
#  Yang dilakukan di sini (dan BUKAN saat build):
#   0.  Preflight: cek APP_KEY, cek runuser, cek environment diteruskan
#   1.  Sesuaikan port Apache dengan variabel PORT dari Platform
#   2.  Arahkan DocumentRoot ke folder public
#   3.  Bersihkan cache lama
#   4.  Bangun cache config, route, dan view
#   5.  Pastikan storage dan bootstrap/cache milik www-data
#   6.  Jalankan Apache di depan
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
# 0. APP_KEY wajib ada. Dicek paling awal supaya container berhenti sebelum
#    melakukan pekerjaan apa pun.
#
#    Penting: `php artisan config:cache` sendiri TIDAK gagal saat APP_KEY
#    kosong. Perintahnya tetap exit 0, key kosong ikut tersimpan ke cache,
#    lalu aplikasi berjalan dengan enkripsi cookie yang tidak valid. Gejalanya
#    baru muncul belakangan: session tidak terbaca dan pengguna selalu
#    terlihat "logout terus" tanpa pesan error.
#
#    Nilai APP_KEY hanya dibaca untuk dicek ada atau tidak; tidak pernah
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

# -----------------------------------------------------------------------------
# 0b. Pastikan runuser tersedia.
#
#     runuser dipakai untuk menjalankan perintah artisan sebagai www-data.
#
#     runuser berasal dari paket util-linux, yang berstatus ESSENTIAL di Debian,
#     dan image ini berbasis debian:bookworm-slim. Jadi runuser sudah ada
#     tanpa perlu memasang paket apa pun.
#
#     Dulu script ini memakai gosu, yang TIDAK ada di image resmi php:apache.
#     Akibatnya container langsung mati dengan "gosu: not found" sebelum
#     Apache sempat start, dan yang terlihat di CI hanya "connection refused".
#
#     Pemeriksaan di sini tetap dijaga: kalau suatu saat image dasar berubah,
#     kegagalannya muncul sebagai pesan jelas, bukan container mati bisu.
# -----------------------------------------------------------------------------
if ! command -v runuser > /dev/null 2>&1; then
    echo "[startup] ERROR: perintah runuser tidak ditemukan di image ini." >&2
    echo "[startup] runuser ikut paket util-linux yang essential di Debian." >&2
    exit 1
fi

# -----------------------------------------------------------------------------
# 0c. Pastikan APP_KEY benar-benar diteruskan ke proses anak.
#
#     runuser (seperti su) me-reset sebagian environment: HOME, SHELL, USER,
#     LOGNAME, dan PATH. Variabel lain seperti APP_KEY seharusnya ikut, tapi
#     itu asumsi. Baris ini mengubah asumsi menjadi fakta yang diperiksa.
# -----------------------------------------------------------------------------
if ! runuser -u www-data -- sh -c 'test -n "$APP_KEY"'; then
    echo "[startup] ERROR: APP_KEY tidak diteruskan ke proses www-data." >&2
    echo "[startup] Session cookie tidak akan bisa dienkripsi." >&2
    exit 1
fi

echo "[startup] runuser tersedia, APP_KEY diteruskan ke www-data"

echo "[startup] port=${PORT} documentroot=${DOCROOT}"

# -----------------------------------------------------------------------------
# 1. Apache harus membaca port dari environment variable.
#    File konfigurasi Apache tidak bisa melakukan interpolasi ${PORT},
#    jadi nilainya ditulis lewat sed setiap kali container start.
#
#    Dua jebakan yang sudah dihindari di sini:
#
#    a) Anchor "$" milik regex harus di-escape menjadi "\$". Tanpa escape,
#       shell membaca "$!" sebagai variabel dan menghapusnya, sehingga
#       ekspresi sed jadi tidak lengkap dan port TIDAK AKAN terganti.
#
#    b) Semua perintah wajib idempoten, karena entrypoint ini berjalan lagi
#       setiap container start ulang. Kalau tidak, DocumentRoot akan
#       menjadi /var/www/html/public/public pada start kedua.
# -----------------------------------------------------------------------------
sed -ri "s!^Listen[[:space:]]*[0-9]+\$!Listen ${PORT}!" /etc/apache2/ports.conf
sed -ri "s!<VirtualHost[[:space:]]+\*:[0-9]+>!<VirtualHost *:${PORT}>!g" \
    /etc/apache2/sites-available/*.conf

# DocumentRoot diarahkan ke folder public.
#
# Polanya hanya cocok kalau path "/var/www/html" diikuti akhir baris (atau
# spasi), bukan "/public". Kalau polanya longgar, start kedua akan mengubah
# /var/www/html/public menjadi /var/www/html/public/public.
#
# Bentuk aslinya di image php:apache adalah "DocumentRoot /var/www/html"
# di akhir baris TANPA tanda ">", jadi pola wajib menerima akhir baris.
sed -ri "s#(DocumentRoot[[:space:]]+)/var/www/html([[:space:]]*\$)#\1${DOCROOT}\2#" \
    /etc/apache2/sites-available/*.conf

# Blok <Directory> selalu ditutup dengan ">", jadi polanya lebih sederhana.
sed -ri "s#(<Directory[[:space:]]+)/var/www/html[[:space:]]*>#\1${DOCROOT}>#" \
    /etc/apache2/sites-available/*.conf

# -----------------------------------------------------------------------------
# 2. Folder yang ditulis aplikasi harus ada dan bisa ditulis www-data.
#    Folder ini tidak ikut dari git karena isinya di-ignore.
# -----------------------------------------------------------------------------
mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache

# session.cookie_secure mengikuti environment variable. Nilai "1" hanya
# mengirim cookie lewat HTTPS (benar untuk Render/Railway), "0" diperlukan
# saat menguji lewat http://localhost. Nilai lain diabaikan dan diganti 1,
# supaya konfigurasi salah tidak diam-diam membuat cookie tanpa proteksi.
SESSION_SECURE_COOKIE="${SESSION_SECURE_COOKIE:-1}"

case "$SESSION_SECURE_COOKIE" in
    0|1) ;;
    *)
        echo "[startup] PERINGATAN: SESSION_SECURE_COOKIE=\"${SESSION_SECURE_COOKIE}\" tidak valid." >&2
        echo "[startup] Nilai yang dipakai: 1 (hanya HTTPS)." >&2
        SESSION_SECURE_COOKIE=1
        ;;
esac

# php.ini tidak bisa membaca env var, jadi nilainya ditulis ke file .ini
# terpisah yang otomatis terbaca dari conf.d.
printf 'session.cookie_secure = %s\n' "$SESSION_SECURE_COOKIE" \
    > /usr/local/etc/php/conf.d/zz-session-secure.ini

echo "[startup] session.cookie_secure=${SESSION_SECURE_COOKIE}"

# -----------------------------------------------------------------------------
# 3. Cache lama dibuang dulu. Kalau tidak, hasil build lama masih dipakai
#    dan env baru diabaikan. Perintah "*:clear" boleh gagal karena cache
#    memang belum ada.
# -----------------------------------------------------------------------------
echo "[startup] membersihkan cache lama (config/route/view)"
runuser -u www-data -- php artisan config:clear --no-ansi || true
runuser -u www-data -- php artisan route:clear --no-ansi || true
runuser -u www-data -- php artisan view:clear --no-ansi || true

# Cache dari build sebelumnya tidak boleh ikut ke image.
rm -f bootstrap/cache/config.php bootstrap/cache/routes-*.php

# -----------------------------------------------------------------------------
# 4. Cache untuk produksi.
#
#    Tahap vendor memakai --no-scripts, jadi package:discover WAJIB jalan.
#
#    CATATAN: config:cache sendiri TIDAK gagal ketika APP_KEY kosong, itu
#    sudah diuji. Pengecekan di langkah 0 yang menjadi penjaganya.
#
#    Tidak satu pun perintah di bawah memakai "|| true". Kegagalan HARUS
#    menghentikan start, dan pesan langkah di atasnya membuat docker logs
#    menunjukkan di bagian mana start berhenti. Konfigurasi yang gagal
#    di-cache lebih berbahaya daripada container yang gagal start.
# -----------------------------------------------------------------------------
echo "[startup] 1/4 package:discover"
runuser -u www-data -- php artisan package:discover --ansi --no-interaction

echo "[startup] 2/4 config:cache"
runuser -u www-data -- php artisan config:cache --no-ansi

echo "[startup] 3/4 route:cache"
runuser -u www-data -- php artisan route:cache --no-ansi

echo "[startup] 4/4 view:cache"
runuser -u www-data -- php artisan view:cache --no-ansi

# -----------------------------------------------------------------------------
# 5. Berkas cache tadi dibuat oleh proses www-data, tapi chown ulang
#    dijamin agar Apache (worker-nya berjalan sebagai www-data) bisa menulis
#    tanpa masalah, termasuk berkas yang dibuat oleh langkah lain.
# -----------------------------------------------------------------------------
echo "[startup] memastikan storage dan bootstrap/cache milik www-data"
chown -R www-data:www-data storage bootstrap/cache

echo "[startup] ringkasan environment"
runuser -u www-data -- php artisan about --only=environment 2>/dev/null || true

echo "[startup] apache start di port ${PORT}"

# exec supaya Apache menjadi PID 1 dan menerima signal stop dari Platform.
exec apache2-foreground