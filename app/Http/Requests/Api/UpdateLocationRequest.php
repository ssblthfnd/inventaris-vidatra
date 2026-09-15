<?php

namespace App\Http\Requests\Api;

use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT|PATCH /api/locations/{location}` (Tahap 6.8.3), `can:admin`.
 *
 * Partial-patch semantics, same `sometimes` idiom as
 * {@see UpdateRoomRequest}. `code` is `prohibited` —
 * see StoreLocationRequest's docblock for why it must never change once a
 * location exists.
 *
 * Deactivating a location (`is_active=false`) performs no cascade at all:
 * no dependency check, no touching rooms/aliases/assets. See
 * `LocationController`'s docblock for the full reasoning — this mirrors
 * Room's own Tahap 6.8.1 precedent exactly.
 */
class UpdateLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: auth:sanctum + auth.active + can:admin
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
        if ($this->has('alias')) {
            $alias = trim((string) $this->input('alias'));
            $this->merge(['alias' => $alias === '' ? null : $alias]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Location $location */
        $location = $this->route('location');

        return [
            'code' => ['prohibited'],

            'name' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('locations', 'name')->ignore($location->code, 'code')],
            'alias' => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.prohibited' => 'Kode lokasi tidak dapat diubah.',
            'name.unique' => 'Nama lokasi ini sudah digunakan.',
        ];
    }
}
