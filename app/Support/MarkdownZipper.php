<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Membuat file ZIP berisi hasil konversi: satu file .md per halaman,
 * ditambah satu file .md gabungan.
 *
 * Dipakai untuk dokumen panjang, supaya pengguna bisa menyalin hanya halaman
 * yang mereka perlukan, alih-alih satu file raksasa.
 *
 * Catatan: extension PHP "zip" harus aktif. Kalau tidak, kelas ini melempar
 * exception yang jelas, bukan error fatal yang membingungkan.
 */
class MarkdownZipper
{
    /** Extension yang dibutuhkan. */
    public static function isAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /**
     * Nama file di dalam ZIP per halaman.
     *
     * Nomor halaman dimulai dari 1 dan diberi nol di depan supaya urutan
     * file benar saat diurutkan di sistem file maupun di GitHub.
     *
     * @param  array<int, string>  $pages
     * @return array<string, string> nama file => isi file
     */
    public function buildFiles(array $pages, string $baseName = 'dokumen'): array
    {
        $base = $this->safeEntryName($baseName);
        $files = [];

        foreach (array_values($pages) as $index => $content) {
            $files[sprintf('%s-%03d.md', $base, $index + 1)] = $content;
        }

        return $files;
    }

    /**
     * Buat file ZIP sementara dan kembalikan path-nya.
     *
     * Caller WAJIB menghapus file tersebut setelah selesai (lihat delete()).
     * File ini berisi dokumen pengguna, jadi tidak boleh ditinggalkan.
     *
     * @param  array<int, string>  $pages
     *
     * @throws RuntimeException
     */
    public function createTemporaryArchive(
        array $pages,
        string $combinedMarkdown,
        string $baseName = 'dokumen',
    ): string {
        if (! self::isAvailable()) {
            throw new RuntimeException(
                'Ekstensi PHP "zip" belum aktif, jadi file ZIP tidak bisa dibuat. '
                .'Aktifkan extension zip di php.ini lalu muat ulang server.'
            );
        }

        $path = tempnam(sys_get_temp_dir(), 'pdf-md-zip');

        if ($path === false) {
            throw new RuntimeException('Gagal membuat file sementara untuk ZIP.');
        }

        try {
            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Gagal menyiapkan file ZIP.');
            }

            $base = $this->safeEntryName($baseName);

            // File gabungan ikut disertakan supaya pengguna tetap punya satu
            // file utuh seperti hasil download .md biasa.
            $zip->addFromString($base.'.md', $combinedMarkdown);

            foreach ($this->buildFiles($pages, $base) as $name => $content) {
                $zip->addFromString($name, $content);
            }

            $zip->close();
        } catch (\Throwable $e) {
            // Kalau gagal di tengah jalan, jangan tinggalkan file yang
            // sudah terlanjur dibuat di disk.
            $this->delete($path);

            throw $e;
        }

        return $path;
    }

    /**
     * Hapus file ZIP sementara. Aman dipanggil berkali-kali.
     */
    public function delete(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Nama file/entry dibersihkan supaya aman dipakai di dalam ZIP maupun
     * di header Content-Disposition.
     */
    public function safeEntryName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._ -]/u', '', $name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $name = ltrim($name, '.-');

        return $name === '' ? 'dokumen' : mb_substr($name, 0, 60);
    }

    /**
     * Nama file ZIP untuk diunduh, mis. "laporan.zip".
     */
    public function zipFilename(string $baseName): string
    {
        return $this->safeEntryName($baseName).'.zip';
    }
}
