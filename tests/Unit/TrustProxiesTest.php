<?php

namespace Tests\Unit;

use App\Http\Middleware\TrustProxies;
use Tests\TestCase;

/**
 * Test untuk pembacaan TRUSTED_PROXIES di TrustProxies.
 *
 * Kenapa test ini penting: kalau terlalu banyak proxy dipercaya, siapa pun
 * bisa memalsukan X-Forwarded-For. Rate limit 10 konversi per menit lalu
 * kehilangan artinya.
 */
class TrustProxiesTest extends TestCase
{
    protected function tearDown(): void
    {
        // Pastikan variabel yang di-set test tidak bocor ke test lain.
        putenv('TRUSTED_PROXIES');
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);

        parent::tearDown();
    }

    // =========================================================
    // Terjemahan nilai environment variable
    // =========================================================

    /**
     * @dataProvider environmentProvider
     *
     * @param  array<int, string>|string|null  $expected
     */
    public function test_menerjemahkan_nilai_environment(mixed $input, array|string|null $expected): void
    {
        $this->assertSame($expected, TrustProxies::fromEnvironment($input));
    }

    /**
     * @return array<string, array{0: mixed, 1: array<int, string>|string|null}>
     */
    public static function environmentProvider(): array
    {
        return [
            // --- Default: percaya nobody ---
            'null' => [null, null],
            'k kosong' => ['', null],
            'spasi saja' => ['   ', null],
            'hanya koma' => [',', null],
            'hanya koma dan spasi' => [' , , ', null],
            'bukan string' => [false, null],
            'integer' => [123, null],

            // --- Semua proxyplatform ---
            'bintang' => ['*', '*'],
            'bintang ganda' => ['**', '**'],
            'bintang dengan spasi' => ['  *  ', '*'],

            // --- Daftar IP eksplisit ---
            'satu ip' => ['10.0.0.1', ['10.0.0.1']],
            'beberapa ip' => ['10.0.0.1,10.0.0.2', ['10.0.0.1', '10.0.0.2']],
            'dengan spasi' => [' 10.0.0.1 , 10.0.0.2 ', ['10.0.0.1', '10.0.0.2']],
            'daftar ipv6' => ['2001:db8::1', ['2001:db8::1']],
        ];
    }

    public function test_default_tidak_mempercayai_proxy_manapun(): void
    {
        // Tidak ada variabel yang di-set, jadi harus kembali ke default aman.
        putenv('TRUSTED_PROXIES');

        $middleware = new TrustProxies;

        $this->assertNull(
            $middleware->trustedProxies(),
            'Default harus tidak mempercayai proxy apa pun'
        );
    }

    public function test_env_kosong_tidak_mempercayai_proxy_manapun(): void
    {
        putenv('TRUSTED_PROXIES=');

        $this->assertNull((new TrustProxies)->trustedProxies());
    }

    public function test_bintang_membuat_semua_proxy_dipercayai(): void
    {
        putenv('TRUSTED_PROXIES=*');

        $this->assertSame('*', (new TrustProxies)->trustedProxies());
    }

    public function test_daftar_ip_dibaca_dari_environment(): void
    {
        putenv('TRUSTED_PROXIES=10.0.0.1,10.0.0.2');

        $this->assertSame(['10.0.0.1', '10.0.0.2'], (new TrustProxies)->trustedProxies());
    }
}
