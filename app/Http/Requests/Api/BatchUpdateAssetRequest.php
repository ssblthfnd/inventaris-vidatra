<?php

namespace App\Http\Requests\Api;

use App\Enums\AssetCondition;
use App\Services\Asset\AssetWriteService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /api/assets/batch` (Tahap 5.8.6) — batch-edit the safe descriptive fields
 * of many assets in one atomic request.
 *
 * Only three fields may ever be batch-edited: `room_id`, `condition`, `notes`.
 * Everything else (classification, identity, lifecycle, audit columns) is
 * deliberately out of scope — see {@see AssetWriteService::batchUpdate()}.
 *
 * Null semantics mirror {@see UpdateAssetRequest}: a `sometimes` + `nullable` pair
 * lets `validated('changes')` distinguish "key absent -> don't touch" from
 * "key present as null -> set NULL", because `validated()` drops keys that were
 * never present in the request at all.
 *
 * `room_id`'s cross-asset location check (a room belongs to exactly one location,
 * so it can never be valid for a multi-location selection) needs the actual
 * selected asset rows and is therefore enforced authoritatively in the service,
 * under the same row locks used to apply the batch — not here.
 */
class BatchUpdateAssetRequest extends FormRequest
{
    public const MAX_ASSETS = 1000;

    private const ALLOWED_CHANGE_KEYS = ['room_id', 'condition', 'notes'];

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

            'changes' => ['required', 'array', 'min:1', $this->onlyAllowedChangeKeys()],
            'changes.room_id' => [
                'sometimes', 'nullable', 'integer', 'min:1',
                Rule::exists('rooms', 'id')->where('is_active', true),
            ],
            'changes.condition' => ['sometimes', 'nullable', Rule::enum(AssetCondition::class)],
            'changes.notes' => ['sometimes', 'nullable', 'string'],
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
            'changes.required' => 'Pilih minimal satu field yang ingin diubah.',
            'changes.array' => 'Format changes tidak valid.',
            'changes.min' => 'Pilih minimal satu field yang ingin diubah.',
            'changes.room_id.exists' => 'Ruangan tidak valid atau tidak aktif.',
        ];
    }

    /**
     * `changes` accepts ONLY `room_id` / `condition` / `notes` — everything else
     * (including every field that has its own dedicated endpoint, like
     * `is_written_off`) is rejected outright rather than silently ignored.
     */
    private function onlyAllowedChangeKeys(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            $unknown = array_diff(array_keys($value), self::ALLOWED_CHANGE_KEYS);
            if ($unknown !== []) {
                $fail('Field yang tidak diizinkan untuk batch edit: '.implode(', ', $unknown).'.');
            }
        };
    }

    /**
     * @return array<int, int>
     */
    public function assetIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->validated('asset_ids'))));
    }

    /**
     * Only the keys actually present in the request — absent keys must never reach
     * the service, or "don't touch this field" would be indistinguishable from
     * "set it to whatever validated() defaults to".
     *
     * @return array{room_id?: ?int, condition?: ?string, notes?: ?string}
     */
    public function changes(): array
    {
        return $this->validated('changes');
    }
}
