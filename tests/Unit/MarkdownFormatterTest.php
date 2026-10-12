<?php

namespace Tests\Unit;

use App\Services\Markdown\MarkdownFormatter;
use Tests\TestCase;

/**
 * Unit test untuk SETIAP aturan pembentukan Markdown.
 *
 * Setiap test menunjukkan INPUT dan OUTPUT yang jelas.
 * Termasuk kasus yang SEHARUSNYA TIDAK diubah (anti false-positive),
 * karena aturan ini berbasis pola teks dan bisa salah tebak.
 */
class MarkdownFormatterTest extends TestCase
{
    private MarkdownFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formatter = new MarkdownFormatter;
    }

    // =========================================================
    // ATURAN 1: DETEKSI HEADING
    // =========================================================

    /**
     * @dataProvider headingProvider
     */
    public function test_deteksi_heading(string $input, ?string $expected): void
    {
        $this->assertSame($expected, $this->formatter->detectHeading($input));
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function headingProvider(): array
    {
        return [
            // --- Harus terdeteksi sebagai heading ---
            'BAB dengan angka romawi' => ['BAB II PENANGANAN DATA', '# BAB II PENANGANAN DATA'],
            'BAB dengan angka arab' => ['BAB 3 Metode Penelitian', '# BAB 3 Metode Penelitian'],
            'penomoran level 1' => ['1. Pendahuluan', '## 1. Pendahuluan'],
            'penomoran level 2' => ['1.1 Latar Belakang', '### 1.1 Latar Belakang'],
            'penomoran level 2 dengan titik' => ['1.1. Latar Belakang', '### 1.1. Latar Belakang'],
            'penomoran level 3' => ['1.1.1.1 Sub Judul', '### 1.1.1.1 Sub Judul'],
            'huruf kapital semua' => ['DAFTAR ISI', '## DAFTAR ISI'],
            'huruf kapital dengan angka' => ['BAGIAN 2 LAPORAN', '## BAGIAN 2 LAPORAN'],
            'singleton kata kapital panjang' => ['ABSTRAK', '## ABSTRAK'],

            // --- Harus TIDAK terdeteksi (anti false-positive) ---
            'kalimat yang diakhiri titik' => ['Penelitian ini dilakukan di Bandung.', null],
            'kalimat pendek normal' => ['ini cuma kalimat biasa', null],
            'singleton kata kapital pendek' => ['PDF', null],
            'singleton kata kapital 4 huruf' => ['JSON', null],
            'akronim di tengah kalimat' => ['Gunakan API untuk itu', null],
            'baris terlalu panjang' => [str_repeat('Kata Panjang ', 12).'Judul Panjang', null],
            'daftar angka biasa' => ['2024', null],
            'hanya tanda baca' => ['---', null],
            'baris kosong' => ['', null],
            'campur kapital kecil' => ['Judul Yang Campur Huruf', null],
        ];
    }

    // =========================================================
    // ATURAN 2: DETEKSI LIST
    // =========================================================

    /**
     * @dataProvider listProvider
     */
    public function test_deteksi_list(string $input, ?string $expected): void
    {
        $this->assertSame($expected, $this->formatter->detectListItem($input));
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function listProvider(): array
    {
        return [
            'bullet bulat' => ['• Beli buku', '- Beli buku'],
            'bullet tengah' => ['· Beli buku', '- Beli buku'],
            'bullet kotak kecil' => ['▪ Beli buku', '- Beli buku'],
            'bullet lingkaran kecil' => ['◦ Beli buku', '- Beli buku'],
            'tanda hubung' => ['- Beli buku', '- Beli buku'],
            'bintang' => ['* Beli buku', '- Beli buku'],
            'list bernomor titik' => ['1. Beli buku', '1. Beli buku'],
            'list bernomor kurung' => ['1) Beli buku', '1. Beli buku'],
            'list huruf' => ['a) Beli buku', '- Beli buku'],
            'spasi ekstra dibersihkan' => ['•    Beli buku', '- Beli buku'],

            // --- Harus TIDAK terdeteksi ---
            'paragraf biasa' => ['Beli buku mahal', null],
            'tanda hubung di tengah' => ['harga buku 10.000', null],
            'baris kosong' => ['', null],
            'angka desimal' => ['3.14 adalah pi', null],
        ];
    }

    // =========================================================
    // ATURAN 3: GABUNG BARIS TERPOTONG
    // =========================================================

    public function test_menggabungkan_baris_terpotong_di_tengah_kalimat(): void
    {
        // INPUT: satu kalimat yang patah jadi 3 baris
        $lines = [
            'Konversi dokumen ke format',
            'markdown berguna untuk',
            'menyimpan dokumentasi.',
        ];

        // OUTPUT: satu baris utuh
        $this->assertSame(
            ['Konversi dokumen ke format markdown berguna untuk menyimpan dokumentasi.'],
            $this->formatter->joinWrappedLines($lines),
        );
    }

    public function test_menyambung_kata_yang_terputus_tanda_hubung(): void
    {
        // INPUT: kata "konversi" terpotong jadi "konver-" + "si"
        $lines = ['Teknologi konver-', 'si file inibesar besar.'];

        // OUTPUT: kata disambung tanpa tanda hubung
        $this->assertSame(
            ['Teknologi konversi file inibesar besar.'],
            $this->formatter->joinWrappedLines($lines),
        );
    }

    public function test_tidak_menggabungkan_baris_kalimat_yang_sudah_selesai(): void
    {
        // INPUT: dua kalimat terpisah (kalimat 1 diakhiri titik)
        $lines = [
            'Ini kalimat pertama.',
            'Dan ini kalimat kedua.',
        ];

        // OUTPUT: tetap dua baris, TIDAK digabung
        $this->assertSame($lines, $this->formatter->joinWrappedLines($lines));
    }

    public function test_tidak_menggabungkan_baris_yang_ikutan_huruf_besar(): void
    {
        // INPUT: baris pertama tidak diakhiri tanda baca, tapi baris kedua
        // diawali huruf besar -> kemungkinan kalimat/judul baru
        $lines = [
            'Bab pertama membahas pengantar',
            'Penelitian ini dilakukan di Bandung',
        ];

        $this->assertSame($lines, $this->formatter->joinWrappedLines($lines));
    }

    public function test_tidak_menggabungkan_baris_judul_dengan_paragraf(): void
    {
        $lines = ['PENDAHULUAN', 'Paragraf pembuka dokumen.'];

        $this->assertSame($lines, $this->formatter->joinWrappedLines($lines));
    }

    // =========================================================
    // ATURAN 4: BUANG HEADER/FOOTER BERULANG
    // =========================================================

    public function test_membuang_header_dan_nomor_halaman_yang_berulang(): void
    {
        $pages = [
            "Judul Dokumen Uji\n\nIsi halaman satu.",
            "Judul Dokumen Uji\n\nIsi halaman dua.",
            "Judul Dokumen Uji\n\nIsi halaman tiga.",
            "Judul Dokumen Uji\n\nIsi halaman empat.",
        ];

        $result = $this->formatter->removeRepeatedHeadersAndFooters($pages);

        // Header "Judul Dokumen Uji" hilang dari semua halaman.
        foreach ($result as $index => $page) {
            $this->assertStringNotContainsString('Judul Dokumen Uji', $page, 'Halaman '.($index + 1));
        }

        // Isi halaman tetap utuh.
        $this->assertSame('Isi halaman satu.', $result[0]);
        $this->assertSame('Isi halaman empat.', $result[3]);
    }

    public function test_membuang_nomor_halaman_di_footer(): void
    {
        $pages = [
            "Isi halaman satu.\n\n1",
            "Isi halaman dua.\n\n2",
            "Isi halaman tiga.\n\n3",
        ];

        $result = $this->formatter->removeRepeatedHeadersAndFooters($pages);

        $this->assertSame('Isi halaman satu.', $result[0]);
        $this->assertSame('Isi halaman tiga.', $result[2]);
    }

    public function test_membuang_nomor_halaman_berformat_lain(): void
    {
        $pages = [
            "Isi satu.\n\n- 1 -",
            "Isi dua.\n\n- 2 -",
            "Isi tiga.\n\n- 3 -",
        ];

        $result = $this->formatter->removeRepeatedHeadersAndFooters($pages);

        $this->assertSame('Isi satu.', $result[0]);
    }

    public function test_tidak_membuang_baris_yang_hanya_berulang_di_satu_halaman(): void
    {
        // Baris "Diskon 50%" muncul 3x tapi semuanya di halaman 4 saja.
        $pages = [
            'Isi halaman satu.',
            'Isi halaman dua.',
            'Isi halaman tiga.',
            "Diskon 50%\n\nDiskon 50%\n\nDiskon 50%\n\nIsi halaman empat.",
        ];

        $result = $this->formatter->removeRepeatedHeadersAndFooters($pages);

        // Mampu 4 halaman, ambang 60% = 2,4 -> 3 halaman harus punya baris itu.
        // Baris ini hanya ada di 1 halaman, jadi TIDAK boleh dihapus.
        $this->assertStringContainsString('Diskon 50%', $result[3]);
    }

    public function test_tidak_membuang_apa_pada_dokumen_kurang_dari_3_halaman(): void
    {
        // Dua halaman dengan baris identik: isi ini bisa memang penting,
        // jadi untuk aman kita tidak menghapus apa pun.
        $pages = ['Judul Sama\n\nIsi satu.', 'Judul Sama\n\nIsi dua.'];

        $this->assertSame($pages, $this->formatter->removeRepeatedHeadersAndFooters($pages));
    }

    public function test_membuang_baris_yang_berulang_di_zona_akhir_halaman(): void
    {
        $pages = [
            "Isi satu.\n\nHalaman 1 dari 3",
            "Isi dua.\n\nHalaman 2 dari 3",
            "Isi tiga.\n\nHalaman 3 dari 3",
        ];

        $result = $this->formatter->removeRepeatedHeadersAndFooters($pages);

        $this->assertSame('Isi satu.', $result[0]);
        $this->assertSame('Isi tiga.', $result[2]);
    }

    // =========================================================
    // ATURAN 5: NORMALISASI SPASI DAN BARIS KOSONG
    // =========================================================

    public function test_normalisasi_menggabungkan_baris_kosong_berlebih(): void
    {
        $input = "Baris pertama.\n\n\n\n\nBaris kedua.";

        $this->assertSame(
            "Baris pertama.\n\nBaris kedua.",
            $this->formatter->normalizeWhitespace($input),
        );
    }

    public function test_normalisasi_menggabungkan_spasi_berlebih(): void
    {
        // Perhatikan tanda kutip: memakai " (double quote) supaya \t
        // benar-benar menjadi TAB. Kalau memakai ' (single quote),
        // \t hanya akan menjadi dua karakter biasa "\" dan "t".
        $this->assertSame(
            'Satu dua tiga empat',
            $this->formatter->normalizeWhitespace("Satu   dua\t\t\ttiga    empat"),
        );
    }

    public function test_normalisasi_mengubah_baris_hanya_spasi_menjadi_kosong(): void
    {
        $this->assertSame(
            "Atas\n\nBawah",
            $this->formatter->normalizeWhitespace("Atas\n      \nBawah"),
        );
    }

    public function test_normalisasi_menyamakan_akhir_baris_crlf(): void
    {
        $this->assertSame(
            "Satu\nDua",
            $this->formatter->normalizeWhitespace("Satu\r\nDua"),
        );
    }

    // =========================================================
    // INTEGRASI: PIPELINE LENGKAP
    // =========================================================

    public function test_pipeline_lengkap_menghasilkan_markdown_yang_rapi(): void
    {
        $pages = [
            // Halaman 1: judul, paragraf terpotong, dan list
            "BAB I PENDAHULUAN\n\n".
            "Dokumen ini menjelaskan\n".
            "proses konversi file.\n\n".
            "• Pertama\n".
            '• Kedua',

            // Halaman 2: judul + paragraf terpotong + list bernomor
            "BAB II METODE\n\n".
            "Metode yang dipakai adalah\n".
            "analisis deskriptif.\n\n".
            "1. Pengumpulan data\n".
            '2. Analisis data',
        ];

        $result = $this->formatter->formatPages($pages);

        $this->assertSame(
            "# BAB I PENDAHULUAN\n\n"
            ."Dokumen ini menjelaskan proses konversi file.\n\n"
            ."- Pertama\n"
            .'- Kedua',
            $result[0],
        );

        $this->assertSame(
            "# BAB II METODE\n\n"
            ."Metode yang dipakai adalah analisis deskriptif.\n\n"
            ."1. Pengumpulan data\n"
            .'2. Analisis data',
            $result[1],
        );
    }

    public function test_nomor_list_tidak_berubah_menjadi_heading(): void
    {
        // List bernomor "1. ..." dan "2. ..." berurutan. Kalau salah
        // diperlakukan sebagai heading, daftar ini akan rusak jadi judul.
        $pages = [
            "1. Beli buku\n2. Jual buku\n3. Hitung untung\n\nParagraf pengantar.",
        ];

        $result = $this->formatter->formatPage($pages[0]);

        $this->assertSame(
            "1. Beli buku\n2. Jual buku\n3. Hitung untung\n\nParagraf pengantar.",
            $result,
        );
    }

    public function test_halaman_kosong_menghasilkan_string_kosong(): void
    {
        $this->assertSame('', $this->formatter->formatPage(''));
        $this->assertSame('', $this->formatter->formatPage('    '));
    }

    public function test_statistik_halaman_tidak_berubah_oleh_formatter(): void
    {
        $pages = ['Isi satu.', '', 'Isi tiga.'];

        $result = $this->formatter->formatPages($pages);

        // Jumlah halaman harus tetap sama, termasuk halaman kosong.
        $this->assertCount(3, $result);
        $this->assertSame('', $result[1]);
    }
}
