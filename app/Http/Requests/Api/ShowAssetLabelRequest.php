<?php

namespace App\Http\Requests\Api;

use App\Services\Label\AssetLabelPdfService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query validation for `GET /api/assets/{asset}/label` (Tahap 6.0, `size` param
 * added Tahap 6.0.2). The only allowed values are the predefined print sizes the
 * backend knows how to render ({@see AssetLabelPdfService::SIZES}) — a client can
 * never request an arbitrary width/height. Missing `size` defaults to `'small'`,
 * preserving the pre-6.0.2 behaviour for any existing caller of this endpoint.
 */
class ShowAssetLabelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: auth:sanctum + auth.active + can:operator
    }

    /**
     * Treat a blank `?size=` the same as an absent one, matching
     * {@see MutationLogIndexRequest}'s convention elsewhere in this API.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('size') === '') {
            $this->merge(['size' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'size' => ['nullable', 'string', Rule::in(AssetLabelPdfService::SIZES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'size.in' => 'Ukuran label tidak valid. Pilihan: '.implode(', ', AssetLabelPdfService::SIZES).'.',
        ];
    }

    public function size(): string
    {
        return $this->validated('size') ?? AssetLabelPdfService::DEFAULT_SIZE;
    }
}
