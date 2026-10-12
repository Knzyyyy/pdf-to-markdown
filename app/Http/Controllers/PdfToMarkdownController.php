<?php

namespace App\Http\Controllers;

use App\Exceptions\ConversionException;
use App\Http\Requests\ConvertPdfRequest;
use App\Services\PdfToMarkdownService;
use App\Support\ConversionStore;
use App\Support\MarkdownZipper;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PdfToMarkdownController extends Controller
{
    /**
     * Dependency injection: Laravel otomatis memasukkan class-class ini.
     * Controller tidak perlu membuat sendiri dengan `new`.
     */
    public function __construct(
        private readonly PdfToMarkdownService $converter,
        private readonly ConversionStore $store,
        private readonly MarkdownZipper $zipper,
    ) {}

    /**
     * GET /pdf-to-markdown — menampilkan halaman converter.
     */
    public function showForm(): View
    {
        return view('pdf-to-markdown', [
            // Hasil disimpan di cache, bukan session. Yang perlu dikirim ke
            // view hanya isi untuk ditampilkan (bukan untuk diunduh).
            'result' => $this->resultForView(),
        ]);
    }

    /**
     * POST /pdf-to-markdown — menerima upload lalu konversi.
     */
    public function convert(ConvertPdfRequest $request): RedirectResponse
    {
        $options = $request->conversionOptions();

        try {
            $result = $this->converter->convert($request->temporaryPath(), $options);
        } catch (ConversionException $e) {
            // back() = kembali ke URL sebelumnya (halaman form).
            // withInput() mengisi ulang kolom teks (rentang halaman), TAPI
            // file dikecualikan karena isinya tidak bisa diserialisasi ke session.
            return back()
                ->withInput($request->except('file'))
                ->withErrors(['file' => $e->getMessage()]);
        }

        // Simpan hasil ke cache dengan TTL 15 menit. Yang disimpan di session
        // hanya kunci acaknya saja (kecil, aman untuk cookie).
        $payload = [
            'filename' => $result->filenameFor($request->originalFilename()),
            'markdown' => $result->markdown,
            'pages' => $result->pages,
            'page_count' => $result->pageCount(),
            'words' => $result->wordCount(),
            'characters' => $result->characterCount(),
            'estimated_tokens' => $result->estimatedTokens(),
            'page_range' => $options->describeRange(),
            'total_pages' => $result->totalPagesInDocument,
        ];

        $request->session()->put(ConversionStore::SESSION_KEY, $this->store->put($payload));

        return redirect()->route('pdf-to-markdown');
    }

    /**
     * GET /pdf-to-markdown/download — mengirim hasil sebagai file .md.
     *
     * Memakai response()->streamDownload() supaya isi Markdown mengalir
     * langsung ke browser tanpa menulis file ke disk sama sekali.
     */
    public function download(): RedirectResponse|StreamedResponse
    {
        $payload = $this->store->get($this->currentKey() ?? '');

        if ($payload === null) {
            return $this->expiredResultResponse();
        }

        return response()->streamDownload(
            fn () => print ($payload['markdown']),
            $payload['filename'],
            [
                'Content-Type' => 'text/markdown; charset=UTF-8',
            ],
        );
    }

    /**
     * GET /pdf-to-markdown/zip — mengunduh hasil sebagai ZIP, satu file .md
     * per halaman.
     *
     * Berbeda dengan download .md biasa, file di sini DIBUAT DI DISK
     * (temporary) karena ZipArchive tidak bisa menulis langsung ke output
     * HTTP secara andal. File itu langsung dihapus setelah dikirim.
     */
    public function zip(): RedirectResponse|StreamedResponse
    {
        $payload = $this->store->get($this->currentKey() ?? '');

        if ($payload === null) {
            return $this->expiredResultResponse();
        }

        $baseName = pathinfo((string) $payload['filename'], PATHINFO_FILENAME);

        try {
            $temporaryPath = $this->zipper->createTemporaryArchive(
                $payload['pages'],
                $payload['markdown'],
                $baseName,
            );
        } catch (Throwable) {
            // Pesan aman untuk pengguna, tanpa detail internal.
            return back()->withErrors([
                'file' => 'File ZIP tidak bisa dibuat. Pastikan ekstensi PHP "zip" aktif di server.',
            ]);
        }

        return response()->streamDownload(function () use ($temporaryPath): void {
            try {
                readfile($temporaryPath);
            } finally {
                // File berisi dokumen pengguna: WAJIB dihapus.
                $this->zipper->delete($temporaryPath);
            }
        }, $this->zipper->zipFilename($baseName), [
            'Content-Type' => 'application/zip',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // =========================================================
    // Helper privat
    // =========================================================

    /**
     * Kunci hasil konversi milik sesi browser ini saja.
     */
    private function currentKey(): ?string
    {
        $key = session(ConversionStore::SESSION_KEY);

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Ambil hasil untuk ditampilkan di halaman.
     *
     * Kalau sudah kedaluwarsa, session dibersihkan supaya tidak ada kunci
     * yatim. TIDAK menulis log isi dokumen (bisa berisi data pribadi).
     */
    private function resultForView(): ?array
    {
        $key = $this->currentKey();

        if ($key === null) {
            return null;
        }

        $payload = $this->store->get($key);

        if ($payload === null) {
            session()->forget(ConversionStore::SESSION_KEY);

            return null;
        }

        return $payload;
    }

    /**
     * Pesan yang sama untuk semua kondisi "hasil tidak ada": user belum pernah
     * mengonversi, atau hasil sudah kedaluwarsa setelah 15 menit.
     */
    private function expiredResultResponse(): RedirectResponse
    {
        return back()
            ->withErrors([
                'file' => 'Hasil konversi tidak ditemukan atau sudah kedaluwarsa (maksimal 15 menit). Silakan konversi ulang.',
            ]);
    }
}
