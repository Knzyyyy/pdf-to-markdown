<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Test perilaku trusted proxy di level HTTP.
 *
 * Focus: memastikan X-Forwarded-For dari client TIDAK dipercaya saat
 * trustedproxy.proxies kosong, dan DIPERCAYA saat diisi "*".
 *
 * Ini yang relevan: kalau salah, rate limit 10/menit jadi tidak berguna
 * karena semua request akan terhitung berasal dari satu IP.
 *
 * Test ini memakai config(), BUKAN putenv(). Alasannya dijelaskan di
 * TrustProxiesTest: env() membaca $_ENV/_SERVER lebih dulu daripada
 * getenv(), sehingga nilai dari .env selalu menang atas putenv() dan hasil
 * testnya berbeda antara mesin lokal dan CI.
 */
class TrustedProxyBehaviourTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Route sementara untuk melihat alamat IP yang dibaca Request.
        Route::middleware('web')->get('/__ip-probe', function (Request $request) {
            return response()->json(['ip' => $request->ip()]);
        });
    }

    protected function tearDown(): void
    {
        // Kembalikan ke nilai aslinya agar tidak bocor ke test lain.
        config(['trustedproxy.proxies' => env('TRUSTED_PROXIES')]);

        parent::tearDown();
    }

    public function test_x_forwarded_for_diabaikan_secara_default(): void
    {
        config(['trustedproxy.proxies' => '']);

        $response = $this->get('/__ip-probe', [
            'X-Forwarded-For' => '203.0.113.5',
            'REMOTE_ADDR' => '198.51.100.7',
        ]);

        $response->assertOk();

        // Client pengirim IP palsu TIDAK boleh terbaca.
        $this->assertNotSame('203.0.113.5', $response->json('ip'));
    }

    public function test_x_forwarded_for_diabaikan_kalaunya_null(): void
    {
        config(['trustedproxy.proxies' => null]);

        $response = $this->get('/__ip-probe', [
            'X-Forwarded-For' => '203.0.113.5',
            'REMOTE_ADDR' => '198.51.100.7',
        ]);

        $response->assertOk();
        $this->assertNotSame('203.0.113.5', $response->json('ip'));
    }

    public function test_x_forwarded_for_dipercaya_kalauproxy_ditandai(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $response = $this->get('/__ip-probe', [
            'X-Forwarded-For' => '203.0.113.5',
            'REMOTE_ADDR' => '198.51.100.7',
        ]);

        $response->assertOk();

        // Dengan nilai "*" (setup Render/Railway), IP asli terbaca.
        $this->assertSame('203.0.113.5', $response->json('ip'));
    }

    public function test_daftar_ip_proxy_khusus_dipercayai(): void
    {
        // Hanya proxy pengirim (198.51.100.7) yang dipercaya.
        config(['trustedproxy.proxies' => '198.51.100.7']);

        $response = $this->get('/__ip-probe', [
            'X-Forwarded-For' => '203.0.113.5',
            'REMOTE_ADDR' => '198.51.100.7',
        ]);

        $response->assertOk();
        $this->assertSame('203.0.113.5', $response->json('ip'));
    }

    public function test_proxy_lain_tidak_dipercayai(): void
    {
        // Proxy yang mengirim request bukan 198.51.0.1, jadi tidak dipercaya.
        config(['trustedproxy.proxies' => '198.51.100.7']);

        $response = $this->get('/__ip-probe', [
            'X-Forwarded-For' => '203.0.113.5',
            'REMOTE_ADDR' => '10.0.0.9',
        ]);

        $response->assertOk();
        $this->assertNotSame('203.0.113.5', $response->json('ip'));
    }

    public function test_tanpa_x_forwarded_for_tidak_berubah(): void
    {
        config(['trustedproxy.proxies' => '']);

        $response = $this->get('/__ip-probe', ['REMOTE_ADDR' => '198.51.100.7']);

        $response->assertOk();
        $response->assertJson(['ip' => '198.51.100.7']);
    }

    // =========================================================
    // Docker: langkah startup harus benar-benar bisa jalan
    // =========================================================

    /**
     * Entry container menjalankan `php artisan route:cache`.
     * Route yang memakai closure TIDAK bisa di-cache sebelum Laravel 9,
     * jadi test ini menangkap regresi yang akan menggagalkan deploy.
     */
    public function test_route_bisa_di_cache(): void
    {
        $cacheFile = \base_path('bootstrap/cache/routes-v7.php');

        // Pastikan tidak ada sisa cache dari percobaan sebelumnya.
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }

        try {
            $this->artisan('route:cache')->assertSuccessful();

            $this->assertFileExists($cacheFile, 'File cache route harus terbentuk');

            // Route aplikasi harus tetap terdaftar setelah cache aktif.
            $this->assertSame(
                'pdf-to-markdown',
                app('router')->getRoutes()->getByName('pdf-to-markdown')?->uri()
            );
        } finally {
            // Selalu bersihkan: file cache tidak boleh tersisa setelah test.
            $this->artisan('route:clear')->run();
        }
    }

    /**
     * Entry container juga menjalankan `php artisan config:cache`.
     * Cache ini memblokir env var, jadi harus dibangun ulang tiap restart.
     */
    public function test_config_bisa_di_cache(): void
    {
        $cacheFile = \base_path('bootstrap/cache/config.php');

        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }

        try {
            $this->artisan('config:cache')->assertSuccessful();

            $this->assertFileExists($cacheFile);

            // Nilai config hasil cache harus tetap ada, termasuk proxies.
            $cached = require $cacheFile;

            $this->assertArrayHasKey('trustedproxy', $cached);
            $this->assertArrayHasKey('proxies', $cached['trustedproxy']);
        } finally {
            $this->artisan('config:clear')->run();
        }
    }

    public function test_bootstrap_cache_bisa_ditulis(): void
    {
        // Kalau folder ini tidak writable, container gagal saat start.
        $this->assertDirectoryIsWritable(
            \base_path('bootstrap/cache'),
            'bootstrap/cache harus bisa ditulis, karena config:cache menulis ke sana'
        );

        $this->assertDirectoryIsWritable(
            \storage_path('framework/cache/data'),
            'Folder cache file harus bisa ditulis oleh www-data'
        );
    }

    /**
     * Penjaga: test suite TIDAK boleh dijalankan saat config masih ter-cache.
     *
     * Ini bukan masalah yang berasal dari kode ini, tapi mudah terjadi.
     * Saat config ter-cache, nilai override dari phpunit.xml (CACHE_STORE=array,
     * SESSION_DRIVER=array) diabaikan, sehingga test berjalan memakai cache dan
     * session file sungguhan. Gejalanya: puluhan test gagal dengan 419
     * (CSRF) dan "null is of type array", yang sama sekali tidak obviously
     * berkaitan dengan cache.
     *
     * Pesannya ditulis eksplisit supaya tidak spends waktu mencari
     * penyebab yang salah.
     */
    public function test_config_tidak_boleh_ter_cache_saat_menjalankan_test(): void
    {
        if (! app()->configurationIsCached()) {
            $this->assertTrue(true, 'Config tidak ter-cache, test environment benar.');

            return;
        }

        $this->fail(
            'Config sedang ter-cache, sehingga override dari phpunit.xml '
            .'(CACHE_STORE=array, SESSION_DRIVER=array) diabaikan dan mayoritas '
            .'test akan gagal dengan error yang menyesatkan (419, null is of type array). '
            .'Jalankan dulu: php artisan config:clear && php artisan route:clear'
        );
    }

    /**
     * Berkas pendukung Docker harus ada dan isinya masuk akal.
     */
    public function test_berkas_docker_melonim_bukan_sia_sia(): void
    {
        $root = \base_path();

        $required = [
            'Dockerfile',
            '.dockerignore',
            'docker/entrypoint.sh',
            'docker/php-production.ini',
            'docker/apache-app.conf',
        ];

        foreach ($required as $file) {
            $this->assertFileExists($root.'/'.$file, "Berkas Docker wajib ada: {$file}");
        }

        $dockerfile = (string) file_get_contents($root.'/Dockerfile');

        // ext-zip wajib ada karena tercatat di composer.json.
        $this->assertStringContainsString('zip', $dockerfile);

        // .env tidak boleh ikut ke dalam image.
        $dockerignore = (string) file_get_contents($root.'/.dockerignore');
        $this->assertMatchesRegularExpression('/^\.env$/m', $dockerignore);
    }

    /**
     * Tahap "vendor" harus memakai PHP yang sama dengan tahap final.
     *
     * Latitude ini pernah jadi sumber kegagalan: tahap vendor memakai image
     * composer:2 yang PHP-nya ikut naik ke 8.5, sementara paket di lock file
     * (nette/schema v1.3.2) hanya mendukung "8.1 - 8.4". Akibatnya
     * composer install menolak lock tersebut.
     */
    public function test_tahap_vendor_memakai_php_yang_sama_dengan_tahap_final(): void
    {
        $dockerfile = (string) file_get_contents(\base_path('Dockerfile'));

        $this->assertMatchesRegularExpression(
            '/^FROM php:8\.3-cli AS vendor$/m',
            $dockerfile,
            'Tahap vendor harus memakai php:8.3-cli'
        );

        $this->assertMatchesRegularExpression(
            '/^FROM php:8\.3-apache$/m',
            $dockerfile,
            'Tahap final harus memakai php:8.3-apache'
        );

        // Binary Composer tetap diambil dari image composer:2.
        $this->assertStringContainsString(
            'COPY --from=composer:2 /usr/bin/composer /usr/bin/composer',
            $dockerfile,
            'Binary composer harus disalin dari image composer:2'
        );

        // composer:2 tidak boleh lagi dipakai sebagai basis tahap.
        $this->assertDoesNotMatchRegularExpression(
            '/^FROM composer:/m',
            $dockerfile,
            'composer:2 hanya boleh dipakai lewat COPY --from, bukan sebagai FROM'
        );
    }

    /**
     * ext-zip harus dipasang di tahap vendor juga, bukan hanya di tahap final.
     *
     * composer.json menyatakan "ext-zip": "*", jadi tanpa extension ini
     * composer install berhenti dengan:
     *   "requires ext-zip * -> the requested PHP extension zip is missing"
     */
    public function test_tahap_vendor_memasang_ext_zip(): void
    {
        $dockerfile = (string) file_get_contents(\base_path('Dockerfile'));

        // Ambil isi tahap vendor saja.
        $this->assertSame(1, preg_match('/FROM php:8\.3-cli AS vendor\n(.*?)(?=\n# ={10,}|\Z)/s', $dockerfile, $matches));

        $vendorStage = $matches[1] ?? '';

        $this->assertStringContainsString('libzip-dev', $vendorStage);
        $this->assertStringContainsString('docker-php-ext-install', $vendorStage);
        $this->assertStringContainsString('zip', $vendorStage);
    }

    /**
     * Dilarang memakai --ignore-platform-req di tahap vendor.
     *
     * Flag itu mematikan pemeriksaan kecocokan PHP, sehingga lock file yang
     * tidak kompatibel bisa lolos ke image lalu gagal saat runtime dengan
     * pesan yang jauh lebih sulit dibaca.
     */
    public function test_dilarang_memakai_ignore_platform_req(): void
    {
        $dockerfile = (string) file_get_contents(\base_path('Dockerfile'));

        // Buang komentar dulu, supaya peringatan di dalam komentar tidak
        // ikut terperiksa.
        $commands = preg_replace('/#.*$/m', '', $dockerfile) ?? $dockerfile;

        $this->assertDoesNotMatchRegularExpression(
            '/--ignore-platform-reqs?(?![\w-])/',
            $commands,
            'Dockerfile tidak boleh memakai --ignore-platform-req'
        );

        // Perintah composer install harus tetap lengkap.
        $this->assertStringContainsString(
            '--no-dev',
            $dockerfile,
        );
        $this->assertStringContainsString('--optimize-autoloader', $dockerfile);
        $this->assertStringContainsString('--no-interaction', $dockerfile);
        $this->assertStringContainsString('--no-scripts', $dockerfile);
    }

    /**
     * Semua base image harus ter-pin.
     *
     * Tag "latest" atau major yang mengambang (mis. "php:latest") bisa
     * berubah tanpa jejak, dan_BUILD ini akan gagal atau berubah hasil
     * tanpa ada yang mengubah berkas di repository.
     */
    public function test_semua_base_image_ter_pin(): void
    {
        $dockerfile = (string) file_get_contents(\base_path('Dockerfile'));

        preg_match_all('/^FROM\s+(\S+)/m', $dockerfile, $matches);

        $images = $matches[1] ?? [];

        $this->assertCount(3, $images, 'Harus ada tepat 3 tahap build');

        foreach ($images as $image) {
            // Tidak boleh memakai tag "latest".
            $this->assertDoesNotMatchRegularExpression(
                '/:latest$/',
                $image,
                "Base image memakai tag latest: {$image}"
            );

            // Setiap tag harus menyebut versi secara eksplisit.
            // "php:8.3-cli" dan "node:20-alpine" lolos,
            // "php" atau "php:8.3-fpm-bookworm-latest" tidak.
            $this->assertMatchesRegularExpression(
                '/^[a-z0-9.\-]+:[a-z0-9.\-]+/',
                $image,
                "Base image tidak menyebut versi secara eksplisit: {$image}"
            );

            // Image PHP harus di 8.3, sama dengan composer.json.
            if (str_starts_with($image, 'php:')) {
                $this->assertStringStartsWith(
                    'php:8.3',
                    $image,
                    "Base image PHP harus ter-pin ke 8.3: {$image}"
                );
            }
        }

        // Node tetap di major 20.
        $this->assertContains('node:20-alpine', $images);

        // Tidak boleh ada FROM yang memakai major mengambang tanpa minor,
        // misalnya "php:8-apache".
        foreach ($images as $image) {
            if (str_starts_with($image, 'php:')) {
                $this->assertDoesNotMatchRegularExpression(
                    '/^php:8-(?!3\b)/',
                    $image,
                    "Base image PHP harus menyebut minor, bukan hanya major: {$image}"
                );
            }
        }
    }

    /**
     * session.cookie_secure harus bisa diatur lewat environment variable.
     *
     * Nilai ini tidak bisa ditulis di php.ini karena php.ini tidak bisa membaca
     * environment variable, jadi entrypoint yang menulisnya. Test ini
     * menjaga supaya mekanisme itu tidak hilang diam-diam.
     */
    public function test_session_secure_cookie_diatur_lewat_environment(): void
    {
        $root = \base_path();

        $ini = (string) file_get_contents($root.'/docker/php-production.ini');
        $entrypoint = (string) file_get_contents($root.'/docker/entrypoint.sh');

        // Di php.ini harus TIDAK ada nilai yang dipaksa.
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*session\.cookie_secure\s*=/m',
            preg_replace('/^\s*;.*$/m', '', $ini) ?? $ini,
            'session.cookie_secure tidak boleh dikunci di php.ini karena perlu mengikuti env'
        );

        // Entrypoint harus membacanya dari environment.
        $this->assertStringContainsString('SESSION_SECURE_COOKIE', $entrypoint);
        $this->assertMatchesRegularExpression(
            '/SESSION_SECURE_COOKIE="\$\{SESSION_SECURE_COOKIE:-1\}"/',
            $entrypoint,
            'Default SESSION_SECURE_COOKIE harus 1 (hanya HTTPS)'
        );

        // Hanya 0 dan 1 yang diterima, selain itu fallback ke 1.
        $this->assertMatchesRegularExpression(
            '/case\s+"\$SESSION_SECURE_COOKIE" in\s*\n\s*0\|1\)/',
            $entrypoint,
            'Nilai SESSION_SECURE_COOKIE harus divalidasi ketat'
        );

        // Hasilnya ditulis ke file .ini supaya PHP membacanya.
        $this->assertStringContainsString('session.cookie_secure', $entrypoint);
        $this->assertStringContainsString('conf.d', $entrypoint);
    }

    public function test_env_produksi_terdokumentasi_di_env_example(): void
    {
        $envExample = (string) file_get_contents(\base_path('.env.example'));

        foreach ([
            'TRUSTED_PROXIES',
            'SESSION_SECURE_COOKIE',
            'APP_ENV',
            'APP_DEBUG',
            'APP_KEY',
            'APP_URL',
            'CACHE_STORE',
            'SESSION_DRIVER',
        ] as $key) {
            $this->assertStringContainsString(
                $key.'=',
                $envExample,
                "Variabel wajib untuk produksi belum ada di .env.example: {$key}"
            );
        }
    }

    /**
     * entrypoint harus menolak start bila APP_KEY kosong.
     *
     * Penting karena `php artisan config:cache` TIDAK gagal tanpa APP_KEY.
     * Perintahnya tetap exit 0, key kosong ikut tersimpan ke cache, lalu
     * aplikasi berjalan dengan enkripsi cookie yang tidak valid. Gejalanya
     * baru muncul belakangan dan tidak jelas: session tidak terbaca sehingga
     * pengguna selalu terlihat "logout terus".
     *
     * Karena itu gerbang ini harus ada dan harus menolak nilai kosong, bukan
     * hanya "tidak diset".
     */
    public function test_entrypoint_menolak_jika_app_key_kosong(): void
    {
        $entrypoint = (string) file_get_contents(\base_path('docker/entrypoint.sh'));

        // Gerbang harus mengecek variabel dengan default kosong (${APP_KEY:-}),
        // supaya tidak trigger error "unbound variable" dari `set -u`.
        $this->assertMatchesRegularExpression(
            '/if\s+\[\s*-z\s+"\$\{APP_KEY:-\}"/',
            $entrypoint,
            'entrypoint harus mengecek APP_KEY dengan pola ${APP_KEY:-}'
        );

        // Harus keluar dengan status bukan nol.
        $this->assertMatchesRegularExpression(
            '/APP_KEY belum diisi.*?exit 1/s',
            $entrypoint,
            'entrypoint harus exit 1 saat APP_KEY kosong'
        );

        // Pesan harus ditulis ke stderr dan menyebut cara memperbaikinya.
        $this->assertStringContainsString('>&2', $entrypoint);
        $this->assertStringContainsString('key:generate --show', $entrypoint);
    }

    /**
     * Pengecekan APP_KEY harus terjadi SEBELUM perintah berat apa pun.
     *
     * Kalau diletakkan setelah config:cache, container sudah melakukan pekerjaan
     * yang tidak perlu hanya untuk gagal.
     */
    public function test_cek_app_key_terjadi_sebelum_perintah_berat(): void
    {
        $entrypoint = (string) file_get_contents(\base_path('docker/entrypoint.sh'));

        $gatePosition = strpos($entrypoint, 'APP_KEY belum diisi');
        $configCache = strpos($entrypoint, 'config:cache --no-ansi');

        $this->assertIsInt($gatePosition, 'Gerbang APP_KEY tidak ditemukan');
        $this->assertIsInt($configCache, 'Perintah config:cache tidak ditemukan');

        $this->assertLessThan(
            $configCache,
            $gatePosition,
            'Gerbang APP_KEY harus dijalankan sebelum config:cache'
        );

        // bridge: apache2-foreground tetap harus dipanggil lewat exec supaya
        // menjadi PID 1 dan menerima signal stop dari Platform.
        $this->assertMatchesRegularExpression(
            '/^exec apache2-foreground$/m',
            $entrypoint,
            'apache2-foreground harus dijalankan lewat exec'
        );
    }

    /**
     * Nilai APP_KEY tidak boleh dicetak ke log.
     *
     * Yang BOLEH: panjang karakter (${#APP_KEY}) dan penyebutan kata "APP_KEY"
     * dalam pesan. Yang TIDAK boleh: nilai aslinya, ditulis dengan $APP_KEY atau
     * ${APP_KEY}, di dalam perintah echo.
     */
    public function test_nilai_app_key_tidak_ditampilkan_di_log(): void
    {
        $entrypoint = (string) file_get_contents(\base_path('docker/entrypoint.sh'));

        $commands = preg_replace('/^\s*#.*$/m', '', $entrypoint) ?? $entrypoint;

        foreach (explode("\n", $commands) as $line) {
            if (! str_starts_with(trim($line), 'echo ')) {
                continue;
            }

            // Panjang karakter boleh, nilai aslinya tidak.
            $withoutLength = str_replace('${#APP_KEY}', '', $line);

            $this->assertStringNotContainsString(
                '$APP_KEY',
                $withoutLength,
                "Nilai APP_KEY boleh dicetak di baris: {$line}"
            );
            $this->assertStringNotContainsString(
                '${APP_KEY}',
                $withoutLength,
                "Nilai APP_KEY boleh dicetak di baris: {$line}"
            );
        }

        // Bentuk lama yang salah harus tetap dilarang.
        $this->assertStringNotContainsString('APP_KEY=${APP_KEY}', $entrypoint);
    }

    /**
     * Komentar dan dokumentasi harus sesuai perilaku sebenarnya.
     *
     * Klaim lama menyebut "config:cache akan GAGAL bila APP_KEY belum diisi".
     * Itu sudah diuji salah: perintahnya exit 0. Kalau klaim salah sampai ke
     * README, orang akan salah menduga penyebab masalah saat debugging.
     */
    public function test_dokumentasi_tidak_berbohong_tentang_app_key(): void
    {
        $readme = (string) file_get_contents(\base_path('README.md'));
        $envExample = (string) file_get_contents(\base_path('.env.example'));

        foreach (['README.md' => $readme, '.env.example' => $envExample] as $name => $content) {
            $this->assertDoesNotMatchRegularExpression(
                '/config:cache akan menolak/',
                $content,
                "{$name} masih mengklaim config:cache menolak APP_KEY kosong, padahal tidak"
            );
        }

        // Sebaliknya, keduanya harus menyebut bahwa gerbang ada di entrypoint.
        $this->assertStringContainsString('entrypoint', $readme);
        $this->assertStringContainsString('entrypoint', $envExample);
    }

    /**
     * ENTRYPOINT bawaan image tidak boleh ditimpa.
     *
     * Image resmi php:8.3-apache memasang ENTRYPOINT ["docker-php-entrypoint"]
     * yang tugasnya menormalkan argumen lalu `exec "$@"`. Kalau Dockerfile
     * menimpanya, dua perintah umum ikut mati:
     *   docker run <image> php artisan --version
     *   docker run <image> bash
     *
     * Karena itu script aplikasi dipasang sebagai CMD, bukan ENTRYPOINT.
     */
    public function test_entrypoint_bawaan_tidak_ditimpa(): void
    {
        $dockerfile = (string) file_get_contents(\base_path('Dockerfile'));

        // Buang komentar sebelum memeriksa, supaya penjelasan di dalam
        // komentar tidak ikut terperiksa.
        $instructions = preg_replace('/^\s*#.*$/m', '', $dockerfile) ?? $dockerfile;

        $this->assertDoesNotMatchRegularExpression(
            '/^ENTRYPOINT\s/mi',
            $instructions,
            'Dockerfile tidak boleh memasang ENTRYPOINT, akan menimpa bawaan image'
        );

        $this->assertStringContainsString(
            'CMD ["/usr/local/bin/entrypoint.sh"]',
            $dockerfile,
            'Script aplikasi harus dipasang sebagai CMD'
        );

        // Script harus ada di path yang sama dengan yang dipanggil CMD.
        $this->assertStringContainsString(
            'COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh',
            $dockerfile
        );
        $this->assertStringContainsString(
            'RUN chmod +x /usr/local/bin/entrypoint.sh',
            $dockerfile,
            'Script harus dibuat executable'
        );

        // Nama lama tidak boleh tersisa.
        $this->assertStringNotContainsString('app-entrypoint', $dockerfile);
    }

    /**
     * Perintah untuk menjalankan artisan harus memakai runuser, bukan gosu.
     *
     * gosu TIDAK ada di image resmi php:apache. Dulu script memakainya dan
     * container langsung mati dengan "gosu: not found" sebelum Apache sempat
     * start; di CI yang terlihat hanya "connection refused" yang menyesatkan.
     *
     * runuser datang dari paket util-linux yang essential di Debian, dan image
     * ini berbasis debian:bookworm-slim, jadi tidak perlu paket tambahan.
     */
    public function test_entrypoint_memakai_runuser_bukan_gosu(): void
    {
        $entrypoint = (string) file_get_contents(\base_path('docker/entrypoint.sh'));
        $dockerfile = (string) file_get_contents(\base_path('Dockerfile'));

        // Buang komentar: penyebutan gosu di komentar sejarah itu wajar.
        $commands = preg_replace('/^\s*#.*$/m', '', $entrypoint) ?? $entrypoint;

        $this->assertDoesNotMatchRegularExpression(
            '/\bgosu\b/',
            $commands,
            'entrypoint tidak boleh memanggil gosu, perintah itu tidak ada di image'
        );

        // Semua perintah artisan harus lewat runuser.
        //
        // Baris echo sengaja dikecualikan: pesan bantuan seperti
        // `echo "php artisan key:generate --show"` bukan perintah yang
        // dijalankan, hanya teks yang ditampilkan ke pengguna.
        $executable = array_filter(
            explode("\n", $commands),
            static fn (string $line): bool => ! str_starts_with(trim($line), 'echo ')
                && str_contains($line, 'php artisan'),
        );

        $this->assertNotEmpty($executable, 'Tidak ada perintah artisan di entrypoint');

        foreach ($executable as $line) {
            if (str_contains($line, '|| true')) {
                continue; // perintah *_clear boleh gagal
            }

            $this->assertStringContainsString(
                'runuser -u www-data -- php artisan',
                $line,
                "Perintah artisan wajib lewat runuser: {$line}"
            );
        }

        // runuser tidak boleh dipasang lewat Dockerfile karena sudah ada.
        $this->assertDoesNotMatchRegularExpression(
            '/\bgosu\b/',
            $dockerfile,
            'Dockerfile tidak perlu memasang gosu karena tidak dipakai lagi'
        );
    }

    /**
     * Harus ada pemeriksaan bahwa runuser benar-benar tersedia.
     *
     * Pemeriksaan ini yang mengubah kegagalan "container mati bisu" menjadi
     * pesan yang bisa dibaca di docker logs.
     */
    public function test_entrypoint_memeriksa_ketersediaan_runuser(): void
    {
        $entrypoint = (string) file_get_contents(\base_path('docker/entrypoint.sh'));

        $this->assertStringContainsString(
            'command -v runuser',
            $entrypoint,
            'entrypoint harus memeriksa ketersediaan runuser'
        );

        // Harus dicek SEBELUM ada perintah yang mengubah keadaan.
        $guard = strpos($entrypoint, 'command -v runuser');
        $firstSed = strpos($entrypoint, 'sed -ri');

        $this->assertIsInt($guard);
        $this->assertIsInt($firstSed);
        $this->assertLessThan(
            $firstSed,
            $guard,
            'Pemeriksaan runuser harus sebelum perintah sed pertama'
        );

        // Environment ke proses anak harus diverifikasi, bukan diasumsikan.
        $this->assertStringContainsString(
            'runuser -u www-data -- sh -c \'test -n "$APP_KEY"\'',
            $entrypoint,
            'Harus dipastikan APP_KEY diteruskan ke proses www-data'
        );
    }

    /**
     * Langkah cache TIDAK boleh memakai "|| true".
     *
     * Kegagalan harus menghentikan start dengan pesan jelas. Kalau diabaikan,
     * aplikasi berjalan dengan cache yang tidak lengkap dan gejalanya muncul
     * jauh kemudian tanpa sebab yang jelas.
     */
    public function test_langkah_cache_tidak_boleh_mengabaikan_kegagalan(): void
    {
        $entrypoint = (string) file_get_contents(\base_path('docker/entrypoint.sh'));

        $commands = preg_replace('/^\s*#.*$/m', '', $entrypoint) ?? $entrypoint;

        foreach ([
            'config:cache',
            'route:cache',
            'view:cache',
            'package:discover',
        ] as $command) {
            foreach (explode("\n", $commands) as $line) {
                if (str_contains($line, "artisan {$command}") && ! str_contains($line, '*:clear')) {
                    $this->assertStringNotContainsString(
                        '|| true',
                        $line,
                        "Langkah {$command} tidak boleh memakai || true"
                    );
                }
            }
        }

        // Setiap langkah harus memberi jejak di log, supaya docker logs
        // menunjukkan di bagian mana start berhenti.
        foreach (['1/4', '2/4', '3/4', '4/4'] as $marker) {
            $this->assertStringContainsString($marker, $entrypoint, "Penanda langkah {$marker} hilang");
        }
    }

    /**
     * Apache harus bisa menulis ke storage dan bootstrap/cache.
     */
    public function test_entrypoint_menjamin_ownership_www_data(): void
    {
        $entrypoint = (string) file_get_contents(\base_path('docker/entrypoint.sh'));

        // chown harus dilakukan sebelum DAN sesudah langkah cache.
        $this->assertSame(
            2,
            preg_match_all('/chown -R www-data:www-data storage bootstrap\/cache/', $entrypoint),
            'chown harus dilakukan dua kali: sebelum dan sesudah langkah cache'
        );

        $firstChown = strpos($entrypoint, 'chown -R www-data');
        $packageDiscover = strpos($entrypoint, 'package:discover --ansi');

        $this->assertIsInt($firstChown);
        $this->assertIsInt($packageDiscover);
        $this->assertLessThan(
            $packageDiscover,
            $firstChown,
            'chown pertama harus sebelum langkah artisan'
        );
    }

    /**
     * Job CI harus benar-benar membangun dan menjalankan image.
     */
    public function test_ci_memiliki_job_docker(): void
    {
        $workflow = (string) file_get_contents(\base_path('.github/workflows/tests.yml'));

        $this->assertMatchesRegularExpression('/^  docker:$/m', $workflow);
        $this->assertStringContainsString('needs: tests', $workflow);
        $this->assertStringContainsString('docker build -t pdf-to-markdown .', $workflow);
        $this->assertStringContainsString('http://localhost:8080/pdf-to-markdown', $workflow);
    }

    /**
     * Flag retry curl harus menangani SEMUA error transient.
     *
     * Entry container menjalankan 7 perintah artisan sebelum Apache listen.
     * Selama masa itu port sudah dipetakan tapi belum ada yang mendengarkan,
     * sehingga koneksi berakhir sebagai "connection refused" (curl 7) atau
     * "connection reset by peer" (curl 56).
     *
     * --retry-connrefused saja TIDAK cukup: curl berhenti di percobaan pertama
     * saat jawabannya 56. Karena itu --retry-all-errors yang dipakai.
     */
    public function test_ci_smoke_test_mengulang_semua_error(): void
    {
        $workflow = (string) file_get_contents(\base_path('.github/workflows/tests.yml'));

        $this->assertStringContainsString(
            '--retry-all-errors',
            $workflow,
            'Smoke test wajib memakai --retry-all-errors'
        );

        // Retry harus cukup lama untuk 7 boot artisan + start Apache.
        $this->assertStringContainsString('--retry 15', $workflow);
        $this->assertStringContainsString('--retry-delay 2', $workflow);

        // --retry-connrefused saja sudah tidak cukup dan boleh diganti,
        // tapi flag --retry harus tetap ada.
        $this->assertStringContainsString('--retry ', $workflow);
        $this->assertStringContainsString('--fail', $workflow);

        // Batas per percobaan, supaya satu percobaan yang menggantung tidak
        // menghabiskan seluruh budget retry.
        $this->assertStringContainsString('--connect-timeout', $workflow);
    }

    /**
     * Harus ada langkah diagnostik yang jalan saat job gagal.
     *
     * Tanpa ini, kegagalan smoke test hanya memberi tahu "curl (56)"
     * tanpa penyebabnya. Penyebab sebenarnya ada di log container.
     */
    public function test_ci_menampilkan_log_container_saat_gagal(): void
    {
        $workflow = (string) file_get_contents(\base_path('.github/workflows/tests.yml'));

        $this->assertStringContainsString(
            'if: failure()',
            $workflow,
            'Harus ada langkah diagnostik dengan if: failure()'
        );

        $this->assertStringContainsString('docker logs pdf-md-smoke', $workflow);
        $this->assertStringContainsString('docker ps -a', $workflow);

        // Status container (exit code, OOM) accelerating mencari penyebab.
        $this->assertStringContainsString('docker inspect', $workflow);
    }

    /**
     * Langkah diagnosis harus memeriksa EMPAT hal saat smoke test balas 500.
     *
     * Apache sudah listen (bukan connection refused), jadi container hidup dan
     * Docker saja tidak cukup: penyebabnya ada DI DALAM image, yaitu
     * laravel.log, manifest Vite, dan hak tulis folder.
     */
    public function test_ci_diagnosis_500_menampilkan_log_manifest_dan_izin(): void
    {
        $diagnosis = $this->ambil_script_langkah_diagnosis();

        // 1. Isi laravel.log: 80 baris terakhir, bukan log penuh.
        //
        // Angka 80 diteruskan sebagai ARGUMEN ke helper, dan helper memakai
        // "tail -n ${max}". Dua hal ini diuji terpisah karena keduanya perlu.
        $this->assertStringContainsString('storage/logs/laravel.log', $diagnosis);
        $this->assertStringContainsString('tail -n ${max}', $diagnosis);
        $this->assertStringContainsString(
            "'laravel.log' 80",
            $diagnosis,
            'Harus meminta 80 baris terakhir lewat parameter ke helper'
        );

        // tail harus dilakukan di DALAM container supaya log besar tidak
        // ikut tertransfer ke runner.
        $this->assertStringContainsString('2>/dev/null', $diagnosis);

        // 2. Manifest Vite, dengan pesan jelas kalau tidak ada.
        $this->assertStringContainsString('public/build/manifest.json', $diagnosis);
        $this->assertStringContainsString('manifest.json TIDAK ADA', $diagnosis);

        // Manifest hilang adalah penyebab 500 yang paling mungkin, jadi harus
        // muncul sebagai anotasi error, bukan sekadar teks di log.
        $this->assertStringContainsString('::error::', $diagnosis);

        // 3. Hak tulis folder yang ditulis aplikasi.
        $this->assertStringContainsString(
            'ls -ld /var/www/html/storage /var/www/html/bootstrap/cache',
            $diagnosis
        );

        // 4. Body respons 500, dipotong supaya log tidak meledak.
        $this->assertStringContainsString(
            'curl -s http://localhost:8080/pdf-to-markdown | head -c 2000',
            $diagnosis
        );
    }

    /**
     * Diagnosis harus juga menampilkan konfigurasi rewrite Apache.
     *
     * AH00124 (loop rewrite) tidak bisa dibaca dari laravel.log karena
     * permintaannya memang tidak pernah sampai ke PHP. Yang perlu dilihat
     * adalah isi document root dan nilai AllowOverride.
     */
    public function test_ci_diagnosis_menampilkan_konfigurasi_rewrite_apache(): void
    {
        $diagnosis = $this->ambil_script_langkah_diagnosis();

        $this->assertStringContainsString('ls -la /var/www/html/public', $diagnosis);
        $this->assertStringContainsString('cat /var/www/html/public/.htaccess', $diagnosis);

        // harus tetap memberi pesan jelas kalau .htaccess memang tidak ada.
        $this->assertStringContainsString('.htaccess TIDAK ADA', $diagnosis);

        // Naluri tiga tempat konfigurasi sekaligus: vhost, config utama, conf.
        foreach (['sites-enabled', 'apache2.conf', 'conf-enabled'] as $tempat) {
            $this->assertStringContainsString(
                $tempat,
                $diagnosis,
                "Lokasi konfigurasi '{$tempat}' belum diperiksa"
            );
        }

        $this->assertStringContainsString('DocumentRoot', $diagnosis);
        $this->assertStringContainsString('AllowOverride', $diagnosis);
        $this->assertStringContainsString('<Directory', $diagnosis);

        // Alternasi grep harus tetap ditulis dengan "|" di dalam BRE.
        $this->assertStringContainsString(
            'DocumentRoot\\|AllowOverride\\|<Directory',
            $diagnosis,
            'grep BRE butuh \| untuk alternasi, bukan |'
        );

        // mod_rewrite harus dipastikan aktif.
        $this->assertStringContainsString('apache2ctl -M', $diagnosis);
        $this->assertStringContainsString('grep rewrite', $diagnosis);
        $this->assertStringContainsString('mod_rewrite TIDAK aktif', $diagnosis);
    }

    /**
     * Diagnostik tidak boleh menggagalkan dirinya sendiri.
     *
     * Kalau satu pemeriksaan error dan langkah ikut berhenti, pemeriksaan
     * setelahnya tidak pernah jalan dan informasi justru hilang.
     */
    public function test_ci_diagnosis_tidak_berhenti_di_check_yang_gagal(): void
    {
        $diagnosis = $this->ambil_script_langkah_diagnosis();

        // errexit harus dimatikan, dan itu harus pernyataan PERTAMA supaya berlaku
        // sebelum perintah apa pun dijalankan.
        $this->assertStringContainsString(
            'set +e',
            $diagnosis,
            'Langkah diagnosis wajib mulai dengan set +e'
        );
        $firstCodeLine = '';
        foreach (explode("\n", $diagnosis) as $line) {
            if (trim($line) !== '' && ! str_starts_with(trim($line), '#')) {
                $firstCodeLine = trim($line);
                break;
            }
        }
        $this->assertSame(
            'set +e',
            $firstCodeLine,
            'set +e harus menjadi perintah pertama, bukan di tengah'
        );

        // Setiap perintah yang gagal berarti "tidak ada yang bisa dibaca"
        // wajib ditoleransi, supaya pemeriksaan lain tetap jalan.
        //
        // Baris lebih dulu digabung bila memakai line-continuation (\), karena
        // "|| echo ..." sering ada di baris kedua.
        $joined = preg_replace('/\\\\\s*\n\s*/', ' ', $diagnosis) ?? $diagnosis;
        $risky = ['docker logs', 'docker ps', 'docker inspect', 'docker port', 'docker cp'];

        foreach (explode("\n", $joined) as $line) {
            $trimmed = trim($line);

            foreach ($risky as $needle) {
                if (str_starts_with($trimmed, $needle) && ! str_contains($trimmed, '||')) {
                    $this->fail("Perintah '{$needle}' tanpa toleransi gagal: {$trimmed}");
                }
            }
        }

        // curl-body DIKECUALIKAN dengan sengaja: "curl | head -c 2000" membuat
        // head menutup pipe begitu 2000 byte terkumpul, sehingga curl
        // menerima SIGPIPE dan keluar non-zero WAJIB pun meski responsnya
        // sukses. Menuntut "|| true" di sana akan menyesatkan.

        // Nama container harus lewat env, bukan ditulis ulang di banyak tempat.
        $workflow = (string) file_get_contents(\base_path('.github/workflows/tests.yml'));
        $this->assertMatchesRegularExpression('/CONTAINER:\s*pdf-md-smoke/', $workflow);
    }

    /**
     * Kalau container sudah berhenti, docker exec tidak bisa dipakai.
     *
     * Jalur cadangan ini wajib ada. Tanpa itu, gejala sebenarnya akan
     * tertutup oleh pesan "No such container" yang menyesatkan.
     */
    public function test_ci_diagnosis_menangani_container_yang_sudah_berhenti(): void
    {
        $diagnosis = $this->ambil_script_langkah_diagnosis();

        // Status running harus dibaca lebih dulu.
        $this->assertStringContainsString('State.Running', $diagnosis);

        // Cadangan lewat docker cp.
        $this->assertStringContainsString('docker cp', $diagnosis);
        $this->assertStringContainsString('RUNNING', $diagnosis);
    }

    /**
     * DocumentRoot harus benar-benar berisi front controller dan .htaccess.
     *
     * Docker menyalin ISI folder sumber, bukan foldernya sendiri. Karena itu
     * "COPY public ./" menaruh index.php di /var/www/html/index.php, satu
     * tingkat di ATAS document root.
     *
     * Akibatnya /var/www/html/public hanya berisi hasil build Vite, dan
     * aturan rewrite di .htaccess tidak pernah dijalankan.
     */
    public function test_dockerfile_menyalin_public_ke_dalam_document_root(): void
    {
        $dockerfile = (string) file_get_contents(\base_path('Dockerfile'));

        // Bentuk yang benar.
        $this->assertMatchesRegularExpression(
            '/^COPY public \.\/public$/m',
            $dockerfile,
            'public/ harus disalin ke ./public, bukan ke .'
        );

        // Bentuk yang salah. Penting memakai jangkar akhir baris, karena
        // "COPY public ./public" secara tekstual MEMILIKI "COPY public ./".
        $this->assertDoesNotMatchRegularExpression(
            '/^COPY public \.\/$/m',
            $dockerfile,
            '"COPY public ./" menaruh isi public/ satu level terlalu atas'
        );

        // Asset Vite harus masuk ke document root yang sama.
        $this->assertMatchesRegularExpression(
            '/^COPY --from=assets \/app\/public\/build \.\/public\/build$/m',
            $dockerfile
        );

        // DocumentRoot di entrypoint harus persis folder itu.
        $entrypoint = (string) file_get_contents(\base_path('docker/entrypoint.sh'));
        $this->assertStringContainsString(
            'DOCROOT="${APACHE_DOCUMENT_ROOT:-/var/www/html/public}"',
            $entrypoint
        );
    }

    /**
     * .dockerignore tidak boleh menyingkirkan berkas yang dibutuhkan halaman.
     *
     * Pola seperti ".*" akan ikut mengenai .htaccess, dan "*.php" akan
     * mengenai index.php. Keduanya membuat image tidak bisa melayani halaman.
     */
    public function test_dockerignore_tidak_mengecualikan_berkas_publik(): void
    {
        $dockerignore = (string) file_get_contents(\base_path('.dockerignore'));

        // Blanket dotfile akan mengenai .htaccess.
        $this->assertDoesNotMatchRegularExpression(
            '/^\.\*$/m',
            $dockerignore,
            'Pola ".*" akan mengecualikan .htaccess'
        );

        // Blanket php akan mengenai index.php.
        $this->assertDoesNotMatchRegularExpression(
            '/^\*\.php$/m',
            $dockerignore,
            'Pola "*.php" akan mengecualikan public/index.php'
        );

        // "public" polos akan membuang SELURUH folder publik.
        $this->assertDoesNotMatchRegularExpression(
            '/^public$/m',
            $dockerignore,
            'Pola "public" akan mengecualikan seluruh folder public/'
        );

        // Sebaliknya, hasil build lokal tetap harus dikecualikan supaya
        // tidak menimpa asset yang dibangun di dalam image.
        $this->assertStringContainsString('public/build', $dockerignore);

        // Berkas publik yang dibutuhkan halaman harus benar-benar ada.
        foreach (['public/index.php', 'public/.htaccess'] as $berkas) {
            $this->assertFileExists(
                \base_path($berkas),
                "{$berkas} wajib ada di repositori supaya bisa ter-copy ke image"
            );
        }
    }

    /**
     * .htaccess harus dibaca Apache, kalau tidak pretty URL tidak jalan.
     *
     * docker-php.conf dari image php hanya memberi "AllowOverride All" untuk
     * /var/www/, sedangkan blok <Directory> di vhost lebih spesifik dan
     * menimpanya. Kalau nilainya None, .htaccess diabaikan sepenuhnya.
     */
    public function test_apache_membaca_htaccess(): void
    {
        $entrypoint = (string) file_get_contents(\base_path('docker/entrypoint.sh'));

        $this->assertStringContainsString(
            'AllowOverride[[:space:]]+)None',
            $entrypoint,
            'AllowOverride harus dinaikkan ke All agar .htaccess dibaca'
        );

        // Perilaku nyata dari aturan yang sama, diuji pada vhost bergaya Debian.
        $vhost = <<<'APACHE'
            <VirtualHost *:80>
            	DocumentRoot /var/www/html

            	<Directory /var/www/html>
            		Options -Indexes +FollowSymLinks
            		AllowOverride None
            		Require all granted
            	</Directory>
            </VirtualHost>
            APACHE;

        // Vhost Debian memakai TAB untuk indentasi, dan sed di entrypoint tidak
        // di-anchor ke awal baris. Pola di bawah meniru perilaku itu.
        $docroot = preg_replace(
            '/^([ \t]*DocumentRoot\s+)\/var\/www\/html([ \t]*)$/m',
            '$1/var/www/html/public$2',
            $vhost
        );

        $directory = preg_replace(
            '/^([ \t]*<Directory\s+)\/var\/www\/html([ \t]*>)$/m',
            '$1/var/www/html/public$2',
            $docroot ?? ''
        );

        $fixed = preg_replace(
            '/(AllowOverride\s+)None/',
            '$1All',
            $directory ?? ''
        );

        $this->assertStringContainsString('DocumentRoot /var/www/html/public', $fixed ?? '');
        $this->assertStringContainsString('<Directory /var/www/html/public>', $fixed ?? '');
        $this->assertStringContainsString('AllowOverride All', $fixed ?? '');
        $this->assertStringNotContainsString('AllowOverride None', $fixed ?? '');

        // Aturan harus idempoten: container bisa start berulang.
        $again = preg_replace(
            '/(AllowOverride\s+)None/',
            '$1All',
            preg_replace(
                '/^([ \t]*<Directory\s+)\/var\/www\/html([ \t]*>)$/m',
                '$1/var/www/html/public$2',
                preg_replace(
                    '/^([ \t]*DocumentRoot\s+)\/var\/www\/html([ \t]*)$/m',
                    '$1/var/www/html/public$2',
                    $fixed ?? ''
                ) ?? ''
            ) ?? ''
        );

        $this->assertSame($fixed, $again, 'Aturan Apache harus idempoten');
    }

    /**
     * Ambil script run milik langkah "Diagnosis kegagalan container".
     *
     * Mengambil per langkah, bukan seluruh workflow, supaya assertion tidak
     * bisa lolos karena string yang sama muncul di langkah lain.
     *
     * Yang dikembalikan adalah ISI script saja (tanpa kunci YAML), supaya
     * pemeriksaan "perintah pertama" tidak tertukar dengan "- name: ...".
     */
    private function ambil_script_langkah_diagnosis(): string
    {
        $workflow = (string) file_get_contents(\base_path('.github/workflows/tests.yml'));

        $this->assertStringContainsString(
            '- name: Diagnosis kegagalan container',
            $workflow,
            'Langkah diagnosis harus ada di workflow'
        );

        // Dari nama langkah sampai langkah berikutnya.
        preg_match(
            '/- name: Diagnosis kegagalan container.*?\n(?=\s+- name:|\s*# Log juga)/s',
            $workflow,
            $match
        );

        $this->assertNotEmpty($match, 'Blok langkah diagnosis tidak bisa dipotong');

        // Potong bagian sebelum "run: |".
        $parts = preg_split('/^\s*run: \|\s*$/m', $match[0], 2);

        $this->assertCount(
            2,
            $parts,
            'Langkah diagnosis harus punya blok "run: |"'
        );

        // Buang indentasi YAML (10 spasi) supaya script dibaca apa adanya.
        $script = preg_replace('/^ {10}/m', '', $parts[1]);

        $this->assertIsString($script);
        $this->assertStringStartsWith('set +e', ltrim($script), 'Script harus diawali set +e');

        return $script;
    }

    /**
     * Pemeriksaan isi halaman harus tahan terhadap URL absolut.
     *
     * route() di Laravel menghasilkan URL absolut, jadi atribut action berisi
     * "http://localhost/pdf-to-markdown", bukan "/pdf-to-markdown".
     * Pola yang memaksa path relatif akan selalu gagal meski halaman sehat.
     */
    public function test_pemeriksaan_halaman_tahan_terhadap_url_absolut(): void
    {
        $workflow = (string) file_get_contents(\base_path('.github/workflows/tests.yml'));

        // Pola action harus menerima bentuk absolut maupun relatif.
        $this->assertStringContainsString(
            'action="[^"]*/pdf-to-markdown"',
            $workflow,
            'Pola action harus menerima URL absolut dari route()'
        );

        // Form upload harus dicek lewat atribut yang memang ada di HTML.
        $this->assertStringContainsString('name="_token"', $workflow);
        $this->assertStringContainsString('enctype="multipart/form-data"', $workflow);
    }
}
