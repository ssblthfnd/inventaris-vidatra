<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Http\Requests\Api\AssetIndexRequest;
use App\Models\Asset;
use App\Support\LocationScope;
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
 *
 * Stage 6.9 R4 — the ONE shared place `location_code` is turned into a WHERE
 * clause, so `AssetController::index()`, `AssetExportController::export()`,
 * and `ReportController::index()` (via `ReportService`, which only ever sees
 * the `Builder` this method already returns) all inherit the same
 * {@see LocationScope}-enforced scope automatically, with no duplicated
 * WHERE clause anywhere. `export` is `can:assets.export`-gated (R9.3)
 * (unit_admin does not hold it — unaffected by this scope in practice), and
 * `reports/inventory` is deliberately regated to `can:assets.report` (see
 * routes/api.php) specifically so unit_admin CAN reach it, scoped, per R4.
 */
trait FiltersAssets
{
    /** Columns `?q=` searches directly; room name is matched via the relation. */
    private const SEARCHABLE = ['asset_code', 'sequence_no', 'brand_model', 'serial_no', 'material', 'notes'];

    protected function assetsMatchingFilters(AssetIndexRequest $request): Builder
    {
        $locationCodes = LocationScope::for($request->user())->resolveFilterCodes($request->locationCodes());

        return Asset::query()
            ->with(['location', 'category', 'room'])
            ->when($locationCodes !== null, fn (Builder $q) => $q->whereIn('location_code', $locationCodes))
            ->when($request->categoryCodes(), fn (Builder $q, array $codes) => $q->whereIn('category_code', $codes))
            ->when($request->subcategoryPairs(), fn (Builder $q, array $pairs) => $q->where(function (Builder $inner) use ($pairs): void {
                foreach ($pairs as [$categoryCode, $subcategoryCode]) {
                    $inner->orWhere(fn (Builder $w) => $w
                        ->where('category_code', $categoryCode)
                        ->where('subcategory_code', $subcategoryCode));
                }
            }))
            // room_id[] may include the `none` sentinel (room_id IS NULL) — same OR-within shape as condition below
            ->when($request->roomFilter(), fn (Builder $q, array $room) => $q->where(function (Builder $inner) use ($room): void {
                if ($room['ids'] !== []) {
                    $inner->orWhereIn('room_id', $room['ids']);
                }
                if ($room['includeNull']) {
                    $inner->orWhereNull('room_id');
                }
            }))
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
