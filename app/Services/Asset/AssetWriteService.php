<?php

namespace App\Services\Asset;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Business-critical asset lifecycle operations (Tahap 5.4 §27).
 *
 * The controller stays thin: it validates (FormRequests) and delegates here. This
 * service owns transactions, the sequence generator, concurrency retries, and
 * mutation logging.
 *
 *  - `asset_code` is a DB generated column — never assigned; the model is refreshed
 *    after every write so the Resource emits the current value (§20).
 *  - `sequence_no` is allocated by {@see AssetNumberGenerator} on create and is
 *    NEVER changed afterwards (§10, §19).
 *  - Soft delete / restore keep the same row, id, sequence and asset_code (§7, §23).
 *  - written-off is a business status, independent of soft delete (§8).
 */
class AssetWriteService
{
    /** Descriptive + placement columns a client may set on create/update. */
    private const WRITABLE = [
        'location_code', 'category_code', 'subcategory_code', 'asset_year', 'room_id',
        'condition', 'brand_model', 'serial_no', 'material', 'purchase_date',
        'funding_source', 'detail_type', 'capacity_note', 'notes',
    ];

    public function __construct(
        private readonly AssetNumberGenerator $numbers,
        private readonly AssetMutationRecorder $mutations,
    ) {}

    /**
     * Create an asset with a server-allocated sequence number.
     *
     * @param  array<string, mixed>  $data  validated StoreAssetRequest payload
     */
    public function create(array $data, User $actor): Asset
    {
        $writtenOff = (bool) ($data['is_written_off'] ?? false);

        return $this->withDuplicateRetry(function () use ($data, $actor, $writtenOff): Asset {
            return DB::transaction(function () use ($data, $actor, $writtenOff): Asset {
                $this->numbers->lockScope($data['category_code'], $data['subcategory_code']);

                $asset = new Asset(Arr::only($data, self::WRITABLE));
                $asset->sequence_no = $this->numbers->next(
                    $data['location_code'],
                    $data['category_code'],
                    $data['subcategory_code'],
                );
                $asset->quantity = 1;
                $asset->is_written_off = $writtenOff;
                $asset->written_off_on = $writtenOff ? ($data['written_off_on'] ?? null) : null;
                $asset->written_off_note = $writtenOff ? ($data['written_off_note'] ?? null) : null;
                $asset->created_by = $actor->id;
                $asset->updated_by = $actor->id;
                $asset->save();

                return $asset->refresh();
            });
        });
    }

    /**
     * Update descriptive / placement fields. Never touches `sequence_no` or
     * `asset_code`. Records a `pindah_ruangan` mutation iff location and/or room
     * changed (§19, §33).
     *
     * @param  array<string, mixed>  $data  validated UpdateAssetRequest payload
     */
    public function update(Asset $asset, array $data, User $actor): Asset
    {
        return DB::transaction(function () use ($asset, $data, $actor): Asset {
            $before = $this->placementSnapshot($asset);

            $asset->fill(Arr::only($data, self::WRITABLE));
            $asset->quantity = 1;
            $asset->updated_by = $actor->id;
            $asset->save();
            $asset->refresh();

            $after = $this->placementSnapshot($asset);

            if ($before['location_code'] !== $after['location_code'] || $before['room_id'] !== $after['room_id']) {
                $this->mutations->recordRelocation(
                    $asset,
                    $before,
                    $after,
                    $actor,
                    $data['mutation_note'] ?? null,
                );
            }

            return $asset->refresh();
        });
    }

    /** Soft delete. The row, its number and its mutation history all remain (§22, §36). */
    public function softDelete(Asset $asset): void
    {
        DB::transaction(fn () => $asset->delete());
    }

    /**
     * Restore a soft-deleted asset. Same id / sequence / asset_code, no new number
     * consumed, no duplicate created — the row already owns its identity slot in
     * `uq_assets_number` (which counts trashed rows), so a collision is impossible
     * unless the DB was tampered with directly (§23, §37).
     */
    public function restore(Asset $asset): Asset
    {
        if (! $asset->trashed()) {
            return $asset->refresh();
        }

        return $this->withDuplicateRetry(function () use ($asset): Asset {
            return DB::transaction(function () use ($asset): Asset {
                $asset->restore();

                return $asset->refresh();
            });
        }, attempts: 1);
    }

    /**
     * Mark written-off. Physical `condition` is left untouched (§8, §18, §24, §35).
     *
     * @param  array{written_off_on:string, written_off_note?:string|null}  $data
     */
    public function writeOff(Asset $asset, array $data, User $actor): Asset
    {
        if ($asset->is_written_off) {
            abort(409, 'Aset sudah berstatus written-off.');
        }

        return DB::transaction(function () use ($asset, $data, $actor): Asset {
            $asset->is_written_off = true;
            $asset->written_off_on = $data['written_off_on'];
            $asset->written_off_note = $data['written_off_note'] ?? null;
            $asset->updated_by = $actor->id;
            $asset->save();

            return $asset->refresh();
        });
    }

    /** Clear written-off status, date and note (§8, §35). */
    public function unwriteOff(Asset $asset, User $actor): Asset
    {
        if (! $asset->is_written_off) {
            abort(409, 'Aset tidak berstatus written-off.');
        }

        return DB::transaction(function () use ($asset, $actor): Asset {
            $asset->is_written_off = false;
            $asset->written_off_on = null;
            $asset->written_off_note = null;
            $asset->updated_by = $actor->id;
            $asset->save();

            return $asset->refresh();
        });
    }

    /**
     * @return array{location_code:?string, room_id:?int, condition:mixed}
     */
    private function placementSnapshot(Asset $asset): array
    {
        return [
            'location_code' => $asset->location_code,
            'room_id' => $asset->room_id === null ? null : (int) $asset->room_id,
            'condition' => $asset->condition,
        ];
    }

    /**
     * Retry a closure a few times when it hits a duplicate-key / deadlock / lock-wait
     * race — each retry re-runs the generator against the now-committed state (§12).
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function withDuplicateRetry(callable $operation, int $attempts = 4): mixed
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $operation();
            } catch (QueryException $e) {
                if (! $this->isRetryable($e)) {
                    throw $e;
                }
                $lastException = $e;
                usleep(random_int(5_000, 25_000));
            }
        }

        throw $lastException;
    }

    private function isRetryable(QueryException $e): bool
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        // 1062 duplicate entry · 1213 deadlock · 1205 lock wait timeout
        return in_array($driverCode, [1062, 1213, 1205], true);
    }
}
