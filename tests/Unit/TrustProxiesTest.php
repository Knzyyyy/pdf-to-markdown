<?php

namespace Tests\Unit;

use App\Http\Middleware\TrustProxies;
use Tests\TestCase;

/**
 * Test untuk pembacaan TRUSTED_PROXIES lewat config.
 *
 * PENTING: test ini TIDAK memakai putenv(). Itu sengaja.
 *
 * Alasannya: Illuminate\Support\Env membaca $_SERVER dan $_ENV lebih dulu
 * daripada getenv(). Begitu .env ter-load, nilai yang tersimpan di
 * $_ENV/_SERVER selalu menang atas putenv(). Akibatnya test berbasis putenv()
 * lulus di mesin lokal (yang .env-nya tidak punya baris TRUSTED_PROXIES) tapi
 * gagal di CI (yang .env-nya hasil cp .env.example, jadi nilainya kosong).
 *
 * Test berbasis config() tidak punya masalah itu, karena tidak menyentuh
 * environment sama sekali.
 */
class TrustProxiesTest extends TestCase
{
    protected function tearDown(): void
    {
        // Kembalikan nilai config ke kondisi semula supaya tidak bocor ke
        // test lain dalam satu proses.
        config(['trustedproxy.proxies' => env('TRUSTED_PROXIES')]);

        parent::tearDown();
    }

    // =========================================================
    // Terjemahan nilai config
    // =========================================================

    /**
     * @dataProvider normalizeProvider
     *
     * @param  array<int, string>|string|null  $expected
     */
    public function test_menerjemahkan_nilai_config(mixed $input, array|string|null $expected): void
    {
        $this->assertSame($expected, TrustProxies::normalize($input));
    }

    /**
     * @return array<string, array{0: mixed, 1: array<int, string>|string|null}>
     */
    public static function normalizeProvider(): array
    {
        return [
            // --- Default: percaya nobody ---
            'null' => [null, null],
            'k kosong' => ['', null],
            'hanya spasi' => ['   ', null],
            'hanya koma' => [',', null],
            'hanya koma dan spasi' => [' , , ', null],
            'bukan string' => [false, null],
            'integer' => [123, null],

            // --- Semua proxy platform ---
            'bintang' => ['*', '*'],
            'bintang ganda' => ['**', '**'],
            'bintang dengan spasi' => ['  *  ', '*'],

            // --- Daftar IP eksplisit ---
            'satu ip' => ['10.0.0.1', ['10.0.0.1']],
            'beberapa ip' => ['10.0.0.1,10.0.0.2', ['10.0.0.1', '10.0.0.2']],
            'dengan spasi' => [' 10.0.0.1 , 10.0.0.2 ', ['10.0.0.1', '10.0.0.2']],
            'daftar ipv6' => ['2001:db8::1', ['2001:db8::1']],
            'ada entri kosong di tengah' => ['10.0.0.1,,10.0.0.2', ['10.0.0.1', '10.0.0.2']],
        ];
    }

    // =========================================================
    // Middleware membaca dari config
    // =========================================================

    public function test_default_tidak_mempercayai_proxy_manapun(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->assertNull(
            (new TrustProxies)->trustedProxies(),
            'Default harus tidak mempercayai proxy apa pun'
        );
    }

    public function test_kosong_tidak_mempercayai_proxy_manapun(): void
    {
        // Kasus yang memicu bug di CI: .env menyertakan baris kosong.
        config(['trustedproxy.proxies' => '']);

        $this->assertNull((new TrustProxies)->trustedProxies());
    }

    public function test_bintang_membuat_semua_proxy_dipercayai(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $this->assertSame('*', (new TrustProxies)->trustedProxies());
    }

    public function test_daftar_ip_dibaca_dari_config(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.1,10.0.0.2']);

        $this->assertSame(['10.0.0.1', '10.0.0.2'], (new TrustProxies)->trustedProxies());
    }

    /**
     * Menutup akar masalahnya: nilai yang dibaca HARUS berasal dari config,
     * bukan dari environment.
     *
     * Kalau suatu saat middleware kembali memanggil env() langsung, test ini
     * akan gagal даже ketika test lain masih hijau.
     */
    public function test_middleware_tidak_langsung_memanggil_env(): void
    {
        $source = (string) file_get_contents(
            \base_path('app/Http/Middleware/TrustProxies.php')
        );

        // Buang komentar dulu supaya penjelasan di dalam komentar tidak
        // ikut terperiksa.
        $code = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;
        $code = preg_replace('#//.*$#m', '', $code) ?? $code;

        $this->assertDoesNotMatchRegularExpression(
            '/\benv\s*\(/',
            $code,
            'TrustProxies harus membaca dari config, bukan memanggil env() langsung'
        );

        $this->assertStringContainsString(
            "config('trustedproxy.proxies')",
            $source,
            'TrustProxies harus membaca config("trustedproxy.proxies")'
        );
    }

    public function test_config_trustedproxy_ada_dan_membaca_env(): void
    {
        $path = \base_path('config/trustedproxy.php');

        $this->assertFileExists($path);

        $config = require $path;

        $this->assertIsArray($config);
        $this->assertArrayHasKey('proxies', $config);
        $this->assertSame(env('TRUSTED_PROXIES'), $config['proxies']);
    }
}
