<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Test perilaku trusted proxy di level HTTP.
 *
 * Focus: memastikan X-Forwarded-For dari client TIDAK dipercaya saat
 * TRUSTED_PROXIES kosong, dan DIPERCAYA saat diisi "*".
 *
 * Ini yang relevan: kalau salah, rate limit 10/menit jadi tidak berguna
 * karena semua request akan terhitung berasal dari satu IP.
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
        putenv('TRUSTED_PROXIES');
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);

        parent::tearDown();
    }

    public function test_x_forwarded_for_diabaikan_secara_default(): void
    {
        putenv('TRUSTED_PROXIES');

        $response = $this->get('/__ip-probe', [
            'X-Forwarded-For' => '203.0.113.5',
            'REMOTE_ADDR' => '198.51.100.7',
        ]);

        $response->assertOk();

        // Client pengirim IP palsu TIDAK boleh terbaca.
        $this->assertNotSame('203.0.113.5', $response->json('ip'));
    }

    public function test_x_forwarded_for_dipercaya_kalauproxy_ditandai(): void
    {
        putenv('TRUSTED_PROXIES=*');

        $response = $this->get('/__ip-probe', [
            'X-Forwarded-For' => '203.0.113.5',
            'REMOTE_ADDR' => '198.51.100.7',
        ]);

        $response->assertOk();

        // Dengan TRUSTED_PROXIES=* (setup Render/Railway), IP asli terbaca.
        $this->assertSame('203.0.113.5', $response->json('ip'));
    }

    public function test_tanpa_x_forwarded_for_tidak_berubah(): void
    {
        putenv('TRUSTED_PROXIES');

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

        // Base image harus versi major PHP yang cocok dengan composer.json.
        $this->assertStringContainsString('FROM php:8.3-apache', $dockerfile);
        $this->assertStringContainsString('FROM node:20-alpine', $dockerfile);
        $this->assertStringContainsString('FROM composer:2', $dockerfile);

        // ext-zip wajib ada karena tercatat di composer.json.
        $this->assertStringContainsString('zip', $dockerfile);

        // .env tidak boleh ikut ke dalam image.
        $dockerignore = (string) file_get_contents($root.'/.dockerignore');
        $this->assertMatchesRegularExpression('/^\.env$/m', $dockerignore);
    }
}
