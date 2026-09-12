<?php

namespace App\Http\Requests\Api;

use App\Services\Label\AssetLabelPdfService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/assets/batch/label` (Tahap 6.0) — generate one PDF containing a label
 * for every requested asset. Mirrors {@see BatchUpdateAssetRequest} /
 * {@see BatchDeleteAssetRequest}'s `asset_ids` contract exactly:
 *
 *  - required array, 1..1000 entries, no duplicates (`distinct`)
 *  - every id must resolve to a non-trashed asset (`whereNull('deleted_at')`)
 *
 * A trashed or nonexistent id fails the WHOLE request with 422 — no silent partial
 * PDF (same all-or-nothing choice the other batch endpoints already made). Label
 * generation is read-only, but "which assets are eligible" still follows the same
 * rule as the other batch endpoints for consistency and because a soft-deleted
 * asset is not something the app prints a fresh physical label for.
 *
 * `size` (Tahap 6.0.2): one of {@see AssetLabelPdfService::SIZES}, defaulting to
 * `'small'` when omitted — same contract as {@see ShowAssetLabelRequest}'s query
 * param, just carried in the JSON body instead since this is a POST.
 */
class BatchLabelAssetRequest extends FormRequest
{
    public const MAX_ASSETS = 1000;

    public function authorize(): bool
    {
        return true; // route middleware: auth:sanctum + auth.active + can:operator
    }

    /**
     * Treat a blank `size` the same as an absent one, matching
     * {@see ShowAssetLabelRequest} / `MutationLogIndexRequest`'s convention
     * elsewhere in this API.
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
            'asset_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ASSETS],
            'asset_ids.*' => [
                'distinct', 'integer',
                Rule::exists('assets', 'id')->whereNull('deleted_at'),
            ],
            'size' => ['nullable', 'string', Rule::in(AssetLabelPdfService::SIZES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'asset_ids.required' => 'Pilih minimal satu aset.',
            'asset_ids.array' => 'Format asset_ids tidak valid.',
            'asset_ids.min' => 'Pilih minimal satu aset.',
            'asset_ids.max' => 'Maksimal '.self::MAX_ASSETS.' aset per batch.',
            'asset_ids.*.distinct' => 'asset_ids tidak boleh mengandung duplikat.',
            'asset_ids.*.integer' => 'asset_ids harus berupa angka.',
            'asset_ids.*.exists' => 'Salah satu aset tidak ditemukan.',
            'size.in' => 'Ukuran label tidak valid. Pilihan: '.implode(', ', AssetLabelPdfService::SIZES).'.',
        ];
    }

    /**
     * The requested ids, in the exact order submitted — label order follows this.
     *
     * @return array<int, int>
     */
    public function assetIds(): array
    {
        return array_map('intval', $this->validated('asset_ids'));
    }

    public function size(): string
    {
        return $this->validated('size') ?? AssetLabelPdfService::DEFAULT_SIZE;
    }
}
