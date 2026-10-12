<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * Trusted proxies untuk aplikasi ini.
     *
     * Diisi dari variabel lingkungan TRUSTED_PROXIES pada constructor di
     * bawah. Nilai kosong berarti TIDAK ADA proxy yang dipercaya, sehingga
     * header X-Forwarded-* dari siapa pun diabaikan.
     *
     * Kenapa ini penting: kalau semua proxy dipercaya, siapa pun bisa
     * memalsukan X-Forwarded-For dan alamat IP-nya. Rate limit 10 konversi
     * per menit jadi tidak berguna, karena semua request akan terhitung
     * sebagai berasal dari satu IP yang sama.
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
        $this->proxies = self::fromEnvironment(env('TRUSTED_PROXIES'));
    }

    /**
     * Daftar proxy yang benar-benar dipercaya.
     *
     * Parent class menyimpan nilainya di method protected proxies(), jadi
     * method ini dibuat publik supaya bisa diperiksa test tanpa memakai
     * reflection.
     *
     * @return array<int, string>|string|null
     */
    public function trustedProxies(): array|string|null
    {
        return $this->proxies;
    }

    /**
     * Terjemahkan nilai variabel lingkungan menjadi daftar proxy.
     *
     * Nilai yang diterima:
     *   null, "", (kosong)  -> null, artinya percaya nobody (default aman)
     *   "*" atau "**"       -> percaya proxy yang memanggil, cocok untuk
     *                         Render/Railway yang hanya bisa dijangkau
     *                         lewat proxy platform
     *   "10.0.0.1,10.0.0.2" -> daftar IP proxy yang dipercaya
     *
     * Method ini sengaja public static supaya mudah diuji tanpa harus
     * membangun middleware.
     *
     * @return array<int, string>|string|null
     */
    public static function fromEnvironment(mixed $raw): array|string|null
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

        // Semua entri kosong (mis. " , , ") -> kembali ke default aman.
        return $list === [] ? null : $list;
    }
}
