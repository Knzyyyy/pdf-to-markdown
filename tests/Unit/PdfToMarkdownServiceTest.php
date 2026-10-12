<?php

namespace Tests\Unit;

use App\Exceptions\ConversionException;
use App\Services\PdfToMarkdownService;
use Tests\Support\PdfFactory;
use Tests\TestCase;

/**
 * Test langsung terhadap PdfToMarkdownService, tanpa lewat HTTP.
 *
 * TUJUANNYA: memastikan konversi gagal dengan pesan yang tepat untuk
 * berbagai kondisi file bermasalah.
 */
class PdfToMarkdownServiceTest extends TestCase
{
    private PdfToMarkdownService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new PdfToMarkdownService;
    }

    public function test_menghasilkan_markdown_dari_pdf_satu_halaman(): void
    {
        $result = $this->convert(PdfFactory::singlePage('Halo dunia'));

        $this->assertSame('Halo dunia', trim($result->markdown));
        $this->assertSame(1, $result->pageCount());
    }

    public function test_menghasilkan_markdown_dari_pdf_beberapa_halaman(): void
    {
        $result = $this->convert(PdfFactory::multiPage(['SATU', 'DUA', 'TIGA']));

        $this->assertSame(3, $result->pageCount());
        $this->assertStringContainsString('SATU', $result->markdown);
        $this->assertStringContainsString('DUA', $result->markdown);
        $this->assertStringContainsString('TIGA', $result->markdown);
    }

    public function test_memisahkan_halaman_dengan_garis_horizontal(): void
    {
        $result = $this->convert(PdfFactory::multiPage(['SATU', 'DUA']));

        $this->assertStringContainsString("SATU\n\n---\n\nDUA", $result->markdown);
    }

    public function test_membuang_baris_kosong_berlebih(): void
    {
        $html = '<html><body><p>Baris pertama</p><p>&nbsp;</p><p>Baris kedua</p></body></html>';

        $result = $this->convert(PdfFactory::fromHtml($html));

        // Tidak boleh ada 3 newline berurutan.
        $this->assertStringNotContainsString("\n\n\n", $result->markdown);
        $this->assertStringContainsString('Baris pertama', $result->markdown);
        $this->assertStringContainsString('Baris kedua', $result->markdown);
    }

    public function test_melempar_exception_untuk_pdf_rusak(): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('File PDF tidak dapat dibaca.');

        $this->convert(PdfFactory::corrupted());
    }

    public function test_melempar_exception_untuk_pdf_terenkripsi(): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('terenkripsi');

        $this->convert(PdfFactory::encrypted());
    }

    public function test_melempar_exception_untuk_pdf_tanpa_teks(): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('Tidak ada teks yang dapat diekstrak');

        $this->convert(PdfFactory::withoutText());
    }

    public function test_melempar_exception_untuk_file_yang_bukan_pdf(): void
    {
        $this->expectException(ConversionException::class);

        $this->convert(PdfFactory::fakePdf());
    }

    public function test_melempar_exception_untuk_file_yang_tidak_ada(): void
    {
        $this->expectException(ConversionException::class);

        $this->convert(storage_path('app/tidak-ada-file-ini.pdf'));
    }

    // =========================================================
    // Nama file output
    // =========================================================

    /**
     * @dataProvider filenameProvider
     */
    public function test_membuat_nama_file_md_aman(string $input, string $expected): void
    {
        $result = $this->convert(PdfFactory::singlePage('isi'));

        $this->assertSame($expected, $result->filenameFor($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function filenameProvider(): array
    {
        return [
            'nama biasa' => ['laporan.pdf', 'laporan.md'],

            'punya spasi' => ['Data Penjualan 2024.pdf', 'Data Penjualan 2024.md'],

            // ">" dan "<" dibuang, dan hanya sisa "script" yang lolos.
            'karakter HTML dibuang' => ['<script>evil</script>.pdf', 'script.md'],

            // Path traversal dihapus total oleh basename(),
            // sehingga hanya "passwd" yang tersisa - tidak ada "../".
            'path traversal dibuang' => ['../../etc/passwd.pdf', 'passwd.md'],

            'nama kosong' => ['.pdf', 'dokumen.md'],
            'hanya spasi dibuang' => ['   .pdf', 'dokumen.md'],

            // Nama file Windows yang tidak valid dibuang.
            'karakter invalid windows dibuang' => ['nama:resume*judul?.pdf', 'namaresumejudul.md'],
        ];
    }

    // =========================================================
    // Helper
    // =========================================================

    private function convert(string $bytes): \App\Support\ConversionResult
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf-md-test');
        file_put_contents($path, $bytes);

        try {
            return $this->service->convert($path);
        } finally {
            // File sementara selalu dibersihkan, termasuk saat test gagal.
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
