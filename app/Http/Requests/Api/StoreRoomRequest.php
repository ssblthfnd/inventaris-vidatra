<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/rooms` (Tahap 6.8.1, `can:admin`; Stage 6.9 R7 regated to
 * `can:rooms.manage`).
 *
 * A new room always starts `is_active = true` — `is_active` is deliberately
 * `prohibited` here (not just omitted) so a client attempting to set it gets a
 * clean 422 instead of the value being silently dropped. Same for `id`.
 * `location_code` is intentionally NOT restricted here beyond "must be an
 * active location" — a `unit_admin` submitting a location outside their own
 * scope still passes THIS validation (the location itself is real and
 * active) and is rejected afterward by `RoomController::store()` via
 * `RoomPolicy` instead — the location is never silently rewritten to the
 * actor's own.
 */
class StoreRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: auth:sanctum + auth.active + can:rooms.manage; location scope checked in RoomController via RoomPolicy
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }

        foreach (['pic', 'notes'] as $key) {
            if ($this->has($key)) {
                $value = trim((string) $this->input($key));
                $this->merge([$key => $value === '' ? null : $value]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'id' => ['prohibited'],
            'is_active' => ['prohibited'],

            'location_code' => ['required', 'string', Rule::exists('locations', 'code')->where('is_active', true)],
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('rooms', 'name')
                    ->where(fn ($query) => $query->where('location_code', $this->input('location_code'))),
            ],
            'pic' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'location_code.required' => 'Lokasi wajib dipilih.',
            'location_code.exists' => 'Lokasi tidak ditemukan atau tidak aktif.',
            'name.unique' => 'Ruangan dengan nama ini sudah ada di lokasi tersebut.',
        ];
    }
}
