<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/assets/{asset}/write-off` (Tahap 5.4 §24).
 *
 * The client cannot send `is_written_off` — the server sets it. Only the effective
 * date and an optional note are accepted.
 */
class WriteOffAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: auth:sanctum + auth.active + can:operator
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_written_off' => ['prohibited'],
            'written_off_on' => ['required', 'date', 'before_or_equal:today'],
            'written_off_note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'is_written_off.prohibited' => 'Status ditentukan oleh endpoint ini; kirim hanya written_off_on dan written_off_note.',
        ];
    }
}
