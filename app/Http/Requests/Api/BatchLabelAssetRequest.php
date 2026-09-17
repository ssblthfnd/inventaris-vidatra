<?php

namespace App\Http\Requests\Api;

use App\Services\Label\AssetLabelPdfService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/assets/batch/label` (Tahap 6.0; `mode` added R8) — generate labels
 * for every requested asset. Mirrors {@see BatchUpdateAssetRequest} /
 * {@see BatchDeleteAssetRequest}'s `asset_ids` contract exactly:
 *
 *  - required array, 1..1000 entries, no duplicates (`distinct`)
 *  - every id must resolve to a non-trashed asset (`whereNull('deleted_at')`)
 *
 * A trashed or nonexistent id fails the WHOLE request with 422 — no silent partial
 * PDF (same all-or-nothing choice the other batch endpoints already made, and
 * the reason R8's Individual mode needed no separate "validate all assets first"
 * step of its own: this FormRequest already validates every id before the
 * controller method — and therefore any file generation — ever runs). Label
 * generation is read-only, but "which assets are eligible" still follows the same
 * rule as the other batch endpoints for consistency and because a soft-deleted
 * asset is not something the app prints a fresh physical label for.
 *
 * `size` (Tahap 6.0.2): one of {@see AssetLabelPdfService::SIZES}, defaulting to
 * `'small'` when omitted — same contract as {@see ShowAssetLabelRequest}'s query
 * param, just carried in the JSON body instead since this is a POST.
 *
 * `mode` (R8): `'a4'` (default, preserves the exact pre-R8 behaviour — one A4
 * sheet with every label positioned in a grid) or `'individual'` — exactly one
 * asset returns its own single-label-sized PDF directly, more than one returns
 * ONE multi-page PDF (one page per asset, every page still exactly the chosen
 * label size, never an A4 sheet) — see {@see AssetLabelController::batch()}.
 * Defaulting to `'a4'` when omitted is what keeps every existing caller of this
 * endpoint (which never sends `mode` at all) on byte-identical behaviour.
 */
class BatchLabelAssetRequest extends FormRequest
{
    public const MAX_ASSETS = 1000;

    public const MODE_A4 = 'a4';

    public const MODE_INDIVIDUAL = 'individual';

    public const MODES = [self::MODE_A4, self::MODE_INDIVIDUAL];

    public function authorize(): bool
    {
        return true; // route middleware: auth:sanctum + auth.active + can:operator
    }

    /**
     * Treat a blank `size`/`mode` the same as an absent one, matching
     * {@see ShowAssetLabelRequest} / `MutationLogIndexRequest`'s convention
     * elsewhere in this API.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];
        if ($this->input('size') === '') {
            $merge['size'] = null;
        }
        if ($this->input('mode') === '') {
            $merge['mode'] = null;
        }
        if ($merge !== []) {
            $this->merge($merge);
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
            'mode' => ['nullable', 'string', Rule::in(self::MODES)],
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
            'mode.in' => 'Mode cetak tidak valid. Pilihan: '.implode(', ', self::MODES).'.',
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

    public function mode(): string
    {
        return $this->validated('mode') ?? self::MODE_A4;
    }
}
