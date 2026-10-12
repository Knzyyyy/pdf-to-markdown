<?php

namespace App\Http\Requests;

use App\Support\ConversionOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Validasi upload PDF.
 *
 * FormRequest = kelas khusus validasi di Laravel. Kalau rules() gagal,
 * Laravel otomatis membalas dengan pesan error TANPA masuk ke Controller.
 */
class ConvertPdfRequest extends FormRequest
{
    /** Batas aplikasi: 15 MB. */
    public const MAX_SIZE_KB = 15360;

    public function authorize(): bool
    {
        // Belum ada sistem login di MVP, jadi semua orang boleh convert.
        // Kalau nanti ada auth, ubah ke pemeriksaan user di sini.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                // max dihitung dalam KILOBYTE: 15360 KB = 15 MB
                'max:'.self::MAX_SIZE_KB,
                // Deteksi tipe dari ISI file (fileinfo/finfo), bukan dari ekstensi.
                'mimetypes:application/pdf,application/x-pdf',
            ],

            // Rentang halaman opsional: "5" atau "2-5".
            'page_range' => ['nullable', 'string', 'regex:/^\d+(\s*-\s*\d+)?$/'],

            // Checkbox HTML tidak mengirim nilai kalau tidak dicentang.
            'page_separator' => ['nullable'],
        ];
    }

    /**
     * Pesan error dalam bahasa Indonesia.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Silakan pilih satu file PDF untuk dikonversi.',
            'file.max' => 'Ukuran file maksimal 15 MB. File Anda lebih besar dari itu.',
            'file.mimetypes' => 'File yang dipilih bukan PDF yang valid.',
            'page_range.regex' => 'Rentang halaman harus berupa format "5" (satu halaman) atau "2-5" (rentang).',
            'page_separator.in' => 'Pilihan pemisah halaman tidak valid.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'file' => 'file PDF',
            'page_range' => 'rentang halaman',
        ];
    }

    /**
     * Pemeriksaan tambahan yang tidak bisa dilakukan oleh rule bawaan.
     *
     * Aturan "mimetypes" saja bisa dilewati sebagian file, jadi kita juga
     * cek langsung bahwa file benar-benar diawali penanda "%PDF-".
     *
     * CATATAN KEAMANAN: pengecekan ini BUKAN jaminan file 100% aman.
     * File PDF bisa saja berisi JavaScript atau exploits. Yang membuat aplikasi
     * ini relatif aman adalah: file tidak pernah dieksekusi, tidak disimpan
     * permanen, dan hanya dibaca sebagai teks oleh library parser.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $file = $this->file('file');

            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                return;
            }

            $head = @file_get_contents($file->getRealPath(), false, null, 0, 5);

            if ($head === false || ! str_starts_with($head, '%PDF-')) {
                $validator->errors()->add(
                    'file',
                    'File yang dipilih bukan PDF yang valid (penanda %PDF- tidak ditemukan).'
                );
            }

            $this->validatePageRangeOrder($validator);
        });
    }

    /**
     * "5-2" lolos aturan regex, tapi secara logika tidak masuk akal.
     * Diperiksa di sini karena tidak bisa dilakukan oleh aturan bawaan.
     */
    private function validatePageRangeOrder($validator): void
    {
        $range = $this->input('page_range');

        if (! is_string($range) || preg_match('/^\d+(\s*-\s*\d+)?$/', $range) !== 1) {
            return;
        }

        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $range, $matches) !== 1) {
            return;
        }

        if ((int) $matches[1] > (int) $matches[2]) {
            $validator->errors()->add(
                'page_range',
                'Nomor halaman awal tidak boleh lebih besar dari halaman akhir.'
            );
        }
    }

    /**
     * Opsi konversi yang sudah dibersihkan, siap dipakai Service.
     */
    public function conversionOptions(): ConversionOptions
    {
        return ConversionOptions::make(
            $this->input('page_range'),
            // Checkbox yang tidak dicentang tidak terkirim sama sekali,
            // jadi harus dianggap sebagai "false".
            $this->boolean('page_separator'),
        );
    }

    /**
     * Nama file asli pengguna, dibersihkan untuk ditampilkan di UI saja.
     * Nilai ini TIDAK PERNAH dipakai sebagai path di disk.
     */
    public function originalFilename(): string
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile
            ? basename($file->getClientOriginalName())
            : 'dokumen.pdf';
    }

    /**
     * Path absolut file sementara. Hanya ini yang boleh dipakai service.
     */
    public function temporaryPath(): string
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return $file->getRealPath();
    }
}
