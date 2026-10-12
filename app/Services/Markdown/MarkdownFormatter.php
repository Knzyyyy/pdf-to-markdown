<?php

namespace App\Services\Markdown;

/**
 * Mengubah teks mentah hasil ekstraksi PDF menjadi teks Markdown yang lebih rapi.
 *
 * PRINSIP: semua method di kelas ini adalah fungsi TE MURNI (tidak menyentuh
 * I/O, tidak butuh Laravel). Itu disengaja supaya setiap aturan bisa diuji
 * sendiri dengan input/output yang jelas di tests/Unit/MarkdownFormatterTest.php.
 *
 * Semua aturan di sini bersifat HEURISTIK (penilaian berdasarkan pola teks),
 * bukan analisis struktur dokumen sungguhan. smalot/pdfparser hanya memberi
 * kita teks, tanpa info heading/tabel/gambar. Karena itu hasilnya SEKALI-SEKALI
 * bisa salah, dan itu harus disebut terbuka di README.
 */
class MarkdownFormatter
{
    /** Panjang maksimum (karakter) agar sebuah baris dianggap judul. */
    private const HEADING_MAX_LENGTH = 100;

    /** Jumlah baris awal/akhir halaman yang diperiksa sebagai header/footer. */
    private const HEADER_FOOTER_ZONE = 3;

    /**
     * AmbangKemiripan: baris dianggap header/footer berulang kalau muncul di
     * minimal 60% halaman.
     */
    private const REPEAT_THRESHOLD = 0.6;

    /**
     * Pipeline utama: menerima teks mentah semua halaman, mengembalikan
     * Markdown per halaman yang sudah dirapikan.
     *
     * @param  array<int, string>  $pages
     * @return array<int, string>
     */
    public function formatPages(array $pages): array
    {
        $formatted = [];

        foreach ($pages as $page) {
            $formatted[] = $this->formatPage($page);
        }

        // Penghapusan header/footer harus dilakukan setelah semua halaman
        // diformat, karena kita butuh data dari SELURUH dokumen.
        return $this->removeRepeatedHeadersAndFooters($formatted);
    }

    /**
     * Memproses satu halaman: normalisasi -> gabung baris terpotong ->
     * tandai heading & list.
     */
    public function formatPage(string $text): string
    {
        $text = $this->normalizeWhitespace($text);

        if (trim($text) === '') {
            return '';
        }

        $blocks = $this->splitIntoBlocks($text);
        $result = [];

        foreach ($blocks as $block) {
            foreach ($this->processBlock($block) as $line) {
                $result[] = $line;
            }

            // Baris kosong memisahkan blok paragraf.
            $result[] = '';
        }

        return $this->normalizeWhitespace(implode("\n", $result));
    }

    // =========================================================
    // ATURAN 1 & 2: heading dan list
    // =========================================================

    /**
     * Deteksi heading.
     *
     * Mengembalikan Markdown heading ("# Judul") atau null kalau baris ini
     * bukan judul.
     *
     * Aturan: baris pendek, tidak diakhiri tanda baca, dan berpola:
     *   - "BAB II", "BAB 3"          -> "# "
     *   - "1. Judul"                  -> "## "
     *   - "1.1 Judul", "1.1.1 Judul"  -> "### "
     *   - "HURUF KAPITAL SEMUA"        -> "## "
     *
     * Syarat penolak (anti false positive):
     *   - panjang > 100 karakter  -> bukan judul
     *   - diakhiri . , ; : ! ?     -> bukan judul
     *   - hanya 1-2 huruf kapital  -> bukan judul
     */
    public function detectHeading(string $line): ?string
    {
        $line = trim($line);

        if ($line === '' || mb_strlen($line) > self::HEADING_MAX_LENGTH) {
            return null;
        }

        // Judul tidak diakhiri tanda baca. Pengecualian: penomoran itu sendiri
        // boleh diakhiri titik, mis. "1." atau "1.1.".
        if (preg_match('/[.,;:!?]$/u', $line) === 1) {
            return null;
        }

        // "BAB I" / "BAB II" / "BAB 12" -> level 1
        if (preg_match('/^BAB\s+([IVXLC]+|\d+)\b/iu', $line) === 1) {
            return '# '.$line;
        }

        // "1.1.1 Judul" -> level 3 (paling dalam)
        if (preg_match('/^\d+(?:\.\d+){2,}\s+\S/u', $line) === 1) {
            return '### '.$line;
        }

        // "1.1 Judul" -> level 3
        if (preg_match('/^\d+\.\d+\.?\s+\S/u', $line) === 1) {
            return '### '.$line;
        }

        // "1. Judul" -> level 2
        if (preg_match('/^\d+\.\s+\S/u', $line) === 1) {
            return '## '.$line;
        }

        // "HURUF KAPITAL SEMUA"
        if ($this->isShouted($line)) {
            return '## '.$line;
        }

        return null;
    }

