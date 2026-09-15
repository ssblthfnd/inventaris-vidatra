<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreSubcategoryRequest;
use App\Http\Requests\Api\UpdateSubcategoryRequest;
use App\Http\Resources\SubcategoryResource;
use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Master data: subcategories.
 *
 * `index()`/`show()` (Tahap 5.3) are read-only, active-only, nested under a
 * category, and untouched by Tahap 6.8.4 — every existing behaviour stays
 * exactly as it was (see `MasterDataReadApiTest`). The `category` shown on
 * each subcategory is always its own `category_code` — a bare `code` is
 * never trusted as globally unique (schema_design.md §3.2).
 *
 * `adminIndex()`/`store()`/`update()` (Tahap 6.8.4, `can:admin`) are new,
 * separate — deliberately a different route (`GET /api/subcategories`,
 * flat, every category, active AND inactive) rather than adding an
 * "include inactive" mode to the existing nested `index()`, mirroring
 * exactly how Tahap 6.8.1 added `GET /api/rooms` alongside
 * `GET /api/locations/{location}/rooms`. This also lets an admin browse
 * subcategories that belong to an INACTIVE category, which the existing
 * nested `index()` 404s on for everyone.
 *
 * Deactivating a subcategory performs NO cascade — assets referencing it
 * are left completely untouched (same reasoning as `CategoryController`).
 * `category_code`/`code` are immutable — see StoreSubcategoryRequest's
 * docblock.
 */
class SubcategoryController extends ApiController
{
    public function index(Request $request, Category $category): AnonymousResourceCollection
    {
        abort_if(! $category->is_active, 404);

        $subcategories = $category->subcategories()
            ->where('is_active', true)
            ->when($request->filled('q'), function ($query) use ($request) {
                $like = '%'.$this->escapeLike((string) $request->string('q')).'%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like));
            })
            ->orderBy('code')
            ->get()
            ->each->setRelation('category', $category);

        return SubcategoryResource::collection($subcategories);
    }

    public function show(Subcategory $subcategory): SubcategoryResource
    {
        abort_if(! $subcategory->is_active, 404);

        $subcategory->load('category');

        return new SubcategoryResource($subcategory);
    }

    /**
     * `GET /api/subcategories` (Tahap 6.8.4), `can:admin` — every subcategory
     * across every category, active AND inactive, for the Master Data
     * management list. Unpaginated like every other master-data collection.
     */
    public function adminIndex(Request $request): AnonymousResourceCollection
    {
        $subcategories = Subcategory::query()
            ->with('category')
            ->when($request->filled('category_code'), function ($query) use ($request) {
                $query->where('category_code', $request->string('category_code'));
            })
            ->when($request->filled('q'), function ($query) use ($request) {
                $like = '%'.$this->escapeLike((string) $request->string('q')).'%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('guide_name', 'like', $like));
            })
            ->orderBy('category_code')
            ->orderBy('code')
            ->get();

        return SubcategoryResource::collection($subcategories);
    }

    public function store(StoreSubcategoryRequest $request): JsonResponse
    {
        $subcategory = Subcategory::create([
            ...$request->validated(),
            'is_active' => true,
        ]);
        $subcategory->load('category');

        return (new SubcategoryResource($subcategory))->response()->setStatusCode(201);
    }

    public function update(UpdateSubcategoryRequest $request, Subcategory $subcategory): SubcategoryResource
    {
        $subcategory->update($request->validated());
        $subcategory->load('category');

        return new SubcategoryResource($subcategory);
    }
}
