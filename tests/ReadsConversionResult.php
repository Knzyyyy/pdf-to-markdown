<?php

namespace Tests;

use App\Support\ConversionStore;

/**
 * Helper yang dipakai test fitur untuk membaca hasil konversi.
 *
 * Hasil konversi TIDAK lagi disimpan di session (karena bisa jauh melebihi
 * batas 4 KB cookie). Yang ada di session hanya kunci acaknya, sedangkan
 * isinya ada di cache. Test ini membaca lewat ConversionStore, sama seperti
 * yang dilakukan controller.
 */
trait ReadsConversionResult
{
    /**
     * @return array<string, mixed>|null
     */
    protected function resultPayload(): ?array
    {
        $key = session(ConversionStore::SESSION_KEY);

        if (! is_string($key) || $key === '') {
            return null;
        }

        return $this->app->make(ConversionStore::class)->get($key);
    }

    /**
     * @return array<string, mixed>
     */
    protected function resultPayloadOrFail(): array
    {
        $payload = $this->resultPayload();

        $this->assertIsArray($payload, 'Hasil konversi tidak ditemukan di cache');

        return $payload;
    }
}
