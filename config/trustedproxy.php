<?php

/**
 * Konfigurasi trusted proxy.
 *
 * Kenapa harus lewat config, bukan env() langsung di middleware:
 *
 * 1. Laravel hanya mengizinkan env() dipanggil di dalam file config.
 *    Pemanggilan di tempat lain discouraged karena nilainya bisa hilang
 *    setelah config di-cache.
 *
 * 2. env() MEMBACA $_SERVER / $_ENV LEBIH DAHULU daripada getenv().
 *    Begitu .env ter-load, nilai yang tersimpan di $_ENV/_SERVER selalu
 *    menang atas putenv(). Efeknya: test yang memakai putenv() untuk
 *    menyimulasikan environment variable jadi tidak bisa dipercaya,
 *    dan hasilnya berbeda antara mesin lokal dan CI.
 *
 * 3. Dengan config, test bisa menimpa nilai secara langsung lewat
 *    config(['trustedproxy.proxies' => ...]) tanpa menyentuh environment.
 *
 * Nilai yang diterima untuk TRUSTED_PROXIES:
 *   (kosong / tidak diisi) -> null, artinya TIDAK ADA proxy yang dipercaya
 *   "*" atau "**"         -> percaya proxy yang memanggil (Render/Railway)
 *   "10.0.0.1,10.0.0.2"   -> daftar IP proxy yang dipercaya
 *
 * Nilai mentah diteruskan apa adanya; penerjemahannya dilakukan di
 * App\Http\Middleware\TrustProxies supaya aturan parsing-nya tinggal
 * di satu tempat dan mudah diuji.
 */

return [

    'proxies' => env('TRUSTED_PROXIES'),

];
