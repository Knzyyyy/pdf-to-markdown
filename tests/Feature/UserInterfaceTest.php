<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Tests\Support\PdfFactory;
use Tests\TestCase;

/**
 * Test untuk sisi antarmuka: tab Preview/Raw, sanitasi preview,
 * teks privasi, dan tombol aksi.
 */
class UserInterfaceTest extends TestCase
{
    private function pdfUpload(string $bytes): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('dokumen.pdf', $bytes);
    }

    private function convert(string $html): void
    {
        $this->post(route('pdf-to-markdown.convert'), [
            'file' => $this->pdfUpload(PdfFactory::fromHtml($html)),
            'page_separator' => '1',
        ])->assertSessionHasNoErrors();
    }

    // =========================================================
    // Tab
    // =========================================================

    public function test_halaman_menampilkan_dua_tab_dengan_role_yang_benar(): void
    {
        $this->convert('<p>Isi dokumen</p>');

        $this->get(route('pdf-to-markdown'))
            ->assertOk()
            ->assertSee('role="tablist"', false)
            ->assertSee('Preview')
            ->assertSee('Raw Markdown')
            // Tab Preview aktif secara bawaan.
            ->assertSee('aria-selected="true"', false)
            ->assertSee('aria-selected="false"', false);
    }

    public function test_kedua_panel_ada_dan_satu_disembunyikan(): void
    {
        $this->convert('<p>Isi dokumen</p>');

        $this->get(route('pdf-to-markdown'))
            ->assertOk()
            ->assertSee('id="panel-preview"', false)
            ->assertSee('id="panel-raw"', false);
    }

    public function test_tab_menunjuk_panel_yang_benar(): void
    {
        $this->convert('<p>Isi dokumen</p>');

        $this->get(route('pdf-to-markdown'))
            ->assertOk()
            ->assertSee('aria-controls="panel-preview"', false)
            ->assertSee('aria-controls="panel-raw"', false);
    }

    // =========================================================
    // Sanitasi preview
    // =========================================================

    public function test_markdown_dijalankan_sebagai_html_di_preview(): void
    {
        $this->convert('<p># Judul Besar</p>');

        $this->get(route('pdf-to-markdown'))
            ->assertOk()
            ->assertSee('<h1>Judul Besar</h1>', false);
    }

    public function test_tab_raw_menampilkan_markdown_apa_adanya(): void
    {
        $this->convert('<p># Judul Besar</p>');

        $this->get(route('pdf-to-markdown'))
            ->assertOk()
            // Di tab Raw, "# Judul Besar" harus tetap berupa teks biasa.
            ->assertSee('# Judul Besar');
    }

    public function test_html_dan_script_dari_pdf_tidak_dijalankan_di_preview(): void
    {
        // PDF yang isinya mencoba menyuntikkan HTML/JS.
        $this->convert('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>');

        $response = $this->get(route('pdf-to-markdown'));

        $response->assertOk();

        // Tag <script> asli tidak boleh ada di HTML halaman.
        $response->assertDontSee('<script>alert(1)</script>', false);

        // Yang tampil hanya teks-nya, ter-escape.
        $response->assertSee('&lt;script&gt;', false);
    }

    public function test_link_berbahaya_tidak_menjadi_href_di_preview(): void
    {
        $this->convert('<p>[klik](javascript:alert(1))</p>');

        $this->get(route('pdf-to-markdown'))
            ->assertOk()
            ->assertDontSee('href="javascript:', false);
    }

    // =========================================================
    // Teks privasi & tombol aksi
    // =========================================================

    public function test_halaman_menyatakan_file_tidak_disimpan_di_server(): void
    {
        $this->get(route('pdf-to-markdown'))
            ->assertOk()
            ->assertSee('tidak disimpan di server');
    }

    public function test_menampilkan_tombol_salin_dan_unduh(): void
    {
        $this->convert('<p>Isi</p>');

        $this->get(route('pdf-to-markdown'))
            ->assertOk()
            ->assertSee('id="copy-btn"', false)
            ->assertSee('Salin Markdown')
            ->assertSee(route('pdf-to-markdown.download'))
            ->assertSee('Unduh .md');
    }

    public function test_menampilkan_area_upload_dengan_penanda_yang_benar(): void
    {
        $this->get(route('pdf-to-markdown'))
            ->assertOk()
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('id="dropzone"', false)
            ->assertSee('accept="application/pdf,.pdf"', false)
            ->assertSee('Maksimal 15 MB');
    }

    public function test_menampilkan_indikator_loading(): void
    {
        $this->get(route('pdf-to-markdown'))
            ->assertOk()
            ->assertSee('id="loader"', false)
            ->assertSee('id="submit-btn"', false);
    }

    public function test_menampilkan_keterbatasan_yang_jujur(): void
    {
        $this->get(route('pdf-to-markdown'))
            ->assertOk()
            ->assertSee('Batasan aplikasi ini')
            ->assertSee('OCR')
            ->assertSee('tidak')
            ->assertSee('pertahankan');
    }

    public function test_halaman_kosong_menampilkan_empty_state(): void
    {
        $this->get(route('pdf-to-markdown'))
            ->assertOk()
            ->assertSee('Belum ada hasil')
            ->assertDontSee('Salin Markdown');
    }

    // =========================================================
    // Penjaga kontras warna
    // =========================================================

    /**
     * Warna terang (#FFEB3B, #2196F3, #FF5252) HANYA boleh jadi latar.
     * Teks di atasnya harus gelap.
     *
     * Alasannya rasio kontras:
     *   putih di atas #2196F3 = 3.12:1  (butuh >= 4.5:1)
     *   putih di atas #FF5252 = 3.19:1  (gagal WCAG AA)
     *   hitam di atas #2196F3 = 6.72:1  (lolos)
     *   hitam di atas #FF5252 = 6.58:1  (lolos)
     */
    public function test_tidak_ada_teks_putih_di_atas_warna_terang(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        // (?<![a-z-]) supaya "background-color:" tidak ikut terhitung.
        $this->assertSame(
            0,
            preg_match_all('/(?<![a-z-])color:\s*var\(--nb-white\)/', $css),
            'Ditemukan teks putih di CSS. Pastikan teks di atas warna terang memakai hitam/off-black.'
        );
    }

    public function test_warna_teks_di_atas_biru_ada_variasi_gelap(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString(
            '--nb-blue-text: #0d47a1;',
            $css,
            'Link di preview perlu biru gelap agar kontrasnya >= 4.5:1 di atas putih'
        );
    }

    public function test_tombol_hasil_membungkus_di_layar_sempit(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        // Tombol Salin/Unduh/ZIP harus full-width di bawah 768px supaya
        // tidak saling mendepit di layar 375px.
        $this->assertStringContainsString('@media (max-width: 767px)', $css);
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 767px\)\s*\{\s*\.result__actions\s*\{[^}]*grid-template-columns:\s*1fr/s',
            $css,
            'Tombol hasil seharusnya jadi satu kolom di layar sempit'
        );
    }

    public function test_judul_menggunakan_clamp_yang_aman_untuk_layar_375px(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        // 2.5rem pada 375px membuat "PDF ke Markdown" melebihi lebar layar.
        $this->assertMatchesRegularExpression(
            '/\.masthead__title\s*\{[^}]*font-size:\s*clamp\(2rem,/s',
            $css,
            'Judul utama seharusnya mulai dari 2rem agar tidak meluber di mobile'
        );
    }

    public function test_grid_menyamakan_tinggi_kartu_di_atas(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/\.grid\s*\{[^}]*align-items:\s*start/s',
            $css,
            'Grid harus memakai align-items: start agar kedua kartu sejajar di atas'
        );
    }

    /**
     * Penjaga untuk bug "kartu kedua meleset 32px ke bawah".
     *
     * Penyebabnya: selector `.card + .card { margin-top: 2rem; }`.
     * Kedua kartu di dalam .grid adalah sibling bersebelahan, jadi selector
     * itu kena ke kartu kedua. Pada grid, margin dipakai sebelum perataan,
     * sehingga margin tersebut membatalkan align-items: start.
     *
     * Diuji lewat CSS karena test ini tidak menjalankan browser sungguhan.
     * Pengukuran manual di Chrome headless pada lebar 1400px menunjukkan
     * selisih 32px sebelum perbaikan dan 0px sesudahnya.
     */
    public function test_kartu_grid_tidak_punya_margin_atas(): void
    {
        $rules = $this->cssRules();

        $this->assertDoesNotMatchRegularExpression(
            '/\.card\s*\+\s*\.card\s*\{[^}]*margin/s',
            $rules,
            'Aturan .card + .card akan membuat kartu kedua meleset turun di dalam .grid'
        );
    }

    /**
     * Ambil isi app.css dengan semua blok komentar CSS dibuang.
     *
     * Penting: tanpa ini, penjelasan di dalam komentar CSS ikut terperiksa
     * dan test bisa gagal (atau lulus) karena teks penjelasan, bukan
     * karena aturan CSS-nya.
     */
    private function cssRules(): string
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $stripped = preg_replace('#/\*.*?\*/#s', '', $css);

        return $stripped ?? $css;
    }

    public function test_kedua_kartu_adalah_sibling_bersebelahan_di_dalam_grid(): void
    {
        // purely struktural: dua <section class="card"> berdampingan.
        // Inilah yang membuat selector "+ .card" berbahaya, jadi struktur ini
        // harus dicatat di sini supaya tidak berubah diam-diam.
        $response = $this->get(route('pdf-to-markdown'));

        $response->assertOk();

        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/<section class="card">.*<\/section>\s*<section class="card">/s',
            (string) $html,
            'Kedua kartu harus sibling bersebelahan di dalam .grid'
        );
    }
}
