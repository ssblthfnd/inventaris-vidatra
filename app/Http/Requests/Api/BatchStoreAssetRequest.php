<?php

namespace App\Http\Requests\Api;

/**
 * `POST /api/assets/batch` (Tahap 5.8.4) — create many identical assets at once.
 *
 * Reuses every field rule from {@see StoreAssetRequest} (classification, composite
 * subcategory, room↔location, descriptive fields, `asset_year`, `sequence_no` /
 * `asset_code` still `prohibited`) and adds `count`. Written-off fields are
 * additionally `prohibited` here — a batch never creates disposed assets.
 *
 * The number of records is `count`; every created asset still has `quantity = 1`
 * (one row = one physical unit — the batch is NOT `quantity = count`).
 */
class BatchStoreAssetRequest extends StoreAssetRequest
{
    public const MAX_COUNT = 1000;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'count' => ['required', 'integer', 'min:1', 'max:'.self::MAX_COUNT],

            // status changes go nowhere near a batch create
            'is_written_off' => ['prohibited'],
            'written_off_on' => ['prohibited'],
            'written_off_note' => ['prohibited'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'count.required' => 'Jumlah aset wajib diisi.',
            'count.integer' => 'Jumlah aset harus berupa bilangan bulat.',
            'count.min' => 'Jumlah aset minimal 1.',
            'count.max' => 'Jumlah aset maksimal '.self::MAX_COUNT.'.',
            'is_written_off.prohibited' => 'Batch create tidak menerima status penghapusan.',
            'written_off_on.prohibited' => 'Batch create tidak menerima status penghapusan.',
            'written_off_note.prohibited' => 'Batch create tidak menerima status penghapusan.',
        ]);
    }

    public function assetCount(): int
    {
        return (int) $this->validated('count');
    }

    /**
     * The validated payload minus `count` — the per-asset template.
     *
     * @return array<string, mixed>
     */
    public function assetTemplate(): array
    {
        $data = $this->validated();
        unset($data['count']);

        return $data;
    }
}
