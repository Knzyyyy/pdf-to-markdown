<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Error yang "ramahDicakai" saat konversi PDF gagal.
 *
 * Dipisahkan dari Exception umum supaya Controller bisa membedakan:
 * - ConversionException  -> pesan aman untuk ditampilkan ke pengguna
 * - Exception lainnya    -> bug aplikasi, ditangani Handler Laravel
 *
 * Semua pesan di sini ditulis dalam bahasa Indonesia dan TIDAK pernah
 * memuat isi dokumen pengguna maupun detail internal sistem.
 */
class ConversionException extends RuntimeException
{
    public static function unreadable(): self
    {
        return new self('File PDF tidak dapat dibaca. Pastikan file tidak rusak atau terpotong saat diunduh.');
    }

    public static function encrypted(): self
    {
        return new self('File PDF ini diproteksi dengan kata sandi (terenkripsi). Silakan hapus proteksinya lalu coba lagi.');
    }

    public static function noExtractableText(): self
    {
        return new self(
            'Tidak ada teks yang dapat diekstrak dari file ini. '
            .'Kemungkinan besar PDF hasil scan (gambar) sehingga membutuhkan OCR, '
            .'dan OCR belum didukung oleh aplikasi ini.'
        );
    }

    /**
     * Pengguna memilih rentang halaman yang melebihi jumlah halaman dokumen.
     * Pesan ini sengaja menyebutkan jumlah halaman sebenarnya supaya pengguna
     * tahu rentang yang benar.
     */
    public static function pageRangeOutOfBounds(string $range, int $totalPages): self
    {
        return new self(
            "Rentang {$range} tidak tersedia. Dokumen ini hanya memiliki {$totalPages} halaman."
        );
    }
}
