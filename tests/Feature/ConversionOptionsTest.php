<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Tests\ReadsConversionResult;
use Tests\Support\PdfFactory;
use Tests\TestCase;

/**
 * Test fitur: statistik dokumen dan opsi konversi
 * (rentang halaman + toggle pemisah antar halaman).
 */
class ConversionOptionsTest extends TestCase
{
    use ReadsConversionResult;

    private function pdfUpload(string $bytes): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('dokumen.pdf', $bytes);
    }

    // =========================================================
    // Statistik
    // =========================================================

    public function test_menampilkan_jumlah_halaman_kata_karakter_dan_token(): void
    {
        $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::multiPage([
                'satu dua tiga',
                'empat lima',
            ])),
        ]);

        $result = $this->resultPayloadOrFail();

        $this->assertSame(2, $result['page_count']);
        $this->assertSame(5, $result['words']);
        $this->assertGreaterThan(0, $result['characters']);

        // Token = karakter / 4, dibulatkan ke atas, dan diberi label perkiraan.
        $this->assertSame((int) ceil($result['characters'] / 4), $result['estimated_tokens']);
    }

    public function test_label_token_menyatakan_itu_perkiraan(): void
    {
        $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::singlePage('isi')),
        ]);

        $this->get(route('pdf-to-markdown'))
            ->assertOk()
            ->assertSee('Token (perkiraan)');
    }

    // =========================================================
    // Rentang halaman
    // =========================================================

    public function test_rentang_halaman_membatasi_halaman_yang_dikonversi(): void
    {
        $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::multiPage([
                'HALAMAN SATU',
                'HALAMAN DUA',
                'HALAMAN TIGA',
                'HALAMAN EMPAT',
            ])),
            'page_range' => '2-3',
        ]);

        $result = $this->resultPayloadOrFail();

        $this->assertSame(2, $result['page_count']);
        $this->assertSame(4, $result['total_pages']);
        $this->assertStringContainsString('HALAMAN DUA', $result['markdown']);
        $this->assertStringContainsString('HALAMAN TIGA', $result['markdown']);

        // Di luar rentang: tidak ikut.
        $this->assertStringNotContainsString('HALAMAN SATU', $result['markdown']);
        $this->assertStringNotContainsString('HALAMAN EMPAT', $result['markdown']);
    }

    public function test_rentang_halaman_satu_halaman(): void
    {
        $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::multiPage(['SATU', 'DUA', 'TIGA'])),
            'page_range' => '3',
        ]);

        $result = $this->resultPayloadOrFail();

        $this->assertSame(1, $result['page_count']);
        $this->assertStringContainsString('TIGA', $result['markdown']);
        $this->assertStringNotContainsString('DUA', $result['markdown']);
    }

    public function test_tanpa_rentang_semua_halaman_dikonversi(): void
    {
        $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::multiPage(['SATU', 'DUA', 'TIGA'])),
            'page_range' => '',
        ]);

        $this->assertSame(3, $this->resultPayloadOrFail()['page_count']);
        $this->assertNull($this->resultPayloadOrFail()['page_range']);
    }

    public function test_rentang_halaman_di_luar_jangkauan_ditolak_dengan_pesan_jelas(): void
    {
        $response = $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::multiPage(['SATU', 'DUA'])),
            'page_range' => '10-15',
        ]);

        $response->assertSessionHasErrors('file');
        $response->assertSessionHasErrors([
            'file' => 'Rentang halaman 10–15 tidak tersedia. Dokumen ini hanya memiliki 2 halaman.',
        ]);
        $this->assertNull($this->resultPayload());
    }

    public function test_rentang_halaman_dengan_urutan_terbalik_ditolak(): void
    {
        $response = $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::singlePage('isi')),
            'page_range' => '5-2',
        ]);

        $response->assertSessionHasErrors([
            'page_range' => 'Nomor halaman awal tidak boleh lebih besar dari halaman akhir.',
        ]);
    }

    /**
     * @dataProvider invalidRangeProvider
     */
    public function test_format_rentang_halaman_tidak_valid_ditolak(string $range): void
    {
        $response = $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::singlePage('isi')),
            'page_range' => $range,
        ]);

        $response->assertSessionHasErrors('page_range');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidRangeProvider(): array
    {
        return [
            'huruf' => ['abc'],
            'minus saja' => ['-'],
            'tiga bilangan' => ['1-2-3'],
            'pakai koma' => ['2,5'],
            'ada huruf di tengah' => ['2-abc'],
            'script' => ['<script>1</script>'],
        ];
    }

    public function test_rentang_halaman_ditampilkan_di_halaman_hasil(): void
    {
        $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::multiPage(['SATU', 'DUA', 'TIGA'])),
            'page_range' => '1-2',
        ]);

        $this->get(route('pdf-to-markdown'))
            ->assertOk()
            ->assertSee('Hanya halaman 1–2 yang dikonversi')
            ->assertSee('dari total 3 halaman');
    }

    public function test_form_menampilkan_kembali_rentang_halaman_setelah_error(): void
    {
        $this->from(route('pdf-to-markdown'))->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::singlePage('isi')),
            'page_range' => '5-2',
        ])->assertSessionHasErrors('page_range');

        // old() mengisi ulang nilai input supaya tidak perlu mengetik ulang.
        $this->get(route('pdf-to-markdown'))
            ->assertOk()
            ->assertSee('value="5-2"', false);
    }

    // =========================================================
    // Toggle pemisah antar halaman
    // =========================================================

    public function test_pemisah_halaman_aktif_secara_bawaan(): void
    {
        $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::multiPage(['SATU', 'DUA'])),
            'page_separator' => '1',
        ]);

        $this->assertStringContainsString("\n\n---\n\n", $this->resultPayloadOrFail()['markdown']);
    }

    public function test_pemisah_halaman_bisa_dimatikan(): void
    {
        $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::multiPage(['SATU', 'DUA'])),
            // Checkbox tidak dicocentak -> nilainya tidak terkirim sama sekali.
            'page_separator' => '0',
        ]);

        $markdown = $this->resultPayloadOrFail()['markdown'];

        $this->assertStringNotContainsString('---', $markdown);

        // Kedua halaman tetap ada, hanya dipisah baris kosong biasa.
        $this->assertStringContainsString('SATU', $markdown);
        $this->assertStringContainsString('DUA', $markdown);
    }

    public function test_checkbox_pemisah_tidak_terkirim_tetap_berfungsi(): void
    {
        // Simulasi checkbox yang benar tidak dicentang: field tidak ada.
        $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::multiPage(['SATU', 'DUA'])),
        ]);

        $this->assertStringNotContainsString('---', $this->resultPayloadOrFail()['markdown']);
    }

    public function test_checkbox_pemisah_tercentang_terjaga_setelah_error(): void
    {
        $this->from(route('pdf-to-markdown'))->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::singlePage('isi')),
            'page_separator' => '1',
            'page_range' => 'abc',
        ])->assertSessionHasErrors('page_range');

        $this->get(route('pdf-to-markdown'))
            ->assertOk()
            ->assertSee('name="page_separator"', false)
            ->assertSee('checked', false);
    }
}
