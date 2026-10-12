<?php

namespace App\Support;

/**
 * Render Markdown menjadi HTML untuk ditampilkan di tab "Preview".
 *
 * ============ KENAPA TIDAK PAKAI LIBRARY MARKDOWN? ============
 * Mayoritas library Markdown mengizinkan HTML mentah di dalam dokumen.
 * Karena isi hasil konversi berasal dari PDF milik orang lain yang tidak
 * kita percaya, HTML mentah berarti celah XSS (mis. <script> atau
 * <img onerror=...>). CommonMark pun perlu konfigurasi ekstra untuk
 * menutup celah ini.
 *
 * Di sini kita memakai pendekatan yang aman secara konstruksi:
 *   LANGKAH 1 - escape seluruh teks lebih dulu (htmlspecialchars).
 *               Desde titik ini, tidak ada "<" yang bisa menjadi tag HTML.
 *   LANGKAH 2 - baru sisipkan tag HTML yang kita tulis sendiri
 *               berdasarkan pola Markdown tersebut.
 * Hasilnya: mustahil ada HTML asing yang lolos ke browser.
 *
 * ============ KETERBATASAN ============
 * Ini BUKAN renderer Markdown penuh. Yang didukung hanya yang dipakai
 * konverter ini: heading, list, paragraf, garis horizontal, kode,
 * bold, italic, strikethrough, dan link. Tabel dan gambar TIDAK
 * ditampilkan di preview.
 */
class MarkdownRenderer
{
    /** Skema URL yang boleh dipakai pada link. */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto'];

    /** Panjang maksimum preview agar halaman tidak melambat. */
    public const MAX_PREVIEW_LENGTH = 200_000;

    /**
     * Pintasan statis supaya Blade bisa memanggil dengan satu baris.
     *
     * CATATAN PENTING untuk pemakaian di Blade:
     *   {!! MarkdownRenderer::render($teks) !!}
     *
     * Menggunakan {!! ... !!} (tanpa escape) terlihat berbahaya, tapi di sini
     * itu aman karena render() sudah meng-escape SELURUH teks sebelum
     * menambahkan tag. Yang keluar dari fungsi ini hanya tag yang kita
     * tulis sendiri di kelas ini.
     */
    public static function render(string $markdown): string
    {
        return (new self)->toHtml($markdown);
    }

