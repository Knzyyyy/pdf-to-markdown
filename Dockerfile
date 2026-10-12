# =============================================================================
#  PDF to Markdown Converter
#
#  Satu container, satu instance. SIAP untuk Render/Railway.
#
# Tiga tahap (multi-stage) supaya image akhir tetap kecil:
#
#    1. assets  -> membangun asset frontend dengan Node
#    2. vendor  -> memasang dependency PHP dengan Composer
#    3. final   -> PHP 8.3 + Apache, hanya berisi yang dibutuhkan runtime
#
#  CATATAN PENTING: Dockerfile ini BELUM pernah di-build di mesin saya karena
#  Docker tidak terpasang di lingkungan itu. Kalau ada yang gagal saat build,
#  pesan error-nya hampir selalu muncul di tahap "vendor".
# =============================================================================

# =============================================================================
# Tahap 1 — Build asset frontend (Vite)
# =============================================================================
FROM node:20-alpine AS assets

WORKDIR /app

# Hanya package.json + lock yang dicOPY dulu supaya layer cache tidak
# di-invalid setiap kali kode aplikasi berubah.
COPY package.json package-lock.json ./
RUN npm ci

# vite.config.js mendaftarkan dua entry point:
#   resources/css/app.css dan resources/js/app.js
COPY vite.config.js ./
COPY resources ./resources

# Hasilnya nanti ada di /app/public/build
RUN npm run build


# =============================================================================
# Tahap 2 — Dependency PHP
# =============================================================================
# Basis WAJIB disamakan dengan tahap 3 (php:8.3-*).
#
# Sebelumnya tahap ini memakai image composer:2, dan image itu memakai PHP
# newest yang tersedia. Saat PHP 8.5 rilis, CI gagal di tahap ini: paket
# nette/schema v1.3.2 di lock file hanya mendukung "8.1 - 8.4", sehingga
# composer install menolak lock tersebut.
#
# Verifikasi ulang dengan perintah Composer:
#   composer check-platform-reqs --lock   -> harus sukses di PHP 8.3
#   composer prohibits php 8.5 --locked   -> harus TIDAK menampilkan
#                                             nette/schema
#
# Binary Composer tetap diambil dari image composer:2 lewat COPY, jadi kita
# mendapat Composer versi terbaru tanpa mengikuti PHP-nya.
FROM php:8.3-cli AS vendor

WORKDIR /app

# Binary Composer disalin dari image composer:2. Binary itu berupa PHAR,
# jadi portabel dan bisa dijalankan di PHP Debian apa pun.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ext-zip wajib dipasang karena composer.json menyatakan "ext-zip": "*".
# Tanpa ini, composer install berhenti dengan "requires ext-zip * -> it is
# missing from your system". unzip dipakai sebagai cadangan Composer saat
# mengambil paket dist, git untuk skenario lain.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libzip-dev unzip git; \
    docker-php-ext-install -j"$(nproc)" zip; \
    rm -rf /var/lib/apt/lists/*

COPY composer.json composer.lock ./

# --no-scripts : package discovery ditunda ke tahap start, bukan build.
#                Alasannya APP_KEY dsb. baru tersedia saat container berjalan.
#
# PENTING: jangan pernah memakai --ignore-platform-req / --ignore-platform-reqs
# di sini. Flag itu membuat Composer berhenti memeriksa kecocokan versi PHP,
# dan akibatnya lock file yang tidak kompatibel bisa lolos ke image lalu
# gagal saat runtime dengan error yang jauh lebih sulit dibaca.
#
# Kalau someday composer install gagal karena platform, perbaikilah lock-nya
# dengan composer update --lock, bukan dengan mengabaikan pemeriksaan.
RUN composer install \
        --no-dev \
        --optimize-autoloader \
        --no-interaction \
        --no-scripts


# =============================================================================
# Tahap 3 — Image final (PHP 8.3 + Apache)
# =============================================================================
FROM php:8.3-apache

# Port default. Render/Railway mengirim nilainya lewat env PORT.
ENV PORT=8080

# Log Apache diarahkan ke stdout/stderr supaya Platform bisa membacanya.
# Env var ini dibaca oleh /etc/apache2/envvars (dipakai entrypoint).
ENV APACHE_LOG_DIR=/var/log/apache2

# -----------------------------------------------------------------------------
# Extension PHP
#
# Yang SUDAH ikut bawaan image resmi php:8.3-apache, jadi tidak perlu
# dipasang lagi: ctype, dom, fileinfo, filter, hash, iconv, json, libxml,
# mbstring, openssl, pcre, PDO, Phar, session, SimpleXML, sodium, SPL,
# tokenizer, xml, xmlreader, xmlwriter, zlib.
#
# Yang perlu dipasang sendiri, karena TIDAK ikut bawaan dan dibutuhkan
# aplikasi ini:
#   zip     -> features unduh ZIP, dan juga tercatat di composer.json
#   opcache -> penting untuk produksi, menambah ~20-30% kecepatan
# -----------------------------------------------------------------------------
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libzip-dev; \
    docker-php-ext-install -j"$(nproc)" zip opcache; \
    rm -rf /var/lib/apt/lists/*

# mod_rewrite wajib untuk pretty URL Laravel.
RUN a2enmod rewrite

# php.ini produksi: batas upload, memory, dan opcache.
COPY docker/php-production.ini /usr/local/etc/php/conf.d/zz-production.ini

# Apache: log ke stdout/stderr supaya Platform bisa Monitoring.
COPY docker/apache-app.conf /etc/apache2/conf-available/app-logs.conf
RUN a2enconf app-logs

WORKDIR /var/www/html

# Asset hasil build tahap 1.
COPY --from=assets /app/public/build ./public/build

# Dependency PHP hasil tahap 2.
COPY --from=vendor /app/vendor ./vendor

# Kode aplikasi.
# .env sengaja TIDAK ikut (sudah dikecualikan di .dockerignore).
# Seluruh konfigurasi dibaca dari environment variable Platform.
COPY artisan composer.json composer.lock ./
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./
COPY resources ./resources
COPY routes ./routes
COPY storage ./storage

# Folder storage/logs tidak ikut dari git (di-ignore), jadi dibuat di sini
# supaya Monolog tidak gagal menulis log.
RUN mkdir -p storage/framework/cache/data \
             storage/framework/sessions \
             storage/framework/views \
             storage/logs

# Apache berjalan sebagai root (master) + www-data (worker).
# File yang ditulis aplikasi harus milik www-data.
RUN chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

EXPOSE 8080

# Health check tanpa butuh curl atau paket tambahan.
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD php -r "exit(@fsockopen('127.0.0.1', (int) (getenv('PORT') ?: 8080)) ? 0 : 1);"

ENTRYPOINT ["app-entrypoint"]