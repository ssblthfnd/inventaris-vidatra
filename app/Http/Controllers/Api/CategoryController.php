<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreCategoryRequest;
use App\Http\Requests\Api\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Master data: categories.
 *
 * `index()`/`show()` (Tahap 5.3) stay read-only, active-only BY DEFAULT for
 * every role — untouched for every existing caller. `index()` gained
 * exactly one opt-in addition (Tahap 6.8.4, same pattern as
 * `LocationController`): `?include_inactive=1`, honoured ONLY when the
 * actor passes `can:admin`, silently ignored otherwise. Every existing
 * consumer (`useMasterData()`, `MasterDataReadApiTest`, `ExportMenu.jsx`,
 * `lib/imports.js`) never sends this param, so the default result stays
 * byte-identical to before for every role including admin.
 *
 * `store()`/`update()` (Tahap 6.8.4, `can:admin`) are new. No `destroy()` —
 * `is_active` is the only lifecycle mechanism (same precedent as locations/
 * rooms). Deactivating a category performs NO cascade: subcategories and
 * assets referencing it are left completely untouched — their
 * `restrictOnUpdate`/`restrictOnDelete` FKs to `categories.code` don't care
 * about `is_active` at all. That column only gates NEW child-record
 * creation (`StoreSubcategoryRequest`'s "category must be active" rule) and
 * NEW import matching (`MasterData::hasCategory()`, Tahap 6.8.2, untouched
 * here). `code` is immutable — see StoreCategoryRequest's docblock.
 */
class CategoryController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $includeInactive = $request->boolean('include_inactive') && Gate::allows('admin');

        $categories = Category::query()
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->when($request->filled('q'), function ($query) use ($request) {
                $like = '%'.$this->escapeLike((string) $request->string('q')).'%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like));
            })
            ->orderBy('code')
            ->get();

        return CategoryResource::collection($categories);
    }

    public function show(Category $category): CategoryResource
    {
        abort_if(! $category->is_active, 404);

        return new CategoryResource($category);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = Category::create([
            ...$request->validated(),
            'is_active' => true,
        ]);

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    public function update(UpdateCategoryRequest $request, Category $category): CategoryResource
    {
        $category->update($request->validated());

        return new CategoryResource($category);
    }
}