    public function toHtml(string $markdown): string
    {
        if (strlen($markdown) > self::MAX_PREVIEW_LENGTH) {
            $markdown = substr($markdown, 0, self::MAX_PREVIEW_LENGTH)."\n\n… (preview dipotong)";
        }

        // LANGKAH 1: escape semua. Semua fungsi di bawahnya hanya menambah
        // tag yang kita tulis sendiri.
        $safe = htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = [];
        $lines = explode("\n", $safe);
        $count = count($lines);
        $inCodeFence = false;
        $codeLines = [];
        $paragraph = [];
        $listType = null;
        $listItems = [];

        $flushParagraph = function () use (&$paragraph, &$html): void {
            if ($paragraph === []) {
                return;
            }

            $text = $this->renderInline(implode(' ', $paragraph));
            $html[] = '<p>'.$text.'</p>';
            $paragraph = [];
        };

        $flushList = function () use (&$listItems, &$listType, &$html): void {
            if ($listItems === [] || $listType === null) {
                return;
            }

            $tag = $listType === 'ul' ? 'ul' : 'ol';
            $items = '';

            foreach ($listItems as $item) {
                $items .= '<li>'.$this->renderInline($item).'</li>';
            }

            $html[] = '<'.$tag.'>'.$items.'</'.$tag.'>';
            $listItems = [];
            $listType = null;
        };

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            // --- Blok kode ---
            if (str_starts_with(trim($line), '```')) {
                if ($inCodeFence) {
                    $html[] = '<pre><code>'.implode("\n", $codeLines).'</code></pre>';
                    $codeLines = [];
                    $inCodeFence = false;
                } else {
                    $flushParagraph();
                    $flushList();
                    $inCodeFence = true;
                }

                continue;
            }

            if ($inCodeFence) {
                $codeLines[] = $line;

                continue;
            }

            // --- Baris kosong menutup paragraf dan list ---
            if (trim($line) === '') {
                $flushParagraph();
                $flushList();

                continue;
            }

            // --- Heading: # sampai ###### ---
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m) === 1) {
                $flushParagraph();
                $flushList();

                $level = strlen($m[1]);
                $html[] = "<h{$level}>".$this->renderInline($m[2])."</h{$level}>";

                continue;
            }

            // --- Garis horizontal: --- atau *** atau ___ ---
            if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', trim($line)) === 1) {
                $flushParagraph();
                $flushList();
                $html[] = '<hr>';

                continue;
            }

            // --- List tak bernomor: -, *, + ---
            if (preg_match('/^[-*+]\s+(.*)$/', $line, $m) === 1) {
                $flushParagraph();

                if ($listType !== 'ul') {
                    $flushList();
                    $listType = 'ul';
                }

                $listItems[] = $m[1];

                continue;
            }

            // --- List bernomor: 1. ---
            if (preg_match('/^\d+\.\s+(.*)$/', $line, $m) === 1) {
                $flushParagraph();

                if ($listType !== 'ol') {
                    $flushList();
                    $listType = 'ol';
                }

                $listItems[] = $m[1];

                continue;
            }

            // --- Kutipan ---
            if (preg_match('/^&gt;\s?(.*)$/', $line, $m) === 1) {
                $flushParagraph();
                $flushList();
                $html[] = '<blockquote>'.$this->renderInline($m[1]).'</blockquote>';

                continue;
            }

            // --- Baris biasa: jadi paragraf ---
            $flushList();
            $paragraph[] = $line;
        }

        // Tutup blok yang masih terbuka di akhir dokumen.
        if ($inCodeFence && $codeLines !== []) {
            $html[] = '<pre><code>'.implode("\n", $codeLines).'</code></pre>';
        }

        $flushParagraph();
        $flushList();

        return implode("\n", $html);
    }

    /**
     * Mengubah formatting Markdown di DALAM satu baris menjadi HTML.
     *
     * Masukan SUDAH di-escape, jadi di sini kita hanya menambah tag.
     */
    private function renderInline(string $text): string
    {
        // Kode sebaris harus diproses lebih dulu, lalu isinya disimpan
        // sementara supaya tidak ikut diformat ulang (mis. `**tebal**`
        // di dalam kode harus tetap tampil apa adanya).
        $placeholders = [];

        $text = preg_replace_callback(
            '/`([^`]+)`/',
            function (array $m) use (&$placeholders): string {
                $token = "\x01C".count($placeholders)."\x01";
                $placeholders[$token] = '<code>'.$m[1].'</code>';

                return $token;
            },
            $text,
        ) ?? $text;

        // Gambar: sengaja TIDAK dirender (butuh request eksternal).
        $text = preg_replace(
            '/!\[([^\]]*)\]\([^)]*\)/',
            '$1',
            $text,
        ) ?? $text;

        // Link: [teks](url) -> <a href="url">teks</a>
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)\s]+)\)/',
            function (array $m): string {
                $url = $this->safeUrl($m[2]);

                if ($url === null) {
                    // Skema berbahaya (javascript:, data:) -> tampil teks saja.
                    return $m[1];
                }

                return '<a href="'.$url.'" rel="noopener noreferrer nofollow" target="_blank">'.$m[1].'</a>';
            },
            $text,
        ) ?? $text;

        // Bold, lalu italic, lalu coret.
        $text = preg_replace('/\*\*(?!\s)(.+?)(?<!\s)\*\*/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/__(?!\s)(.+?)(?<!\s)__/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/~~(.+?)~~/', '<del>$1</del>', $text) ?? $text;
        $text = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/(?<![\w_])_(?!\s)(.+?)(?<!\s)_(?![\w_])/', '<em>$1</em>', $text) ?? $text;

        // Kembalikan isi kode yang sempat disimpan terpisah.
        if ($placeholders !== []) {
            $text = strtr($text, $placeholders);
        }

        return $text;
    }

    /**
     * Hanya izinkan URL dengan skema aman.
     *
     * Wajib karena teks seperti [klik](javascript:alert(1)) tetap terlihat
     * sah setelah di-escape, dan akan dieksekusi browser kalau Allowed
     * menjadi atribut href.
     */
    private function safeUrl(string $url): ?string
    {
        // Buang karakter kontrol/whitespace yang bisa menyamar
        // (mis. "java\tscript:alert(1)").
        $url = preg_replace('/[\x00-\x20]/', '', $url) ?? $url;

        if ($url === '') {
            return null;
        }

        // URL relatif (tidak punya skema) aman, mis. "#bagian" atau "docs/a.md".
        if (! preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $m)) {
            return $url;
        }

        return in_array(strtolower($m[1]), self::ALLOWED_SCHEMES, true) ? $url : null;
    }
}
