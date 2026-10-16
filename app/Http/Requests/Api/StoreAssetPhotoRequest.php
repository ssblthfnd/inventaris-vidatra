<?php

namespace App\Http\Requests\Api;

use App\Services\Image\ImageSanitizer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/assets/{asset}/photos` — one photo per request, multipart field
 * `photo`.
 *
 * Deliberately only `required|file|max`: the type is NOT decided here from the
 * extension or the browser MIME type. {@see ImageSanitizer} inspects the
 * content itself (finfo + getimagesize + a real GD decode), and it is what
 * gives the specific messages (HEIC, not an image, resolution too large, …).
 * `max_upload_kb` is the application limit; PHP's `upload_max_filesize` /
 * `post_max_size` can be lower on the server — a file PHP itself refused
 * arrives as a failed upload (`photo.uploaded`) or, above `post_max_size`, as
 * an empty request (`photo.required`) / HTTP 413.
 */
class StoreAssetPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route: auth:sanctum + auth.active + can:assets.edit + throttle; WHERE in the controller
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'photo' => ['required', 'file', 'max:'.$this->maxKb()],
        ];
    }

    public function messages(): array
    {
        return [
            'photo.required' => 'Pilih foto yang akan diunggah. Jika foto sudah dipilih, ukurannya mungkin melebihi batas server.',
            'photo.uploaded' => 'Foto gagal diunggah. Ukuran file mungkin melebihi batas server — coba foto yang lebih kecil.',
            'photo.file' => 'Foto gagal diunggah. Pilih file foto yang valid.',
            'photo.max' => 'Ukuran file foto maksimal '.round($this->maxKb() / 1024).' MB.',
        ];
    }

    private function maxKb(): int
    {
        return (int) config('inventory.photos.max_upload_kb', 15360);
    }
}
