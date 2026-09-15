<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/locations` (Tahap 6.8.3), `can:admin`.
 *
 * `code` is exactly 2 characters, unique, and — unlike `name`/`alias` —
 * never editable again after creation (see `UpdateLocationRequest`, which
 * `prohibited`-rejects it): schema_design.md §2.1 notes the leading zero is
 * significant and the code participates in every historical `asset_code`
 * copied onto the `assets` table, so renaming it would create dangerous
 * historical ambiguity. `is_active` is deliberately `prohibited` here (not
 * just omitted) — a new location always starts active, full stop.
 */
class StoreLocationRequest extends FormRequest
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
        return [
            'is_active' => ['prohibited'],

            'code' => ['required', 'string', 'size:2', Rule::unique('locations', 'code')],
            'name' => ['required', 'string', 'max:50', Rule::unique('locations', 'name')],
            'alias' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.size' => 'Kode lokasi harus tepat 2 karakter.',
            'code.unique' => 'Kode lokasi ini sudah digunakan.',
            'name.unique' => 'Nama lokasi ini sudah digunakan.',
        ];
    }
}
