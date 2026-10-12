<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>PDF ke Markdown — Konverter</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div class="shell">
    <header class="masthead">
        <p class="masthead__eyebrow">Tanpa AI, tanpa API key</p>
        <h1 class="masthead__title">PDF ke Markdown</h1>
        <p class="masthead__lead">
            Unggah PDF, dapatkan Markdown yang sudah dirapikan: judul, list, paragraf,
            dan pemisah antar halaman. Siap dipakai untuk GitHub.
        </p>
    </header>

    {{-- Pesan error validasi / kegagalan konversi --}}
    @if ($errors->any())
        <div class="alert alert--error" role="alert">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid">
        {{-- ============ KOLOM KIRI: FORM UPLOAD ============ --}}
        <section class="card">
            <h2 class="card__title">Unggah dokumen</h2>

            {{--
                enctype="multipart/form-data" WAJIB agar PHP menerima file.
                @csrf menyisipkan token anti-CSRF. Jangan dihapus.
            --}}
            <form
                id="upload-form"
                action="{{ route('pdf-to-markdown.convert') }}"
                method="POST"
                enctype="multipart/form-data"
            >
                @csrf

                <div id="dropzone" class="dropzone" data-max-size="{{ \App\Http\Requests\ConvertPdfRequest::MAX_SIZE_KB * 1024 }}">
                    <input
                        type="file"
                        name="file"
                        id="file-input"
                        accept="application/pdf,.pdf"
                        class="dropzone__input"
                    >
                    <p class="dropzone__hint">
                        Tarik file PDF ke sini, atau
                        <label for="file-input" class="dropzone__link">pilih file</label>
                    </p>
                    <p class="dropzone__limit">Maksimal 15 MB</p>
                    <p class="dropzone__filename" id="file-name" aria-live="polite"></p>
                </div>

                {{-- ============ OPSIONAL ============ --}}
                <label class="field">
                    <span class="field__label">Rentang halaman</span>
                    <input
                        type="text"
                        name="page_range"
                        id="page_range"
                        class="field__input"
                        placeholder="contoh: 2-5"
                        value="{{ old('page_range') }}"
                        inputmode="numeric"
                    >
                    <span class="field__hint">
                        Kosongkan untuk semua halaman. Isi <code>5</code> untuk satu halaman,
                        atau <code>2-5</code> untuk rentang.
                    </span>
                </label>

                <label class="checkbox">
                    {{--
                        Checkbox tidak mengirim nilai kalau tidak dicentang,
                        jadi value="1" terkirim hanya saat dicentang.
                        "checked" diambil dari old() supaya pilihan pengguna
                        bertahan setelah terjadi error.
                    --}}
                    <input
                        type="checkbox"
                        name="page_separator"
                        value="1"
                        @checked(old('page_separator', '1') === '1')
                    >
                    <span>Pisahkan antar halaman dengan <code>---</code></span>
                </label>

                <button type="submit" class="btn btn--primary" id="submit-btn">
                    Konversi ke Markdown
                </button>

                {{-- Loading: shimmer bar, bukan spinner (sesuai design.md) --}}
                <div class="loader" id="loader" hidden>
                    <div class="loader__bar"><span class="loader__fill"></span></div>
                    <p>Memproses PDF Anda, mohon tunggu…</p>
                </div>
            </form>
        </section>

        {{-- ============ KOLOM KANAN: HASIL KONVERSI ============ --}}
        <section class="card">
            @if ($result)
                <h2 class="card__title">Hasil konversi</h2>

                <div class="result__actions">
                    <button type="button" class="btn btn--blue" id="copy-btn">Salin Markdown</button>
                    <a class="btn btn--white" href="{{ route('pdf-to-markdown.download') }}">Unduh .md</a>
                    <a class="btn btn--white" href="{{ route('pdf-to-markdown.zip') }}">Unduh ZIP per halaman</a>
                </div>

                @if ($result['page_range'])
                    <p class="alert alert--info">
                        Hanya {{ $result['page_range'] }} yang dikonversi
                        (dari total {{ $result['total_pages'] }} halaman).
                    </p>
                @endif

                <dl class="stats">
                    <div class="stats__item">
                        <dt>Halaman</dt>
                        <dd>{{ $result['page_count'] }}</dd>
                    </div>
                    <div class="stats__item">
                        <dt>Kata</dt>
                        <dd>{{ number_format($result['words'], 0, ',', '.') }}</dd>
                    </div>
                    <div class="stats__item">
                        <dt>Karakter</dt>
                        <dd>{{ number_format($result['characters'], 0, ',', '.') }}</dd>
                    </div>
                    <div class="stats__item">
                        <dt>Token (perkiraan)</dt>
                        <dd>~{{ number_format($result['estimated_tokens'], 0, ',', '.') }}</dd>
                    </div>
                </dl>

                {{--
                    ============ TAB ============
                    Preview = HTML yang SUDAH disanitasi oleh MarkdownRenderer
                    (teks di-escape dulu, lalu hanya tag yang kita tulis sendiri
                    yang ditambahkan). Isi PDF tidak pernah dirender sebagai HTML mentah.

                    Raw = teks Markdown asli di dalam <pre>. <pre> hanya
                    menampilkan teks, dan {{ }} otomatis meng-escape karakter HTML.
                --}}
                <div class="tabs" role="tablist" aria-label="Tampilan hasil konversi">
                    <button
                        type="button"
                        class="tab"
                        id="tab-preview"
                        role="tab"
                        aria-selected="true"
                        aria-controls="panel-preview"
                    >
                        Preview
                    </button>
                    <button
                        type="button"
                        class="tab"
                        id="tab-raw"
                        role="tab"
                        aria-selected="false"
                        aria-controls="panel-raw"
                    >
                        Raw Markdown
                    </button>
                </div>

                <div
                    class="tab__panel preview"
                    id="panel-preview"
                    role="tabpanel"
                    aria-labelledby="tab-preview"
                >
                    {!! \App\Support\MarkdownRenderer::render($result['markdown']) !!}
                </div>

                <div
                    class="tab__panel raw"
                    id="panel-raw"
                    role="tabpanel"
                    aria-labelledby="tab-raw"
                    hidden
                >{{ $result['markdown'] }}</div>
            @else
                <h2 class="card__title">Hasil</h2>
                <div class="empty">
                    <p class="empty__title">Belum ada hasil</p>
                    <p>Pilih file PDF di sebelah kiri, lalu tekan tombol konversi.</p>
                </div>
            @endif
        </section>
    </div>

    {{-- Catatan jujur tentang kemampuan aplikasi --}}
    <aside class="notes">
        <h2 class="notes__title">Batasan aplikasi ini</h2>
        <ul>
            <li>
                Hanya mengekstrak <strong>teks</strong>. Tabel, gambar, dan tata letak
                kolom <strong>tidak</strong> dipertahankan.
            </li>
            <li>
                Tidak mendukung OCR, jadi PDF hasil scan (gambar) tidak bisa dikonversi.
            </li>
            <li>
                Judul dokumen dikenali dari pola teks (mis. "BAB I" atau "1. Judul"),
                bukan dari ukuran font. Karena itu struktur heading
                <strong>hasil tebakan</strong> dan bisa saja salah.
            </li>
            <li>
                Tab Preview hanya menampilkan subset Markdown sederhana
                (judul, list, paragraf, kode, link). Tabel dan gambar tidak dirender.
            </li>
        </ul>
        <p class="notes__privacy">
            <strong>Privasi:</strong> file PDF Anda tidak disimpan di server.
            PDF hanya dibaca selama proses konversi berlangsung, lalu dibuang oleh PHP.
            Hasil konversi disimpan sementara selama 15 menit supaya bisa diunduh,
            lalu dihapus otomatis.
        </p>
    </aside>
</div>
</body>
</html>