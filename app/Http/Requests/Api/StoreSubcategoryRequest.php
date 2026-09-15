<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/subcategories` (Tahap 6.8.4), `can:admin`.
 *
 * `category_code` must reference an ACTIVE category — mirrors
 * {@see StoreRoomRequest}'s "location must be active" rule exactly (a new
 * subcategory must never be created under a category an admin has already
 * retired). `code` is exactly 3 characters and unique WITHIN the category
 * only — `(category_code, code)` is not globally unique by design
 * (schema_design.md §3.2, the same subcategory code legitimately repeats
 * across different categories). `id`/`is_active` are `prohibited`.
 */
class StoreSubcategoryRequest extends FormRequest
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
        if ($this->has('guide_name')) {
            $guideName = trim((string) $this->input('guide_name'));
            $this->merge(['guide_name' => $guideName === '' ? null : $guideName]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $categoryCode = $this->input('category_code');

        return [
            'id' => ['prohibited'],
            'is_active' => ['prohibited'],

            'category_code' => ['required', 'string', Rule::exists('categories', 'code')->where('is_active', true)],

            'code' => [
                'required', 'string', 'size:3',
                Rule::unique('subcategories', 'code')->where(fn ($query) => $query->where('category_code', $categoryCode)),
            ],
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('subcategories', 'name')->where(fn ($query) => $query->where('category_code', $categoryCode)),
            ],
            'guide_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_code.required' => 'Kategori wajib dipilih.',
            'category_code.exists' => 'Kategori tidak ditemukan atau tidak aktif.',
            'code.size' => 'Kode subkategori harus tepat 3 karakter.',
            'code.unique' => 'Subkategori dengan kode ini sudah ada di kategori tersebut.',
            'name.unique' => 'Subkategori dengan nama ini sudah ada di kategori tersebut.',
        ];
    }
}
