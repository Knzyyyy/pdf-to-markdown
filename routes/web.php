<?php

use App\Http\Controllers\PdfToMarkdownController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| PDF to Markdown
|--------------------------------------------------------------------------
|
| Rute khusus aplikasi konverter PDF. Sengaja dipisah dari route "/" di atas
| supaya halaman bawaan Laravel tetap utuh.
|
*/

Route::get('/pdf-to-markdown', [PdfToMarkdownController::class, 'showForm'])
    ->name('pdf-to-markdown');

/*
| Route konversi dibatasi 10 request per menit per pengguna.
| throttle:10,1 artinya maksimal 10 kali dalam 1 menit.
| Batas ini penting karena proses parsing PDF cukup berat secara CPU.
*/
Route::post('/pdf-to-markdown', [PdfToMarkdownController::class, 'convert'])
    ->middleware('throttle:10,1')
    ->name('pdf-to-markdown.convert');

Route::get('/pdf-to-markdown/download', [PdfToMarkdownController::class, 'download'])
    ->middleware('throttle:30,1')
    ->name('pdf-to-markdown.download');

/* ZIP dihapus setelah dikirim, jadi route ini bisa dipakai ulang
   selama hasil masih ada di cache. */
Route::get('/pdf-to-markdown/zip', [PdfToMarkdownController::class, 'zip'])
    ->middleware('throttle:10,1')
    ->name('pdf-to-markdown.zip');
