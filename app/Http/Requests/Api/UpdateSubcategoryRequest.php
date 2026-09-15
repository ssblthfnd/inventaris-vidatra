<?php

namespace App\Http\Requests\Api;

use App\Models\Subcategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT|PATCH /api/subcategories/{subcategory}` (Tahap 6.8.4), `can:admin`.
 *
 * Partial-patch semantics. `id`/`category_code`/`code` are all `prohibited`
 * — a subcategory's category and code are immutable once created (see
 * StoreSubcategoryRequest's docblock). Deactivating a subcategory performs
 * NO cascade — assets referencing it are left completely untouched.
 */
class UpdateSubcategoryRequest extends FormRequest
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
        /** @var Subcategory $subcategory */
        $subcategory = $this->route('subcategory');

        return [
            'id' => ['prohibited'],
            'category_code' => ['prohibited'],
            'code' => ['prohibited'],

            'name' => [
                'sometimes', 'required', 'string', 'max:120',
                Rule::unique('subcategories', 'name')
                    ->where(fn ($query) => $query->where('category_code', $subcategory->category_code))
                    ->ignore($subcategory->id),
            ],
            'guide_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_code.prohibited' => 'Kategori subkategori tidak dapat diubah.',
            'code.prohibited' => 'Kode subkategori tidak dapat diubah.',
            'name.unique' => 'Subkategori dengan nama ini sudah ada di kategori tersebut.',
        ];
    }
}