    /**
     * Deteksi baris list. Mengembalikan Markdown list ("- Butir") atau null.
     *
     * Aturan:
     *   - "• Butir", "- Butir", "* Butir", "· Butir" -> "- Butir"
     *   - "1. Butir", "1) Butir"                   -> "1. Butir" (dipertahankan)
     *   - "a) Butir"                                -> "- Butir" (Markdown tidak
     *                                                    mendukung list huruf)
     */
    public function detectListItem(string $line): ?string
    {
        $trimmed = trim($line);

        if ($trimmed === '') {
            return null;
        }

        // Bullet symbol dan - / *
        if (preg_match('/^[•·▪◦‣●]\s*(.+)$/u', $trimmed, $m) === 1) {
            return '- '.trim($m[1]);
        }

        if (preg_match('/^[-*+]\s+(.+)$/u', $trimmed, $m) === 1) {
            return '- '.trim($m[1]);
        }

        // List bernomor: "1." atau "1)"
        if (preg_match('/^(\d+)[.)]\s+(.+)$/u', $trimmed, $m) === 1) {
            return $m[1].'. '.trim($m[2]);
        }

        // List huruf: "a)" atau "a."
        if (preg_match('/^([a-z])[.)]\s+(.+)$/u', $trimmed, $m) === 1) {
            return '- '.trim($m[2]);
        }

        return null;
    }

    // =========================================================
    // ATURAN 3: gabung baris terpotong
    // =========================================================

    /**
     * Menggabungkan baris yang terpotong di tengah kalimat menjadi satu paragraf.
     *
     * Dua kondisi yang digabung:
     *  1. Baris berakhir dengan "-" lalu baris berikutnya huruf kecil
     *     -> kata disambung TANPA tanda hubung: "konver-" + "si" = "konversi"
     *  2. Baris tidak diakhiri tanda baca DAN baris berikutnya diawali huruf kecil
     *     -> digabung dengan satu spasi
     *
     * Kalau baris berikutnya diawali huruf besar atau angka, baris TIDAK digabung
     * karena itu kemungkinan kalimat baru atau judul.
     *
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    public function joinWrappedLines(array $lines): array
    {
        $result = [];

        foreach ($lines as $line) {
            $previous = $result === [] ? null : $result[count($result) - 1];

            if ($previous !== null && $this->shouldJoin($previous, $line)) {
                $result[count($result) - 1] = $this->joinTwoLines($previous, $line);

                continue;
            }

            $result[] = $line;
        }

        return $result;
    }

    // =========================================================
    // ATURAN 4: buang header/footer berulang
    // =========================================================

    /**
     * Menghapus baris yang berulang di header/footer >= 60% halaman.
     *
     * Yang dibuang:
     *  - Teks identik yang muncul di zona atas/bawah tiap halaman
     *    (mis. judul dokumen yang dicetak di setiap halaman)
     *  - Nomor halaman ("1", "- 12 -", "Halaman 3 dari 10")
     *
     * SYARAT PENTING: hanya berjalan jika dokumen punya minimal 3 halaman.
     * Pada dokumen 1-2 halaman, baris berulang bisa jadi isi yang benar-benar
     * penting, jadi kita lebih memilih tidak menghapus daripada salah hapus.
     *
     * @param  array<int, string>  $pages
     * @return array<int, string>
     */
    public function removeRepeatedHeadersAndFooters(array $pages): array
    {
        if (count($pages) < 3) {
            return $pages;
        }

        $zoneSize = self::HEADER_FOOTER_ZONE;
        $occurrences = [];

        foreach ($pages as $page) {
            $pageLines = explode("\n", $page);
            $candidates = [];

            foreach ($this->zoneLines($pageLines, 0, $zoneSize) as $line) {
                $candidates[] = $line;
            }

            foreach ($this->zoneLines($pageLines, count($pageLines) - $zoneSize, $zoneSize) as $line) {
                $candidates[] = $line;
            }

            foreach (array_unique($candidates) as $candidate) {
                $key = $this->headerKey($candidate);

                $occurrences[$key] = ($occurrences[$key] ?? 0) + 1;
            }
        }

        $threshold = (int) ceil(count($pages) * self::REPEAT_THRESHOLD);

        $repeats = [];

        foreach ($occurrences as $key => $count) {
            if ($count >= $threshold) {
                $repeats[$key] = true;
            }
        }

        if ($repeats === []) {
            return $pages;
        }

        return array_map(function (string $page) use ($repeats): string {
            $kept = [];

            foreach (explode("\n", $page) as $line) {
                if ($this->isRepeatedLine($line, $repeats)) {
                    continue;
                }

                $kept[] = $line;
            }

            return $this->normalizeWhitespace(implode("\n", $kept));
        }, $pages);
    }

