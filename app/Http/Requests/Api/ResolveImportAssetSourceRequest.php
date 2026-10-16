<?php

namespace App\Http\Requests\Api;

use App\Enums\AssetSource;
use App\Import\SourceResolution\AssetSourceResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/imports/{batch}/asset-sources/resolve` (Tahap 2 — Sumber Aset).
 *
 * Shape validation only: `row_ids` (1..500 distinct ids) and `asset_source`
 * (one of the six {@see AssetSource} values, normalised first — any value
 * outside the enum is a 422, so a manipulated request can never store an
 * arbitrary string). Whether the rows belong to this batch, are still
 * unpromoted and are within the actor's location scope depends on live DB
 * state and is checked by {@see AssetSourceResolver::resolve()}, the same split
 * as {@see ResolveRoomMappingRequest}.
 */
class ResolveImportAssetSourceRequest extends FormRequest
{
    public const MAX_ROWS = 500;

    public function authorize(): bool
    {
        return true; // route middleware: auth:sanctum + auth.active + can:assets.import; location scope checked in AssetSourceResolver
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('asset_source')) {
            $this->merge(['asset_source' => AssetSource::canonicalOrOriginal($this->input('asset_source'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'row_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'row_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'asset_source' => ['required', Rule::enum(AssetSource::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'row_ids.required' => 'Pilih minimal satu baris.',
            'row_ids.min' => 'Pilih minimal satu baris.',
            'row_ids.max' => 'Maksimal '.self::MAX_ROWS.' baris per perubahan.',
            'asset_source.required' => 'Pilih sumber aset.',
        ];
    }

    /** @return list<int> */
    public function rowIds(): array
    {
        return array_values(array_map('intval', $this->validated('row_ids')));
    }

    public function assetSource(): AssetSource
    {
        return AssetSource::from($this->validated('asset_source'));
    }
}
