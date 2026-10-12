<?php

namespace App\Support;

/**
 * Penyimpanan sementara hasil konversi.
 *
 * ============ KENAPA BUKAN SESSION? ============
 * Cookie session punya batas ukuran sekitar 4 KB. Hasil konversi PDF
 * gampang banget jauh lebih besar dari itu (PDF 15 MB bisa jadi beberapa
 * ratus KB Markdown), dan kalo dipaksa disimpan di session bisa gagal
 * atau membuat cookie tidak terbaca sama sekali.
 *
 * Karena itu yang disimpan di session HANYA kunci acak (kurang dari 40
 * karakter). Isi Markdown-nya sendiri disimpan di cache dengan TTL 15 menit.
 *
 * ============ CATATAN KEAMANAN ============
 * Kunci dibuat dengan random_bytes() sehingga tidak bisa ditebak. Selain
 * itu session dan cache sama-sama milik satu browser yang sedang membuka
 * halaman ini, jadi pengguna hanya bisa mengunduh hasil konversinya sendiri.
 */
class ConversionStore
{
    /** Berapa lama hasil disimpan sebelum otomatis kedaluwarsa. */
    public const TTL_SECONDS = 15 * 60;

    /** Prefix pada kunci cache, supaya mudah dikenali saat debugging. */
    private const PREFIX = 'pdf_md:';

    /** Nama session tempat kuncinya disimpan. */
    public const SESSION_KEY = 'pdf_md_key';

    /**
     * Simpan hasil konversi, kembalikan kunci acak untuk diunduh nanti.
     *
     * @param  array<string, mixed>  $payload
     */
    public function put(array $payload): string
    {
        $key = $this->generateKey();

        cache()->put(self::PREFIX.$key, $payload, self::TTL_SECONDS);

        return $key;
    }

    /**
     * Ambil hasil berdasarkan kunci. Mengembalikan null kalau sudah kedaluwarsa
     * atau memang tidak ada.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        $payload = cache()->get(self::PREFIX.$key);

        return is_array($payload) ? $payload : null;
    }

    /**
     * Ambil hasil berdasarkan kunci DAN langsung hapus (sekali pakai).
     *
     * Dipakai saat mengunduh ZIP, supaya file yang sama tidak bisa diunduh
     * berkali-kali.
     *
     * @return array<string, mixed>|null
     */
    public function pull(string $key): ?array
    {
        $payload = $this->get($key);
        $this->forget($key);

        return $payload;
    }

    public function forget(string $key): void
    {
        cache()->forget(self::PREFIX.$key);
    }

    /**
     * Kunci acak yang aman: 32 byte acak = 64 karakter heksadesimal.
     */
    private function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }
}
