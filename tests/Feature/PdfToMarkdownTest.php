<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Tests\ReadsConversionResult;
use Tests\Support\PdfFactory;
use Tests\TestCase;

/**
 * Test untuk alur HTTP: upload -> validasi -> konversi -> tampilkan -> unduh.
 *
 * Catatan: tidak ada satupun test di sini yang menyentuh database,
 * jadi RefreshDatabase tidak dipakai.
 */
class PdfToMarkdownTest extends TestCase
{
    use ReadsConversionResult;
    // =========================================================
    // Halaman
    // =========================================================

    public function test_halaman_converter_bisa_diakses(): void
    {
        $response = $this->get(route('pdf-to-markdown'));

        $response->assertOk();
        $response->assertViewIs('pdf-to-markdown');
        $response->assertSee('PDF ke Markdown');
        $response->assertSee('Maksimal 15 MB');
    }

    public function test_route_halaman_utama_laravel_tetap_berfungsi(): void
    {
        // Penjaga agar penambahan route baru tidak merusak route bawaan.
        $this->get('/')->assertOk();
    }

    // =========================================================
    // Upload valid
    // =========================================================

    public function test_pdf_valid_dikonversi_dan_ditampilkan(): void
    {
        $response = $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::singlePage('Dokumen uji coba')),
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $result = $this->resultPayloadOrFail();

        $this->assertIsArray($result);
        $this->assertSame('Dokumen uji coba', trim($result['markdown']));
        $this->assertSame(1, $result['page_count']);
        $this->assertSame('dokumen.md', $result['filename']);
    }

    public function test_tampilan_menampilkan_konten_hasil_konversi(): void
    {
        $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::singlePage('Teks harus terlihat')),
        ]);

        $response = $this->get(route('pdf-to-markdown'));

        $response->assertOk();
        $response->assertSee('Teks harus terlihat');
        $response->assertSee('Hasil konversi');
        $response->assertSee(route('pdf-to-markdown.download'));
    }

    public function test_halaman_terpisah_oleh_garis_horizontal(): void
    {
        $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::multiPage(['HALAMAN SATU', 'HALAMAN DUA', 'HALAMAN TIGA'])),
            // Checkbox pemisah harus dikirim eksplisit. Kalau tidak dicentang,
            // HTML tidak mengirim field ini sama sekali.
            'page_separator' => '1',
        ]);

        $markdown = $this->resultPayloadOrFail()['markdown'];

        $this->assertSame(3, $this->resultPayloadOrFail()['page_count']);
        $this->assertStringContainsString('HALAMAN SATU', $markdown);
        $this->assertStringContainsString('HALAMAN TIGA', $markdown);

        // Tiga halaman -> dua pemisah "---".
        $this->assertSame(2, substr_count($markdown, "\n\n---\n\n"));
    }

    public function test_statistik_kata_dan_token_tersedia(): void
    {
        $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::singlePage('satu dua tiga empat lima')),
        ]);

        $result = $this->resultPayloadOrFail();

        $this->assertSame(5, $result['words']);
        $this->assertGreaterThan(0, $result['characters']);
        $this->assertSame((int) ceil($result['characters'] / 4), $result['estimated_tokens']);
    }

    // =========================================================
    // Penolakan file
    // =========================================================

    public function test_file_bukan_pdf_ditolak(): void
    {
        $response = $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::fakePdf()),
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertNull($this->resultPayload());
    }

    public function test_file_gambar_ditolak(): void
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );

        $response = $this->post(route('pdf-to-markdown.convert'), [
            'file' => UploadedFile::fake()->createWithContent('gambar.png', $png),
        ]);

        $response->assertSessionHasErrors('file');
    }

    public function test_tidak_ada_file_yang_diupload_ditolak(): void
    {
        $response = $this->post(route('pdf-to-markdown.convert'), []);

        $response->assertSessionHasErrors('file');
    }

    public function test_file_lebih_dari_15mb_ditolak(): void
    {
        // create() menghasilkan file kosong berukuran 16 MB (dalam kilobyte).
        $tooBig = UploadedFile::fake()->create('besar.pdf', 16 * 1024, 'application/pdf');

        $response = $this->post(route('pdf-to-markdown.convert'), [
            'file' => $tooBig,
        ]);

        $response->assertSessionHasErrors('file');
    }

    // =========================================================
    // Kasus PDF bermasalah
    // =========================================================

    public function test_pdf_rusak_memberi_pesan_yang_jelas(): void
    {
        $response = $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::corrupted()),
        ]);

        $response->assertSessionHasErrors('file');
        $response->assertSessionHasErrors([
            'file' => 'File PDF tidak dapat dibaca. Pastikan file tidak rusak atau terpotong saat diunduh.',
        ]);
        $this->assertNull($this->resultPayload());
    }

    public function test_pdf_terenkripsi_memberi_pesan_bahwa_butuh_kata_sandi(): void
    {
        $response = $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::encrypted()),
        ]);

        $response->assertSessionHasErrors([
            'file' => 'File PDF ini diproteksi dengan kata sandi (terenkripsi). Silakan hapus proteksinya lalu coba lagi.',
        ]);
    }

    public function test_pdf_tanpa_teks_memberi_pesan_tentang_ocr(): void
    {
        $response = $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::withoutText()),
        ]);

        $response->assertSessionHasErrors('file');

        $error = session('errors')->first('file');

        $this->assertStringContainsString('Tidak ada teks yang dapat diekstrak', $error);
        $this->assertStringContainsString('OCR', $error);
    }

    // =========================================================
    // Download
    // =========================================================

    public function test_download_mengembalikan_file_markdown(): void
    {
        $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::singlePage('Isi untuk diunduh')),
        ]);

        $response = $this->get(route('pdf-to-markdown.download'));

        $response->assertOk();
        $response->assertDownload('dokumen.md');

        ob_start();
        $response->sendContent();
        $body = ob_get_clean();

        $this->assertSame('Isi untuk diunduh', trim((string) $body));
    }

    public function test_download_tanpa_hasil_konversi_menampilkan_pesan(): void
    {
        $response = $this->get(route('pdf-to-markdown.download'));

        $response->assertRedirect();
        $response->assertSessionHasErrors([
            'file' => 'Hasil konversi tidak ditemukan atau sudah kedaluwarsa (maksimal 15 menit). Silakan konversi ulang.',
        ]);
    }

    // =========================================================
    // Kebersihan: file tidak boleh tersimpan permanen
    // =========================================================

    public function test_tidak_ada_file_pdf_yang_tersimpan_di_storage(): void
    {
        $before = $this->countStoredFiles();

        $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::singlePage('Tidak boleh tersimpan')),
        ]);

        $this->assertSame($before, $this->countStoredFiles());
    }

    // =========================================================
    // Helper
    // =========================================================

    /**
     * Bungkus string byte PDF menjadi UploadedFile.
     *
     * createWithContent() menulis isi yang benar-benar ada di file,
     * berbeda dengan create() yang membuat file kosong.
     */
    private function pdfUpload(string $bytes): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('dokumen.pdf', $bytes);
    }

    /**
     * @return int jumlah file di storage/app
     */
    private function countStoredFiles(): int
    {
        $files = [];

        foreach (['app', 'app/public'] as $folder) {
            $path = storage_path($folder);

            if (! is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return count($files);
    }
}
