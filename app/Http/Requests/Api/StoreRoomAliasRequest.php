<?php

namespace App\Http\Requests\Api;

use App\Import\Parsing\ValueNormalizer;
use App\Models\RoomAlias;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/room-aliases` (Tahap 6.8.2), `can:operator` — see
 * `RoomAliasController`'s docblock for why this is operator-writable, unlike
 * every other master-data write in this app.
 *
 * `match_key` is never client-settable — it is ALWAYS derived server-side
 * from `raw_value` via the exact same {@see ValueNormalizer::roomMatchKey()}
 * that `RoomMatcher` and `RoomAliasSeeder` already use, so an alias created
 * here is guaranteed to actually resolve the same way at import time. The
 * duplicate check below reproduces that derivation rather than trusting a
 * client-supplied value, for the same reason.
 */
class StoreRoomAliasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: auth:sanctum + auth.active + can:operator
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('raw_value')) {
            $this->merge(['raw_value' => trim((string) $this->input('raw_value'))]);
        }
        if ($this->has('notes')) {
            $notes = trim((string) $this->input('notes'));
            $this->merge(['notes' => $notes === '' ? null : $notes]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'id' => ['prohibited'],
            'match_key' => ['prohibited'],
            'created_by' => ['prohibited'],

            'location_code' => ['required', 'string', Rule::exists('locations', 'code')->where('is_active', true)],

            'raw_value' => [
                'required', 'string', 'max:150',
                function ($attribute, $value, $fail) {
                    $locationCode = $this->input('location_code');
                    if (! is_string($locationCode) || $locationCode === '') {
                        return; // location_code's own rule already reports the real problem
                    }
                    $key = ValueNormalizer::roomMatchKey($value);
                    $exists = RoomAlias::query()
                        ->where('location_code', $locationCode)
                        ->where('match_key', $key)
                        ->exists();
                    if ($exists) {
                        $fail('Alias dengan nilai ini sudah ada di lokasi tersebut.');
                    }
                },
            ],

            // Intentionally NOT is_active-scoped — an alias may point to an
            // inactive room (historical cleanup / admin convenience); see
            // RoomAliasController's docblock. Only "must exist in this
            // location" is enforced here.
            'room_id' => [
                'required', 'integer',
                Rule::exists('rooms', 'id')->where('location_code', $this->input('location_code')),
            ],

            'source' => ['sometimes', Rule::in(['tahap1_seed', 'manual'])],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'location_code.required' => 'Lokasi wajib dipilih.',
            'location_code.exists' => 'Lokasi tidak ditemukan atau tidak aktif.',
            'room_id.exists' => 'Ruangan tidak ditemukan di lokasi tersebut.',
        ];
    }
}
