<?php

namespace Tests\Feature;

use App\Support\ConversionStore;
use Illuminate\Http\UploadedFile;
use Tests\ReadsConversionResult;
use Tests\Support\PdfFactory;
use Tests\TestCase;
use ZipArchive;

/**
 * Test fitur pembeda: unduh hasil konversi sebagai ZIP yang dipecah
 * per halaman.
 */
class ZipDownloadTest extends TestCase
{
    use ReadsConversionResult;

    protected function setUp(): void
    {
        parent::setUp();

        // Ekstensi zip harus aktif agar file ZIP benar-benar bisa dibuat.
        // Kalau tidak, test dilewati - bukan dianggap lulus.
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped(
                'Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip di php.ini lalu jalankan ulang.'
            );
        }
    }

    private function convert(string $bytes, string $name = 'dokumen.pdf'): void
    {
        $this->post(route('pdf-to-markdown.convert'), [
            'file' => UploadedFile::fake()->createWithContent($name, $bytes),
            'page_separator' => '1',
        ])->assertSessionHasNoErrors();
    }

    /**
     * Helper: jalankan request download ZIP dan kembalikan isi filenya.
     */
    private function downloadZip(): string
    {
        $response = $this->get(route('pdf-to-markdown.zip'));

        $response->assertOk();

        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();

        // Simpan ke file sementara supaya bisa dibuka sebagai ZIP.
        $path = tempnam(sys_get_temp_dir(), 'zip-test');
        file_put_contents($path, $body);

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path) === true, 'Berkas hasil unduhan bukan ZIP yang valid');

            $names = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
            }

            $zip->close();
        } finally {
            @unlink($path);
        }

        $this->readEntries = $names;

        return $body;
    }

    /** @var array<string, string> */
    private array $readEntries = [];

    // =========================================================

    public function test_zip_berisi_satu_file_md_per_halaman(): void
    {
        $this->convert(PdfFactory::multiPage(['HALAMAN SATU', 'HALAMAN DUA', 'HALAMAN TIGA']));

        $this->downloadZip();

        ksort($this->readEntries);

        $this->assertArrayHasKey('dokumen-001.md', $this->readEntries);
        $this->assertArrayHasKey('dokumen-002.md', $this->readEntries);
        $this->assertArrayHasKey('dokumen-003.md', $this->readEntries);

        $this->assertStringContainsString('HALAMAN SATU', $this->readEntries['dokumen-001.md']);
        $this->assertStringContainsString('HALAMAN DUA', $this->readEntries['dokumen-002.md']);
        $this->assertStringContainsString('HALAMAN TIGA', $this->readEntries['dokumen-003.md']);
    }

    public function test_zip_berisi_juga_file_markdown_gabungan(): void
    {
        $this->convert(PdfFactory::multiPage(['SATU', 'DUA']));

        $this->downloadZip();

        $this->assertArrayHasKey('dokumen.md', $this->readEntries);

        // File gabungan sama persis dengan hasil download .md biasa.
        $this->assertSame(
            $this->resultPayloadOrFail()['markdown'],
            $this->readEntries['dokumen.md'],
        );
    }

    public function test_nomor_halaman_dengan_nol_di_depan(): void
    {
        $this->convert(PdfFactory::multiPage(array_map(
            fn (int $i): string => "HALAMAN {$i}",
            range(1, 12),
        )));

        $this->downloadZip();

        // 12 halaman -> halaman 10 harus jadi 010, bukan 10.
        $this->assertArrayHasKey('dokumen-010.md', $this->readEntries);
        $this->assertArrayHasKey('dokumen-012.md', $this->readEntries);
        $this->assertCount(13, $this->readEntries, '12 halaman + 1 file gabungan');
    }

    public function test_zip_menghormati_rentang_halaman(): void
    {
        $this->post(route('pdf-to-markdown.convert'), [
            'file' => UploadedFile::fake()->createWithContent(
                'dokumen.pdf',
                PdfFactory::multiPage(['SATU', 'DUA', 'TIGA', 'EMPAT']),
            ),
            'page_range' => '2-3',
            'page_separator' => '1',
        ])->assertSessionHasNoErrors();

        $this->downloadZip();

        // Hanya 2 halaman yang dikonversi, jadi hanya 2 file per halaman.
        $this->assertArrayHasKey('dokumen-001.md', $this->readEntries);
        $this->assertArrayHasKey('dokumen-002.md', $this->readEntries);
        $this->assertCount(3, $this->readEntries, '2 halaman + 1 file gabungan');

        $this->assertStringContainsString('DUA', $this->readEntries['dokumen-001.md']);
        $this->assertStringNotContainsString('SATU', $this->readEntries['dokumen-001.md']);
    }

    public function test_nama_zip_mengikuti_nama_file_asli(): void
    {
        $this->convert(PdfFactory::singlePage('Isi'), 'Laporan Tahunan.pdf');

        $response = $this->get(route('pdf-to-markdown.zip'));

        $response->assertOk();
        $response->assertDownload('Laporan Tahunan.zip');
    }

    public function test_nama_file_berbahaya_tidak_bocor_ke_nama_zip(): void
    {
        $this->convert(PdfFactory::singlePage('Isi'), '../../etc/passwd.pdf');

        $response = $this->get(route('pdf-to-markdown.zip'));

        $response->assertOk();

        $disposition = (string) $response->headers->get('content-disposition');

        $this->assertStringNotContainsString('..', $disposition);
        $this->assertStringNotContainsString('/', $disposition);
    }

    public function test_zip_tanpa_hasil_konversi_menampilkan_pesan(): void
    {
        $this->get(route('pdf-to-markdown.zip'))
            ->assertRedirect()
            ->assertSessionHasErrors('file');
    }

    public function test_zip_setelah_hasil_kedaluwarsa_menampilkan_pesan(): void
    {
        $this->convert(PdfFactory::singlePage('Isi'));

        $this->app->make(ConversionStore::class)->forget(session(ConversionStore::SESSION_KEY));

        $this->get(route('pdf-to-markdown.zip'))
            ->assertRedirect()
            ->assertSessionHasErrors('file');
    }

    public function test_tidak_ada_file_zip_yang_tersimpan_di_disk(): void
    {
        $tempDir = sys_get_temp_dir();

        $before = count(glob($tempDir.DIRECTORY_SEPARATOR.'pdf-md-zip*') ?: []);

        $this->convert(PdfFactory::multiPage(['SATU', 'DUA']));
        $this->downloadZip();

        $after = count(glob($tempDir.DIRECTORY_SEPARATOR.'pdf-md-zip*') ?: []);

        $this->assertSame($before, $after, 'File ZIP sementara tidak boleh tertinggal');
    }

    public function test_tombol_zip_muncul_di_halaman_hasil(): void
    {
        $this->convert(PdfFactory::singlePage('Isi'));

        $this->get(route('pdf-to-markdown'))
            ->assertOk()
            ->assertSee(route('pdf-to-markdown.zip'))
            ->assertSee('Unduh ZIP per halaman');
    }
}
