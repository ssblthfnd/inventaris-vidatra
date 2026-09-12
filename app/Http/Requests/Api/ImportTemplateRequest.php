<?php

namespace App\Http\Requests\Api;

use App\Import\Parsing\CategoryColumnMap;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query validation for `GET /api/imports/template` (Tahap 6.1).
 *
 * `category` must be one of the three categories the existing importer actually
 * understands per-column ({@see CategoryColumnMap::isKnownCategory()}) — the SAME
 * whitelist the real importer uses, not a separately maintained list. There is
 * deliberately no default: a template's physical column layout differs per
 * category, so guessing one would be worse than asking.
 */
class ImportTemplateRequest extends FormRequest
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
            'category' => ['required', 'string', Rule::in(CategoryColumnMap::KNOWN_CATEGORIES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.required' => 'Pilih kategori terlebih dahulu.',
            'category.in' => 'Kategori tidak dikenali untuk template import.',
        ];
    }

    public function categoryCode(): string
    {
        return (string) $this->validated('category');
    }
}
