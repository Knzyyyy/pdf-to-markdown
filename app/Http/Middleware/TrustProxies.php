<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * Trusted proxies untuk aplikasi ini.
     *
     * Diisi dari config('trustedproxy.proxies') pada constructor di bawah,
     * BUKAN dari env() langsung. Alasannya ada di config/trustedproxy.php:
     * env() membaca $_ENV/_SERVER lebih dulu daripada getenv(), sehingga
     * nilai dari .env selalu menang atas putenv() dan test yang memakai
     * putenv() akan selalu salah di CI.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    /**
     * Header yang dipakai untuk mendeteksi proxy.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    public function __construct()
    {
        $this->proxies = self::normalize(config('trustedproxy.proxies'));
    }

    /**
     * Daftar proxy yang benar-benar dipercaya.
     *
     * Parent class menyimpannya di method protected proxies(), jadi method
     * ini dibuat publik supaya bisa diperiksa test tanpa reflection.
     *
     * @return array<int, string>|string|null
     */
    public function trustedProxies(): array|string|null
    {
        return $this->proxies;
    }

    /**
     * Terjemahkan nilai mentah dari config menjadi bentuk yang dipahami
     * middleware Laravel.
     *
     *   null atau "" atau whitespace  -> null (percaya nobody, default aman)
     *   "*" atau "**"                 -> diteruskan, ini artinya percaya proxy
     *                                    yang sedang memanggil
     *   "10.0.0.1, 10.0.0.2"          -> array ['10.0.0.1', '10.0.0.2']
     *   ", , " (semua entri kosong)   -> null
     *
     * Penting: string kosong HARUS jadi null, bukan array kosong. Kalau tidak,
     * parent class akan membentuk daftar [''], yaitu daftar dengan satu entri
     * berupa IP kosong.
     *
     * @return array<int, string>|string|null
     */
    public static function normalize(mixed $raw): array|string|null
    {
        // Nilai bukan string (mis. null) -> default: percaya nobody.
        if (! is_string($raw)) {
            return null;
        }

        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        if ($raw === '*' || $raw === '**') {
            return $raw;
        }

        $list = array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $value): bool => $value !== '',
        ));

        return $list === [] ? null : $list;
    }
}
