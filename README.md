# PDF to Markdown Converter

![Tests](https://github.com/Knzyyyy/pdf-to-markdown/actions/workflows/tests.yml/badge.svg)
![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)

Ubah file PDF menjadi Markdown yang rapi — tanpa AI, tanpa API key, tanpa layanan eksternal.

Dibangun dengan Laravel, Blade, dan satu library PHP ([`smalot/pdfparser`](https://github.com/smalot/pdfparser)) untuk ekstraksi teks.

---

## Fitur

- **Unggah PDF** dengan drag & drop, validasi di backend (tipe MIME + penanda `%PDF-` + ukuran maksimal 15 MB)
- **Ekstraksi teks** per halaman, diurutkan berdasarkan posisi baca
- **Perapian Markdown otomatis**:
  - deteksi judul dari pola teks (`BAB II`, `1. Judul`, `1.1 Judul`, teks kapital)
  - deteksi list (`•`, `-`, `*`, `1.`, `a)`)
  - penggabungan baris yang terpotong di tengah kalimat
  - penyambungan kata yang patah di akhir baris (`konver-` + `si` → `konversi`)
  - pembuangan header/footer dan nomor halaman yang berulang
- **Rentang halaman** — konversi hanya sebagian dokumen, mis. `2-5`
- **Pemisah antar halaman** yang bisa dimatikan (`---`)
- **Statistik**: jumlah halaman, kata, karakter, dan perkiraan jumlah token
- **Dua tab hasil**: Preview (sudah dirender) dan Raw Markdown
- **Unduh** sebagai satu file `.md`, atau **ZIP berisi satu file per halaman**
- **Privasi**: file PDF tidak pernah disimpan di server

## Teknologi

| Bagian | Teknologi |
|---|---|
| Framework | Laravel 10 (`laravel/framework ^10.10`) |
| Bahasa | PHP `^8.2` (diuji sampai PHP 8.3) |
| Template | Blade |
| Ekstraksi PDF | `smalot/pdfparser ^2.12` |
| Frontend | Vite 5, CSS, JavaScript vanilla |
| Testing | PHPUnit 10 (`php artisan test`) |
| dependency test | `dompdf/dompdf ^3.1` (dev only) |

Database **tidak digunakan**. Tidak ada model, migration, atau query.

### Mengapa `smalot/pdfparser`?

- Ringan, murni PHP, tanpa service eksternal
- matured dan banyak dipakai
- Nadanya bisa disetel lewat `Config` (mis. membuang data gambar untuk menghemat memori)
- Versi 2.12.5 membutuhkan `php >=7.1`, `ext-zlib`, dan `ext-iconv` — semuanya sudah tersedia di PHP 8.3

## Kebutuhan sistem

| Kebutuhan | Versi | Keterangan |
|---|---|---|
| PHP | **8.2+** | Diuji sampai PHP 8.3 (ZTS). Minimal 8.2 karena `composer.lock` memuat beberapa package yang butuh >= 8.2 |
| Extension PHP | `zlib`, `iconv`, `ctype`, `dom`, `mbstring`, `fileinfo` | Wajib — sudah dipakai library parser |
| Extension PHP | **`zip`** | **Wajib.** Tercatat di `require` composer.json sebagai `ext-zip`. Dipakai fitur unduh ZIP |
| Composer | 2.x | |
| Node.js | 18+ | Untuk build asset frontend via Vite. **Tidak diperlukan** kalau hanya memakai release yang sudah di-build |

Cek extension yang aktif:

```bash
php -m
```

Kalau `zip` belum ada, aktifkan `extension=zip` di `php.ini` server Anda.
Tanpa itu, `composer install` akan berhenti karena `ext-zip` tercatat di
`require`, dan fitur unduh ZIP juga akan menampilkan pesan error.

## Catatan penting tentang versi

**Proyek ini memakai Laravel 10, yang sudah melewati masa dukungannya (EOL).**

- Laravel 10 menerima *bug fix* terakhir pada **Februari 2024** dan tidak
  lagi mendapat patch keamanan.
- `composer audit` pada `composer.json` proyek ini melaporkan **46 advisory
  keamanan pada 14 package**. Sebagian besar berasal dari kerangka Laravel 10
  beserta dependency-nya sendiri, **bukan** dari kode aplikasi ini.
- Risikonya nyata: aplikasi yang diekspos ke internet bisa tereksploitasi lewat
  kerangka atau dependency-nya yang sudah usang.

Mengapa tidak langsung di-upgrade? Proyek ini sengaja memakai Laravel 10 agar
konsisten dengan versi PHP 8.3 dan toolchain yang sudah ada. Upgrade ke
Laravel 11/12 adalah perubahan **major version** dan perlu diuji ulang,
termasuk kemungkinan perbedaan pada driver cache dan session.

Naik ke versi yang masih didukung ada di [Roadmap](#roadmap).

## Catatan untuk deployment

### 1. Batas ukuran upload

Aplikasi menegakkan batas **15 MB** di backend (`ConvertPdfRequest`). Agar
batas itu berlaku, PHP sendiri harus mengizinkan minimal 15 MB:

```ini
upload_max_filesize = 15M
post_max_size = 16M
```

Cara mengecek nilai yang sedang aktif:

```bash
php -r "echo ini_get('upload_max_filesize'), PHP_EOL;"
php -r "echo ini_get('post_max_size'), PHP_EOL;"
```

Kalau `upload_max_filesize` lebih kecil dari 15M, file besar akan ditolak
oleh PHP **sebelum** sampai ke aplikasi, dan pesan errornya kurang jelas.

Perbedaan antara keduanya penting: `post_max_size` berlaku untuk seluruh
body request (termasuk field lain dan multipart overhead), jadi nilainya harus
**lebih besar** dari `upload_max_filesize`.

Nilai ini diatur di `php.ini` milik server, bukan lewat `.env`. Di Laragon:
`Menu > PHP > php.ini`, lalu muat ulang Apache.

### 2. Trusted proxies

Aplikasi tidak memakai URL absolut dalam respons, jadi **tidak wajib** mengatur
trusted proxies. Tapi ini tetap penting bila Anda berada di belakang reverse
proxy (Nginx, Cloudflare, Traefik, atau load balancer):

- **Akibat tidak diatur:** jika `TrustProxies` middleware salah konfigurasi,
  alamat IP asli tidak terbaca. Rate limit dibatasi per proxy, bukan per
  pengguna — artinya batas 10 konversi per menit bisa terpakai habis oleh satu
  proxy saja sebelum pengguna lain sempat mencoba.
- **Cara mengatur:** daftarkan IP proxy di `app/Http/Middleware/TrustProxies.php`
  pada `$proxies`, lalu set `APP_ENV=production`. **Jangan** memakai `*`,
  karena itu membuat siapa pun bisa memalsukan alamat IP.

### 3. Cache dan session

Hasil konversi disimpan di cache dengan TTL 15 menit. Pastikan driver cache
benar-benar bekerja:

```env
CACHE_STORE=file   # atau redis untuk beban lebih tinggi
SESSION_DRIVER=file
```

Kalau `CACHE_STORE` salah, pengguna akan sering mendapat pesan "hasil konversi
sudah kedaluwarsa".

## Cara memasang

```bash
# 1. Install dependency PHP
composer install

# 2. Siapkan file .env
cp .env.example .env        # Windows PowerShell: copy .env.example .env
php artisan key:generate

# 3. Install dependency JavaScript
npm install

# 4. Build asset frontend
npm run build
```

### Catatan penting soal batas upload

Aplikasi membatasi upload **15 MB**. Pastikan PHP tidak membatasi lebih kecil:

```bash
php -r "echo ini_get('upload_max_filesize'), PHP_EOL;"   # harus >= 15M
php -r "echo ini_get('post_max_size'), PHP_EOL;"          # harus >= 16M
```

Kalau nilainya lebih kecil, file besar akan ditolak oleh PHP **sebelum** sampai ke
aplikasi, dan pesan errornya kurang jelas. Perbaikannya lewat `php.ini` milik
server (di Laragon: `Menu > PHP > php.ini`), lalu muat ulang Apache:

```ini
upload_max_filesize = 15M
post_max_size = 16M
```

## Cara menjalankan

### Dengan Laragon

1. Buka Laragon, pilih **Start All**.
2. Buka browser ke `http://pdf-to-markdown.test/pdf-to-markdown`
   (nama host mengikuti `APP_URL` yang kamu pakai di Laragon).
3. Selesai. Tidak perlu `php artisan serve` kalau Laragon sudah berjalan.

### Dengan terminal / VS Code

```bash
php artisan serve
```

Lalu buka `http://127.0.0.1:8000/pdf-to-markdown`.

### Mode pengembangan frontend

Kalau sedang mengubah CSS atau JS, jalankan Vite dalam mode watch supaya
perubahan langsung ter-refresh tanpa perlu build ulang:

```bash
npm run dev
```

## Cara menjalankan test

```bash
php artisan test
```

Test memakai cache dan session driver `array` (dari `phpunit.xml`), jadi
**tidak butuh database**.

Menjalankan sebagian test saja:

```bash
php artisan test --filter=SecurityTest        # satu class
php artisan test --testsuite=Unit            # hanya unit test
php artisan test --filter=rentang_halaman    # hanya test dengan nama tertentu
```

Cek format kode (Laravel Pint):

```bash
vendor/bin/pint --test    #cek saja
vendor/bin/pint           # perbaiki otomatis
```

### Struktur test

| File | Yang diuji |
|---|---|
| `tests/Unit/MarkdownFormatterTest.php` | setiap aturan pembentukan Markdown, termasuk kasus yang **harus tidak** berubah |
| `tests/Unit/MarkdownRendererTest.php` | render preview + 20 vektor serangan XSS |
| `tests/Unit/PdfToMarkdownServiceTest.php` | ekstraksi PDF dan penanganan file bermasalah |
| `tests/Feature/PdfToMarkdownTest.php` | alur upload → konversi → unduh |
| `tests/Feature/ConversionOptionsTest.php` | rentang halaman, pemisah halaman, statistik |
| `tests/Feature/SecurityTest.php` | rate limit, penyimpanan hasil, file berbahaya |
| `tests/Feature/UserInterfaceTest.php` | tab, sanitasi preview, teks privasi |
| `tests/Feature/ZipDownloadTest.php` | unduh ZIP per halaman |

Fixture PDF dibuat on-the-fly oleh `tests/Support/PdfFactory.php`, tidak ada
file biner di dalam repository dan tidak ada data pribadi di dalamnya.

## Struktur folder

```
app/
├── Exceptions/
│   └── ConversionException.php        # error yang aman ditampilkan ke pengguna
├── Http/
│   ├── Controllers/
│   │   └── PdfToMarkdownController.php
│   └── Requests/
│       └── ConvertPdfRequest.php      # validasi upload
├── Services/
│   ├── PdfToMarkdownService.php       # otak konversi PDF -> Markdown
│   └── Markdown/
│       └── MarkdownFormatter.php      # aturan penyusunan Markdown (pure functions)
└── Support/
    ├── ConversionOptions.php          # rentang halaman + toggle pemisah
    ├── ConversionResult.php           # hasil konversi + statistik
    ├── ConversionStore.php            # simpan hasil sementara di cache (TTL 15 menit)
    ├── MarkdownRenderer.php           # render preview dengan sanitasi
    └── MarkdownZipper.php             # buat ZIP satu file per halaman

resources/
├── css/app.css                        # gaya (mengikuti design.md)
├── js/app.js                          # drag & drop, tab, salin
└── views/pdf-to-markdown.blade.php

routes/web.php                         # 4 route aplikasi
tests/                                 # lihat tabel di atas
```

## Alur kerja

```
Browser ──POST /pdf-to-markdown (multipart + CSRF)──▶ ConvertPdfRequest
                                                          │
                        validasi: 15 MB · MIME PDF · header %PDF- · urutan
                                                          ▼
                                       PdfToMarkdownController::convert()
                                                          │
                          $file->getRealPath()  ← nama file dari user TIDAK
                                                          │    pernah jadi path
                                                          ▼
                                          PdfToMarkdownService::convert()
                                                          │
                          smalot/pdfparser → teks per halaman
                          deteksi terenkripsi (cek "/Encrypt")
                          deteksi dokumen tanpa teks (butuh OCR)
                                                          ▼
                                          MarkdownFormatter::formatPages()
                                                          │
                          judul · list · gabung baris terpotong
                          buang header/footer berulang · normalisasi
                                                          ▼
                                    ConversionResult (markdown + statistik)
                                                          ▼
                                    ConversionStore::put() → cache, TTL 15 mnt
                                    session hanya menyimpan kunci acak
                                                          ▼
                                    redirect → Blade render preview + tombol
                                                          ▼
                        GET /pdf-to-markdown/download  → stream .md ke browser
                        GET /pdf-to-markdown/zip      → ZIP per halaman (dihapus setelah dikirim)
```

## Daftar route

| Method | URL | Keterangan |
|---|---|---|
| GET | `/` | Halaman welcome bawaan Laravel (tidak diubah) |
| GET | `/pdf-to-markdown` | Halaman converter |
| POST | `/pdf-to-markdown` | Proses upload. Rate limit 10/menit |
| GET | `/pdf-to-markdown/download` | Unduh `.md`. Rate limit 30/menit |
| GET | `/pdf-to-markdown/zip` | Unduh ZIP per halaman. Rate limit 10/menit |

Semua route memakai group middleware `web`, termasuk CSRF.

## Keamanan

Yang dilakukan aplikasi ini:

- Validasi di **backend**: ukuran maksimal 15 MB, tipe MIME, dan penanda `%PDF-` di awal file
- Nama file dari pengguna **tidak pernah** dipakai sebagai path di disk; hanya `getRealPath()` milik PHP yang dipakai
- File PDF **tidak disimpan** permanen; PHP otomatis menghapus file upload setelah request selesai
- Hasil konversi disimpan di cache dengan **TTL 15 menit** dan kunci acak 32 byte; yang masuk session hanya kuncinya
- Error internal tidak pernah ditampilkan ke pengguna; pesan error dibuat eksplisit per kondisi
- Isi dokumen tidak pernah ditulis ke log
- Rate limit 10 konversi per menit untuk menahan penyalahgunaan
- CSRF tetap aktif

**Yang TIDAK dilindungi:**

Pemeriksaan MIME dan penanda `%PDF-` **tidak menjamin file benar-benar aman**.
PDF bisa saja berisi JavaScript atau exploit. Aplikasi ini aman relatif karena
filenya tidak pernah dieksekusi dan tidak pernah disimpan, hanya dibaca sebagai
teks — tapi ini bukan sandbox. Jangan memproses PDF dari sumber yang tidak
terpercaya di server yang berisi data sensitif.

## Keterbatasan

Bagian ini sengaja ditulis jujur. **Aplikasi ini tidak cocok untuk dokumen
dengan struktur kompleks.**

1. **Hanya mengekstrak teks.** Tabel, gambar, diagram, dan tata letak kolom
   **tidak dipertahankan**. Tabel sering berubah jadi teks berantakan seperti
   `NamaNilai` `Andi90`.
2. **Tidak ada OCR.** PDF hasil scan (gambar) tidak punya lapisan teks, sehingga
   gagal dikonversi. Aplikasi memberi pesan bahwa OCR dibutuhkan, tapi OCR
   sendiri belum diimplementasikan.
3. **Heading adalah hasil tebakan.** Ukuran font tidak tersedia dari parser,
   jadi judul dikenali dari pola teks (`BAB II`, `1. Judul`, `1.1 Judul`, teks
   kapital). Hasilnya **sering salah**: teks kapital di tengah dokumen bisa
   dianggap judul, dan judul yang tidak kapital bisa terlewat.
4. **Tampilan heading bisa kacau.** Level `#`, `##`, `###` ditentukan dari pola
   penomoran, bukan dari struktur dokumen sebenarnya.
5. **Penghapusan header/footer bisa salah.** Baris yang muncul di ≥ 60% halaman
dianggap berulang dan dihapus. Pada dokumen dengan frasa yang sering
    diulang, isi penting bisa ikut terhapus. Untuk dokumen < 3 halaman, aturan
    ini **dimatikan** demi keamanan.
6. **Penggabungan baris terpotong bisa salah.** Baris yang tidak diakhiri tanda
   baca dan diikuti baris berawalan huruf kecil dianggap sambungan kalimat.
   Kalimat pendek yang berdiri sendiri bisa ikut digabung.
7. **Tab Preview terbatas.** Hanya mendukung judul, list, paragraf, kode,
   blockquote, bold, italic, strikethrough, dan link. Tabel dan gambar
   **tidak dirender**.
8. **Preview dipotong** di 200.000 karakter demi menjaga kecepatan halaman.
   Tab Raw dan file unduhan tidak dipotong.
9. **Perkiraan token bukan angka pasti.** Aturannya `jumlah karakter / 4`,
   yang hanya mendekati nilai sebenarnya untuk bahasa Inggris.
10. **Konten dua kolom bisa tertukar urutan.** Parser membaca berdasarkan
    koordinat teks, dan untuk layout yang rumit hasilnya tidak selalu urutan
    baca yang benar.
11. **Font khusus / subset tanpa tabel Unicode** bisa menghasilkan teks yang
    salah eja atau hilang.
12. **Halaman tanpa teks** (mis. gambar penuh) menghasilkan string kosong.
    Halaman seperti itu tetap dihitung sebagai halaman.

## Roadmap

Rencana pengembangan berikutnya, berurut dari yang paling banyak dibutuhkan:

1. **Upgrade ke Laravel yang masih didukung** - menutup advisory keamanan bawaan kerangka.
   Perlu diuji ulang karena ada perbedaan driver cache dan session antar versi major.
2. **OCR untuk PDF scan** — memakai `tesseract` atau layanan lokal, sehingga
   dokumen hasil scan bisa dikonversi
3. **Konversi tabel** — mendeteksi baris tabel dan menghasilkan Markdown table
   yang valid
4. **Batch upload** — mengunggah beberapa PDF sekaligus, diproses antrean
5. **Deteksi bahasa dokumen** — untuk memperbaiki penggabungan baris dan
   penanganan kutipan
6. **Editor hasil** — mengoreksi hasil sebelum diunduh
7. **Ekspor ke format lain** — HTML, DOCX, EPUB

## Lisensi

MIT — lihat [LICENSE](LICENSE).

Library pihak ketiga tetap mengikuti lisensinya masing-masing:
`smalot/pdfparser` (LGPL-3.0), `dompdf/dompdf` (MIT), Laravel (MIT).