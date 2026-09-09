<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only master data: categories. Active-only, finite collection.
 */
class CategoryController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $categories = Category::query()
            ->where('is_active', true)
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
}
