<?php

namespace App\Http\Requests\Api;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT|PATCH /api/categories/{category}` (Tahap 6.8.4), `can:admin`.
 *
 * Partial-patch semantics, same `sometimes` idiom as
 * {@see UpdateLocationRequest}. `code` is
 * `prohibited`. Deactivating a category performs NO cascade — subcategories
 * and assets referencing it are left completely untouched (see
 * `CategoryController`'s docblock).
 */
class UpdateCategoryRequest extends FormRequest
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
        /** @var Category $category */
        $category = $this->route('category');

        return [
            'code' => ['prohibited'],

            'name' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('categories', 'name')->ignore($category->code, 'code')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.prohibited' => 'Kode kategori tidak dapat diubah.',
            'name.unique' => 'Nama kategori ini sudah digunakan.',
        ];
    }
}
