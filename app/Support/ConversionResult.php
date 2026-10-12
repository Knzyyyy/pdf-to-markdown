<?php

namespace App\Support;

/**
 * Objek hasil konversi. Dipakai sebagai "kotak" yang dikembalikan Service
 * ke Controller, supaya Controller tidak perlu tahu detail perhitungan.
 *
 * Immutable: setelah dibuat, isinya tidak bisa diubah. Tidak ada setter.
 */
final class ConversionResult
{
    /** Markdown lengkap (semua halaman digabung dengan separator). */
    public readonly string $markdown;

    /**
     * @param  array<int, string>  $pages  Markdown per halaman, urut.
     * @param  string  $separator  Teks pemisah antar halaman, mis. "\n\n---\n\n".
     * @param  int|null  $totalPagesInDocument  Jumlah halaman asli di PDF, null
     *                                          kalau dokumen tidak punya batas halaman.
     */
    public function __construct(
        public readonly array $pages,
        public readonly string $separator,
        public readonly ?int $totalPagesInDocument = null,
    ) {
        $this->markdown = implode($this->separator, $this->pages);
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /**
     * True kalau hanya sebagian halaman yang dikonversi.
     */
    public function isPartial(): bool
    {
        return $this->totalPagesInDocument !== null
            && $this->totalPagesInDocument !== $this->pageCount();
    }

    public function wordCount(): int
    {
        if ($this->markdown === '') {
            return 0;
        }

        return preg_match_all('/\S+/u', $this->markdown) ?: 0;
    }

    public function characterCount(): int
    {
        return mb_strlen($this->markdown);
    }

    /**
     * Estimasi kasar jumlah token untuk LLM.
     * Aturan umum: 1 token ~ 4 karakter. INI PERKIRAAN, bukan angka pasti.
     */
    public function estimatedTokens(): int
    {
        return (int) ceil($this->characterCount() / 4);
    }

    /**
     * Buat nama file .md yang aman dari nama file asli milik pengguna.
     *
     * PENTING: nama file dari user TIDAK PERNAH dipakai sebagai path di disk.
     * Kita hanya memakai basename + penyaringan karakter, lalu tetap menyimpan
     * sebagai teks yang dikirim lewat response (bukan file fisik).
     */
    public function filenameFor(string $originalFilename): string
    {
        $base = pathinfo(basename($originalFilename), PATHINFO_FILENAME);

        $base = preg_replace('/[^A-Za-z0-9._ -]/u', '', $base) ?? '';
        $base = trim(preg_replace('/\s+/u', ' ', $base) ?? '');
        $base = ltrim($base, '.-');

        if ($base === '') {
            return 'dokumen.md';
        }

        if (mb_strlen($base) > 80) {
            $base = mb_substr($base, 0, 80);
        }

        return $base.'.md';
    }
}
