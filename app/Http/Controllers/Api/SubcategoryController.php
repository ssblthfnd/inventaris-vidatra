<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\SubcategoryResource;
use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only master data: subcategories.
 *
 * The list endpoint is scoped to one category: `GET /api/categories/{category}/subcategories`.
 * The `category` shown on each subcategory is always its own `category_code` — a bare
 * `code` is never trusted as globally unique (schema_design.md §3.2).
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
}