    // =========================================================
    // ATURAN 5: normalisasi spasi dan baris kosong
    // =========================================================

    /**
     * Menyamakan akhir baris, collapsing spasi berlebih, dan membatasi
     * jumlah baris kosong. Teks yang ada TIDAK dihapus.
     */
    public function normalizeWhitespace(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $text);

        // Baris yang hanya berisi spasi -> baris kosong.
        $text = preg_replace('/^[ ]+$/m', '', $text) ?? $text;

        // 3+ spasi berurutan -> 1 spasi (sudah di luar heading/list).
        $text = preg_replace('/[ ]{3,}/', ' ', $text) ?? $text;

        // 3+ newline -> maksimal 1 baris kosong.
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    // =========================================================
    // Method privat
    // =========================================================

    /**
     * Memisahkan teks menjadi blok paragraf berdasarkan baris kosong.
     *
     * @return array<int, array<int, string>>
     */
    private function splitIntoBlocks(string $text): array
    {
        $blocks = [];
        $current = [];

        foreach (explode("\n", $text) as $line) {
            if (trim($line) === '') {
                if ($current !== []) {
                    $blocks[] = $current;
                    $current = [];
                }

                continue;
            }

            $current[] = rtrim($line);
        }

        if ($current !== []) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * Memproses satu blok paragraf: gabung baris terpotong, lalu tandai
     * heading dan list pada hasilnya.
     *
     * @param  array<int, string>  $block
     * @return array<int, string>
     */
    private function processBlock(array $block): array
    {
        $joined = $this->joinWrappedLines($block);
        $output = [];

        // Detektor heading perlu tahu apakah baris ini berada di dalam
        // blok list, supaya "1. Beli buku" tidak dianggap judul.
        $listFlags = array_map(
            fn (string $line): bool => $this->detectListItem($line) !== null,
            $joined,
        );
        $listBlock = count(array_filter($listFlags)) === count($listFlags);

        foreach ($joined as $index => $line) {
            // Jangan ubah baris yang sudah jadi list.
            if ($listFlags[$index]) {
                $output[] = $this->detectListItem($line);

                continue;
            }

            $heading = $this->detectHeading($line);

            if ($heading !== null && ! $listBlock) {
                $output[] = $heading;

                continue;
            }

            // Kalau seluruh blok ini terbaca sebagai list tapi heading
            // terdeteksi, lebih aman perlakukan sebagai list.
            $output[] = $line;
        }

        return $output;
    }

    /**
     * Apakah baris $previous dan $next sebaiknya digabung?
     */
    private function shouldJoin(string $previous, string $next): bool
    {
        $previous = trim($previous);
        $nextTrim = ltrim($next);

        if ($previous === '' || $nextTrim === '') {
            return false;
        }

        // Baris yang sudah berstruktur (judul/list) tidak boleh digabung.
        if ($this->isStructuralLine($previous)) {
            return false;
        }

        // Lafal soft hyphen / tanda hubung discretionary: sambung tanpa spasi.
        if (str_ends_with($previous, '-')) {
            return preg_match('/^\p{Ll}/u', $nextTrim) === 1;
        }

        // Kalimat yang belum selesai (tidak diakhiri tanda baca)
        // diikuti baris yang diawali huruf kecil = sambung kalimat.
        if (preg_match('/[.!?;:,]$/u', $previous) === 1) {
            return false;
        }

        // Judul kapital tidak digabung ke baris berikutnya.
        if ($this->isShouted($previous)) {
            return false;
        }

        return preg_match('/^\p{Ll}/u', $nextTrim) === 1;
    }

    private function joinTwoLines(string $previous, string $next): string
    {
        $next = ltrim($next);

        if (str_ends_with($previous, '-')) {
            // "konver-" + "si" -> "konversi"
            return rtrim(substr($previous, 0, -1)).$next;
        }

        return $previous.' '.$next;
    }

    /**
     * Baris yang sudah menjadi struktur Markdown (judul atau list).
     */
    private function isStructuralLine(string $line): bool
    {
        return $this->detectHeading($line) !== null
            || $this->detectListItem($line) !== null;
    }

    /**
     * Teks yang dianggap "berteriak" sehingga layak jadi judul.
     *
     * SYARAT BERGANDENG, dan ini penting untuk mengurangi false positive:
     *  - minimal 4 huruf kapital, supaya "PT" dan "PDF" tidak jadi judul
     *  - DAN (minimal 2 kata ATAU minimal 6 huruf), supaya akronim 4 huruf
     *    seperti "JSON" atau "HTML" yang berdiri sendiri tidak dianggap judul
     *
     * Contoh:
     *   "DAFTAR ISI"    -> 2 kata, 9 huruf  -> judul  ✓
     *   "ABSTRAK"       -> 1 kata, 7 huruf  -> judul  ✓
     *   "PDF"           -> 3 huruf          -> bukan  ✗
     *   "JSON"          -> 1 kata, 4 huruf  -> bukan  ✗
     */
    private function isShouted(string $line): bool
    {
        $line = trim($line);

        // Buang angka, tanda baca, dan spasi sebelum menguji kapital.
        $letters = preg_replace('/[^A-Za-z]/u', '', $line) ?? '';
        $letterCount = mb_strlen($letters);

        if ($letterCount < 4) {
            return false;
        }

        if (mb_strtoupper($letters) !== $letters) {
            return false;
        }

        $wordCount = count(preg_split('/\s+/u', $line) ?: []);

        return $wordCount >= 2 || $letterCount >= 6;
    }

    /**
     * Mengambil baris dari sebuah offset dengan batas aman.
     *
     * @return array<int, string>
     */
    private function zoneLines(array $lines, int $offset, int $size): array
    {
        $slice = array_slice($lines, max(0, $offset), $size);

        // Jangan ambil baris kosong sebagai kandidat.
        return array_values(array_filter(array_map('trim', $slice), fn ($l) => $l !== ''));
    }

    /**
     * Kunci pembanding untuk deteksi baris berulang.
     *
     * Nomor halaman dinormalisasi jadi satu token agar "1", "- 1 -", dan
     * "Halaman 1" dianggap satu hal yang sama.
     */
    private function headerKey(string $line): string
    {
        $line = $this->normalizeWhitespace(trim($line));

        if ($this->looksLikePageNumber($line)) {
            return '__PAGE_NUMBER__';
        }

        return mb_strtolower($line);
    }

    private function isRepeatedLine(string $line, array $repeats): bool
    {
        $line = trim($line);

        if ($line === '') {
            return false;
        }

        return isset($repeats[$this->headerKey($line)]);
    }

    /**
     * Pola nomor halaman: "1", "12", "- 12 -", "— 12 —", "Halaman 12",
     * "Page 12", "12 / 30".
     */
    private function looksLikePageNumber(string $line): bool
    {
        $patterns = [
            '/^\d{1,4}$/',
            '/^[-–—|]\s*\d{1,4}\s*[-–—|]$/',
            '/^(halaman|page|hal\.?)\s*\d{1,4}(\s*(dari|of|\/)\s*\d{1,4})?$/i',
            '/^\d{1,4}\s*(\/|\bdari\b|\bof\b)\s*\d{1,4}$/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, trim($line)) === 1) {
                return true;
            }
        }

        return false;
    }
}
