<?php

namespace Tests\Feature;

use App\Support\ConversionStore;
use Illuminate\Http\UploadedFile;
use Tests\ReadsConversionResult;
use Tests\Support\PdfFactory;
use Tests\TestCase;

/**
 * Test keamanan: rate limiting, penyimpanan hasil sementara,
 * dan penolakan berbagai jenis file berbahaya.
 */
class SecurityTest extends TestCase
{
    use ReadsConversionResult;

    private function pdfUpload(string $bytes, string $name = 'dokumen.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    // =========================================================
    // Rate limiting
    // =========================================================

    public function test_konversi_dibatasi_maksimal_10_kali_per_menit(): void
    {
        // Kirim 10 request (batasnya). Semuanya harus berhasil.
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('pdf-to-markdown.convert'), [
                'file' => $this->pdfUpload(PdfFactory::singlePage("Dokumen nomor {$i}")),
            ])->assertSessionHasNoErrors();
        }

        // Request ke-11 harus ditolak.
        $response = $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::singlePage('Dokumen nomor 11')),
        ]);

        $response->assertStatus(429);
    }

    public function test_halaman_tetap_bisa_diakses_saat_rate_limit_aktif(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('pdf-to-markdown.convert'), [
                'file' => $this->pdfUpload(PdfFactory::singlePage("Dokumen {$i}")),
            ]);
        }

        $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::singlePage('Dokumen 11')),
        ])->assertStatus(429);

        // Halaman GET tidak memakai throttle, jadi harus tetap jalan.
        $this->get(route('pdf-to-markdown'))->assertOk();
    }

    // =========================================================
    // Penyimpanan hasil sementara
    // =========================================================

    public function test_hasil_konversi_tidak_disimpan_penuh_di_session(): void
    {
        // PDF dengan teks panjang supaya hasilnya jauh melewati batas 4 KB.
        $longText = str_repeat('Kalimat panjang untuk menguji ukuran hasil konversi. ', 400);

        $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::singlePage($longText)),
        ])->assertSessionHasNoErrors();

        // Yang ada di session HANYA kunci acak, bukan isi dokumen.
        $session = session()->all();
        $serialized = serialize($session);

        $this->assertArrayHasKey(ConversionStore::SESSION_KEY, $session);
        $this->assertLessThan(
            4096,
            strlen($serialized),
            'Session terlalu besar - hasil konversi tidak boleh masuk ke session'
        );

        // Teks dokumen tidak boleh ada di dalam data session.
        $this->assertStringNotContainsString('Kalimat panjang untuk menguji', $serialized);
    }

    public function test_kunci_yang_disimpan_adalah_kunci_acak(): void
    {
        $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::singlePage('Isi')),
        ]);

        $key = session(ConversionStore::SESSION_KEY);

        $this->assertIsString($key);
        $this->assertSame(64, strlen($key), 'Kunci harus 64 karakter hex (32 byte acak)');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $key);
    }

    public function test_kunci_berbeda_untuk_setiap_konversi(): void
    {
        $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::singlePage('Satu')),
        ]);
        $first = session(ConversionStore::SESSION_KEY);

        $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::singlePage('Dua')),
        ]);
        $second = session(ConversionStore::SESSION_KEY);

        $this->assertNotSame($first, $second);
    }

    public function test_hasil_kedaluwarsa_memberi_pesan_dan_bukan_error(): void
    {
        $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::singlePage('Isi')),
        ]);

        // Simulasikan hasil yang sudah hilang dari cache (mis. lewat 15 menit).
        $this->app->make(ConversionStore::class)->forget(session(ConversionStore::SESSION_KEY));

        $this->get(route('pdf-to-markdown.download'))
            ->assertRedirect()
            ->assertSessionHasErrors('file');

        $this->get(route('pdf-to-markdown'))->assertOk();
    }

    public function test_kunci_tidak_boleh_dibuat_ngawur_untuk_mencuri_hasil(): void
    {
        // Seseorang menebak kunci milik orang lain.
        session()->put(ConversionStore::SESSION_KEY, str_repeat('a', 64));

        $this->get(route('pdf-to-markdown.download'))
            ->assertRedirect()
            ->assertSessionHasErrors('file');
    }

    public function test_halaman_tanpa_session_tidak_menampilkan_hasil_orang_lain(): void
    {
        $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::singlePage('Rahasia pengguna lain')),
        ]);

        // Browser baru tanpa session sama sekali.
        $this->flushSession();

        $this->get(route('pdf-to-markdown'))
            ->assertOk()
            ->assertDontSee('Rahasia pengguna lain');
    }

    // =========================================================
    // Penolakan file berbahaya
    // =========================================================

    public function test_file_ber_extensi_pdf_tapi_isi_bukan_pdf_ditolak(): void
    {
        // Classic: rename file .txt menjadi .pdf
        $response = $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload('<?php echo "hai"; ?>'),
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertNull($this->resultPayload());
    }

    public function test_file_php_dengan_nama_pdf_ditolak(): void
    {
        $response = $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload('<?php system($_GET["c"]); ?>', 'shell.pdf'),
        ]);

        $response->assertSessionHasErrors('file');
    }

    public function test_file_phar_dengan_nama_pdf_ditolak(): void
    {
        $response = $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload('<?php __HALT_COMPILER(); ?>', 'jahat.pdf'),
        ]);

        $response->assertSessionHasErrors('file');
    }

    public function test_file_tidak_bukan_pdf_ditolak(): void
    {
        $response = $this->post(route('pdf-to-markdown.convert'), [
            'file' => UploadedFile::fake()->createWithContent('catatan.txt', 'file teks biasa'),
        ]);

        $response->assertSessionHasErrors('file');
    }

    public function test_nama_file_berbahaya_jadi_nama_tanpa_path(): void
    {
        // Nama file berisi path traversal dan karakter aneh.
        $response = $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(
                PdfFactory::singlePage('Isi dokumen'),
                '../../../../etc/passwd.pdf',
            ),
        ])->assertSessionHasNoErrors();

        $filename = $this->resultPayloadOrFail()['filename'];

        // Tidak boleh ada "../" atau "/" pada nama file unduhan.
        $this->assertStringNotContainsString('..', $filename);
        $this->assertStringNotContainsString('/', $filename);
        $this->assertStringEndsWith('.md', $filename);
    }

    public function test_tidak_ada_file_yang_tersimpan_di_storage_setelah_konversi(): void
    {
        $storage = storage_path('app');

        $before = $this->pdfCountIn($storage);

        for ($i = 0; $i < 3; $i++) {
            $this->post(route('pdf-to-markdown.convert'), [
                'file' => $this->pdfUpload(PdfFactory::singlePage("Dokumen {$i}")),
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame($before, $this->pdfCountIn($storage), 'File PDF tidak boleh tersimpan permanen');
    }

    public function test_csrf_token_tetap_diperlukan(): void
    {
        $middleware = $this->convertRouteMiddleware();

        // Dicocokkan berdasarkan nama kelas berakhiran, supaya test ini
        // tetap benar meski Laravel memakai VerifyCsrfToken bawaan
        // atau versi milik aplikasi sendiri.
        $hasCsrf = collect($middleware)
            ->contains(fn (string $class) => str_ends_with($class, 'VerifyCsrfToken'));

        $this->assertTrue($hasCsrf, 'Route konversi harus memakai middleware CSRF dari group "web"');
    }

    public function test_route_konversi_memakai_rate_limit(): void
    {
        $middleware = $this->convertRouteMiddleware();

        $hasThrottle = collect($middleware)
            ->contains(fn (string $class) => str_ends_with($class, 'ThrottleRequests:10,1'));

        $this->assertTrue($hasThrottle, 'Route konversi harus dibatasi 10 request per menit');
    }

    /**
     * Daftar middleware lengkap untuk route POST /pdf-to-markdown,
     * termasuk middleware dari group "web".
     *
     * @return array<int, string>
     */
    private function convertRouteMiddleware(): array
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'pdf-to-markdown'
                && in_array('POST', $route->methods()));

        $this->assertNotNull($route, 'Route POST /pdf-to-markdown tidak ditemukan');

        // PENTING: gatherRouteMiddleware() (bukan gatherMiddleware()) karena
        // middleware CSRF berada di group "web", bukan menempel di route.
        return app('router')->gatherRouteMiddleware($route);
    }

    // =========================================================
    // Helper
    // =========================================================

    private function pdfCountIn(string $path): int
    {
        if (! is_dir($path)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'pdf') {
                $count++;
            }
        }

        return $count;
    }
}
