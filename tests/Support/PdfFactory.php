<?php

namespace Tests\Support;

use Dompdf\Dompdf;

/**
 * Pembuat PDF sintetis untuk keperluan testing.
 *
 * Kenapa tidak pakai file PDF statis di dalam repo?
 *  1. Tidak ada file biner yang menuhin repository.
 *  2. Isi PDF selalu sama, sehingga assertions stabil.
 *  3. Tidak mengandung data pribadi siapa pun.
 *
 * dompdf hanya dipakai di environment test (require-dev),
 * jadi tidak menambah beban aplikasi produksi.
 */
final class PdfFactory
{
    /**
     * Buat isi file PDF dari potongan HTML.
     */
    public static function fromHtml(string $html): string
    {
        $dompdf = new Dompdf;
        $dompdf->setPaper('A4');
        $dompdf->loadHtml($html);
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * PDF satu halaman berisi satu blok teks.
     */
    public static function singlePage(string $text): string
    {
        return self::fromHtml(
            '<html><body><p>'.htmlspecialchars($text, ENT_QUOTES).'</p></body></html>'
        );
    }

    /**
     * PDF beberapa halaman, masing-masing berisi satu paragraf.
     *
     * @param  array<int, string>  $pageTexts
     */
    public static function multiPage(array $pageTexts): string
    {
        $body = '';

        foreach ($pageTexts as $index => $text) {
            if ($index > 0) {
                // Paksa pindah halaman di dalam HTML.
                $body .= '<div style="page-break-after: always;"></div>';
            }

            $body .= '<p>'.htmlspecialchars($text, ENT_QUOTES).'</p>';
        }

        return self::fromHtml('<html><body>'.$body.'</body></html>');
    }

    /**
     * PDF yang formatnya sah tapi tidak punya teks sama sekali
     * (setara PDF hasil scan tanpa lapisan teks).
     * Hanya ada kotak kecil tanpa tulisan.
     *
     * Catatan hasil uji: smalot/pdfparser tetap mengembalikan " " untuk
     * dokumen seperti ini, jadi Service wajib melakukan trim per baris.
     */
    public static function withoutText(): string
    {
        return self::fromHtml(
            '<html><body><div style="width:12px;height:12px;background:#000;"></div></body></html>'
        );
    }

    /**
     * Berkas yang NAMANYA .pdf tapi isinya teks biasa.
     * Wajib ditolak oleh validasi.
     */
    public static function fakePdf(): string
    {
        return 'Ini jelas bukan PDF, cuma teks biasa.'.PHP_EOL;
    }

    /**
     * PDF yang rusak: diawali header "%PDF-" supaya lolos deteksi MIME,
     * tapi isi setelahnya sampah sehingga tidak bisa di-parse.
     *
     * Berkas seperti ini akan lolos aturan "mimetypes" tapi harus ditolak
     * oleh Service dengan ConversionException::unreadable().
     */
    public static function corrupted(): string
    {
        return "%PDF-1.4\n".str_repeat('X', 512);
    }

    /**
     * PDF yang isinya terenkripsi.
     *
     * dompdf tidak mendukung enkripsi, jadi kita sisipkan dictionary
     * /Encrypt ke dalam trailer PDF asli. Efeknya: file terdeteksi
     * terenkripsi oleh guard di Service (dicek lewat kemunculan "/Encrypt"),
     * persis seperti PDF asli yang dilindungi kata sandi.
     */
    public static function encrypted(): string
    {
        $plain = self::singlePage('Dokumen rahasia');

        $encryptDictionary = '/Encrypt << /Filter /Standard /V 1 /R 2'
            .' /O <'.str_repeat('AB', 32).'>'
            .' /U <'.str_repeat('CD', 32).'>'
            .' /P -1 >> ';

        $replaced = preg_replace(
            // 'ID [<...> <...>]' di trailer diganti menjadi "/Encrypt ... /ID [...]"
            '/\/ID\s*\[[^\]]*\]/',
            $encryptDictionary.'/ID [<'.str_repeat('00', 16).'> <'.str_repeat('00', 16).'>]',
            $plain,
            1,
            $count,
        );

        // Kalau regex tidak cocok (versi dompdf berubah), tambahkan
        // penanda secara paksa agar test tetap menguji jalur enkripsi.
        if ($replaced === null || $count === 0) {
            return $encryptDictionary.$plain;
        }

        return $replaced;
    }
}
