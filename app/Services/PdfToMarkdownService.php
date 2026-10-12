<?php

namespace App\Services;

use App\Exceptions\ConversionException;
use App\Services\Markdown\MarkdownFormatter;
use App\Support\ConversionOptions;
use App\Support\ConversionResult;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Otak konversi PDF -> Markdown.
 *
 * Tanggung jawabnya HANYA mengubah file PDF (path absolut di disk) menjadi
 * objek ConversionResult. Tidak menyentuh HTTP, tidak menyentuh session,
 * tidak menyentuh database. Itu urusan Controller.
 */
class PdfToMarkdownService
{
    /** Pemisah antar halaman. "---" = horizontal rule di Markdown. */
    public const PAGE_SEPARATOR = "\n\n---\n\n";

    public function __construct(
        private readonly MarkdownFormatter $formatter = new MarkdownFormatter,
    ) {}

    /**
     * @param  string  $absolutePath  Path absolut file PDF sementara di disk.
     * @param  ConversionOptions|null  $options  Pilihan pengguna (rentang halaman, pemisah).
     *
     * @throws ConversionException
     */
    public function convert(string $absolutePath, ?ConversionOptions $options = null): ConversionResult
    {
        $options ??= ConversionOptions::default();

        $this->guardAgainstEncryptedPdf($absolutePath);

        $config = new Config;

        // Kita hanya butuh teks. Membuang data gambar mentah menghemat
        // penggunaan memori secara signifikan untuk PDF besar.
        $config->setRetainImageContent(false);

        // Catatan: pada smalot/pdfparser v2.12, Config dioper lewat
        // parameter KEDUA (parameter pertama adalah array konfigurasi legacy).
        $parser = new Parser([], $config);

        try {
            $document = $parser->parseFile($absolutePath);

            $pages = [];

            foreach ($document->getPages() as $page) {
                // getPages() mengembalikan objek Page; halaman kosong bisa null.
                $pages[] = $this->cleanPageText($page === null ? '' : $page->getText());
            }
        } catch (Throwable) {
            // Sengaja tidak menyertakan pesan asli exception: bisa memuat detail
            // internal. Pengguna cukup tahu file-nya tidak terbaca.
            throw ConversionException::unreadable();
        }

        if ($pages === [] || $this->containsNoText($pages)) {
            throw ConversionException::noExtractableText();
        }

        $totalPages = count($pages);

        // Cek rentang SETELAH dokumen di-parse, karena hanya sekarang
        // kita tahu berapa halaman yang sebenarnya.
        if (! $options->selectsWholeDocument() && ($options->fromPage ?? 1) > $totalPages) {
            throw ConversionException::pageRangeOutOfBounds(
                $options->describeRange() ?? '',
                $totalPages,
            );
        }

        $selected = $options->slicePages($pages);

        if ($this->containsNoText($selected)) {
            throw ConversionException::noExtractableText();
        }

        return new ConversionResult(
            pages: $this->formatter->formatPages($selected),
            separator: $options->separator(self::PAGE_SEPARATOR),
            totalPagesInDocument: $options->selectsWholeDocument() ? null : $totalPages,
        );
    }

    /**
     * Deteksi PDF terenkripsi dengan membaca kilobyte pertama file.
     *
     * PDF terenkripsi selalu punya dictionary "/Encrypt". Kita cek sebelum
     * memanggil parser supaya bisa memberi pesan yang tepat (butuh kata sandi)
     * alih-alih error umum.
     */
    private function guardAgainstEncryptedPdf(string $absolutePath): void
    {
        $head = @file_get_contents($absolutePath, false, null, 0, 4096);

        if ($head === false) {
            throw ConversionException::unreadable();
        }

        if (str_contains($head, '/Encrypt')) {
            throw ConversionException::encrypted();
        }
    }

    /**
     * Pembersihan dasar: samakan akhir baris, buang spasi di akhir baris,
     * dan rangkum baris kosong berlebih jadi maksimal 1 baris kosong.
     *
     * Perhatikan hasil uji: PDF tanpa teks tetap menghasilkan " " (spasi),
     * jadi baris spasi harus ikut dibuang, bukan hanya trim overall.
     */
    private function cleanPageText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Ubah baris yang isinya hanya spasi jadi baris kosong.
        $text = preg_replace('/^[ \t]+$/m', '', $text) ?? $text;

        $text = preg_replace('/[ \t]+$/m', '', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @param  array<int, string>  $pages
     */
    private function containsNoText(array $pages): bool
    {
        foreach ($pages as $page) {
            if (trim($page) !== '') {
                return false;
            }
        }

        return true;
    }
}
