<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `DELETE /api/assets/batch` (Tahap 5.8.7) — soft-delete many assets in one atomic
 * request. Mirrors {@see BatchUpdateAssetRequest}'s `asset_ids` contract exactly:
 *
 *  - required array, 1..1000 entries, no duplicates (`distinct`)
 *  - every id must resolve to a non-trashed asset (`whereNull('deleted_at')`)
 *
 * An already-trashed or nonexistent id fails the WHOLE request with 422 — the same
 * choice Tahap 5.8.6 made for batch update. This intentionally differs from the
 * single-asset `DELETE /api/assets/{asset}` route, where a trashed id 404s via route
 * model binding (the default `SoftDeletes` scope simply never finds it): a batch
 * payload has no route-binding step to fail early, so the FormRequest is the
 * equivalent gate, and 422 (not 404) is the batch-endpoint-family convention.
 */
class BatchDeleteAssetRequest extends FormRequest
{
    public const MAX_ASSETS = 1000;

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
            'asset_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ASSETS],
            'asset_ids.*' => [
                'distinct', 'integer',
                Rule::exists('assets', 'id')->whereNull('deleted_at'),
            ],
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
        ];
    }

    /**
     * @return array<int, int>
     */
    public function assetIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->validated('asset_ids'))));
    }
}
