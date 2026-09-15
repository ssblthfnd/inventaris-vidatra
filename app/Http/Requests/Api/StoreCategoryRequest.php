<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/categories` (Tahap 6.8.4), `can:admin`.
 *
 * Same immutable-code / always-starts-active shape as
 * {@see StoreLocationRequest} — `code` participates in every historical
 * `asset_code` copied onto the `assets` table (via the composite
 * `(category_code, subcategory_code)` identity), so it is never editable
 * again after creation. `is_active` is `prohibited`, not just omitted.
 */
class StoreCategoryRequest extends FormRequest
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
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['prohibited'],

            'code' => ['required', 'string', 'size:2', Rule::unique('categories', 'code')],
            'name' => ['required', 'string', 'max:50', Rule::unique('categories', 'name')],
        ];
    }

    public function messages(): array
    {
        return [
            'code.size' => 'Kode kategori harus tepat 2 karakter.',
            'code.unique' => 'Kode kategori ini sudah digunakan.',
            'name.unique' => 'Nama kategori ini sudah digunakan.',
        ];
    }
}
