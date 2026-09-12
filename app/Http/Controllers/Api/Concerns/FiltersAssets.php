<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Http\Requests\Api\AssetIndexRequest;
use App\Models\Asset;
use Illuminate\Database\Eloquent\Builder;

/**
 * The exact `assets` filter chain `GET /api/assets` (Tahap 5.3, multi-value Tahap
 * 5.8.1) already applies — extracted verbatim (Tahap 6.2) so the export endpoint
 * can share it instead of re-implementing a second filter vocabulary. Behaviour is
 * byte-for-byte identical to before the extraction: {@see AssetIndexRequest} stays
 * the single source of truth for what a filter parameter means, this trait only
 * turns its validated accessors into the same `Builder` chain (plus the same
 * `?q=` search, including the room-name match), and callers add their own
 * `->orderBy()` / `->paginate()` / `->get()` on top.
 *
 * Requires `ApiController::escapeLike()` on the consuming controller (both
 * `AssetController` and `AssetExportController` extend `ApiController`).
 */
trait FiltersAssets
{
    /** Columns `?q=` searches directly; room name is matched via the relation. */
    private const SEARCHABLE = ['asset_code', 'sequence_no', 'brand_model', 'serial_no', 'material', 'notes'];

    protected function assetsMatchingFilters(AssetIndexRequest $request): Builder
    {
        return Asset::query()
            ->with(['location', 'category', 'room'])
            ->when($request->locationCodes(), fn (Builder $q, array $codes) => $q->whereIn('location_code', $codes))
            ->when($request->categoryCodes(), fn (Builder $q, array $codes) => $q->whereIn('category_code', $codes))
            ->when($request->subcategoryPairs(), fn (Builder $q, array $pairs) => $q->where(function (Builder $inner) use ($pairs): void {
                foreach ($pairs as [$categoryCode, $subcategoryCode]) {
                    $inner->orWhere(fn (Builder $w) => $w
                        ->where('category_code', $categoryCode)
                        ->where('subcategory_code', $subcategoryCode));
                }
            }))
            ->when($request->roomIds(), fn (Builder $q, array $ids) => $q->whereIn('room_id', $ids))
            ->when($request->conditionFilter(), fn (Builder $q, array $condition) => $q->where(function (Builder $inner) use ($condition): void {
                if ($condition['values'] !== []) {
                    $inner->orWhereIn('condition', $condition['values']);
                }
                if ($condition['includeNull']) {
                    $inner->orWhereNull('condition');
                }
            }))
            ->when($request->assetYears(), fn (Builder $q, array $years) => $q->whereIn('asset_year', $years))
            ->when($request->writtenOffValue() !== null, fn (Builder $q) => $q->where('is_written_off', $request->writtenOffValue()))
            ->when($request->validated('q'), fn (Builder $q, string $term) => $this->applyAssetSearch($q, $term));
    }

    private function applyAssetSearch(Builder $query, string $term): void
    {
        $like = '%'.$this->escapeLike($term).'%';

        $query->where(function (Builder $q) use ($like): void {
            foreach (self::SEARCHABLE as $column) {
                $q->orWhere($column, 'like', $like);
            }
            $q->orWhereHas('room', fn (Builder $r) => $r->where('name', 'like', $like));
        });
    }
}
