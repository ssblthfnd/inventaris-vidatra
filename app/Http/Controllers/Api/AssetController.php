<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\AssetIndexRequest;
use App\Http\Resources\AssetCollection;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only inventory asset API (Tahap 5.3).
 *
 *  - Soft-deleted assets are NEVER returned (SoftDeletes default scope) — no withTrashed().
 *  - `is_written_off` is a filterable STATUS, not a deletion — written-off assets appear
 *    normally and can be filtered with `is_written_off=1|0`.
 *  - `subcategory` is resolved from the composite `(category_code, subcategory_code)` and
 *    bulk-loaded to avoid N+1 (Asset::loadSubcategoriesFor).
 */
class AssetController extends ApiController
{
    private const SEARCHABLE = ['asset_code', 'sequence_no', 'brand_model', 'serial_no', 'material', 'notes'];

    public function index(AssetIndexRequest $request): AssetCollection
    {
        $filters = $request->validated();

        $query = Asset::query()
            ->with(['location', 'category', 'room'])
            ->when(isset($filters['location_code']), fn (Builder $q) => $q->where('location_code', $filters['location_code']))
            ->when(isset($filters['category_code']), fn (Builder $q) => $q->where('category_code', $filters['category_code']))
            ->when(isset($filters['subcategory_code']), fn (Builder $q) => $q->where('subcategory_code', $filters['subcategory_code']))
            ->when(isset($filters['room_id']), fn (Builder $q) => $q->where('room_id', $filters['room_id']))
            ->when(isset($filters['condition']), fn (Builder $q) => $q->where('condition', $filters['condition']))
            ->when(isset($filters['is_written_off']), fn (Builder $q) => $q->where('is_written_off', $request->boolean('is_written_off')))
            ->when(isset($filters['asset_year']), fn (Builder $q) => $q->where('asset_year', $filters['asset_year']))
            ->when(isset($filters['q']), fn (Builder $q) => $this->applySearch($q, $filters['q']));

        $query->orderBy($request->sortColumn(), $request->sortDirection())->orderBy('id');

        $assets = $query->paginate($request->perPage())->withQueryString();

        Asset::loadSubcategoriesFor($assets->getCollection());

        return new AssetCollection($assets);
    }

    public function show(Asset $asset): AssetResource
    {
        $asset->load(['location', 'category', 'room']);

        return new AssetResource($asset);
    }

    private function applySearch(Builder $query, string $term): void
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
