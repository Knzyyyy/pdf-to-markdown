<?php

namespace App\Support;

/**
 * Opsi konversi yang dipilih pengguna lewat form.
 *
 * Immutable, dibuat dari input mentah form. Semua "pembersihan" input
 * (mis. " 2 - 5 " -> dari=2, sampai=5) terjadi di sini, supaya Controller
 * dan Service cukup/jobclean memakai objek ini.
 */
final class ConversionOptions
{
    private function __construct(
        public readonly ?int $fromPage,
        public readonly ?int $toPage,
        public readonly bool $separatorEnabled,
    ) {}

    /**
     * Opsi bawaan: semua halaman, pemisah halaman aktif.
     */
    public static function default(): self
    {
        return new self(null, null, true);
    }

    /**
     * @param  string|null  $pageRange  Format "2-5", "2", atau null (semua halaman).
     * @param  bool  $separatorEnabled  Apakah antar halaman dipisah "---".
     */
    public static function make(?string $pageRange, bool $separatorEnabled): self
    {
        $pageRange = $pageRange === null ? null : trim($pageRange);

        if ($pageRange === null || $pageRange === '') {
            return new self(null, null, $separatorEnabled);
        }

        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $pageRange, $m) === 1) {
            return new self((int) $m[1], (int) $m[2], $separatorEnabled);
        }

        // Hanya satu nomor halaman, mis. "3"
        if (preg_match('/^(\d+)$/', $pageRange, $m) === 1) {
            $page = (int) $m[1];

            return new self($page, $page, $separatorEnabled);
        }

        // Format tidak dikenal. Form Request sudah menolak format seperti ini,
        // jadi kondisi ini hanya jaga-jaga.
        return new self(null, null, $separatorEnabled);
    }

    public function selectsWholeDocument(): bool
    {
        return $this->fromPage === null && $this->toPage === null;
    }

    /**
     * Potong daftar halaman sesuai rentang yang dipilih (nomor halaman mulai 1).
     *
     * @param  array<int, string>  $pages
     * @return array<int, string>
     */
    public function slicePages(array $pages): array
    {
        if ($this->selectsWholeDocument()) {
            return $pages;
        }

        $from = max(1, $this->fromPage ?? 1);
        $to = $this->toPage ?? count($pages);

        // array_values supaya indeks/key selalu dimulai dari 0.
        return array_values(array_slice($pages, $from - 1, $to - $from + 1));
    }

    /**
     * Pemisah antar halaman sesuai pilihan pengguna.
     */
    public function separator(string $default): string
    {
        return $this->separatorEnabled ? $default : "\n\n";
    }

    /**
     * Rentang dalam bahasa manusia untuk ditampilkan di UI.
     */
    public function describeRange(): ?string
    {
        if ($this->selectsWholeDocument()) {
            return null;
        }

        if ($this->fromPage === $this->toPage) {
            return 'halaman '.$this->fromPage;
        }

        return 'halaman '.$this->fromPage.'–'.$this->toPage;
    }
}
