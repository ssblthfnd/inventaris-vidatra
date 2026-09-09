<?php

namespace App\Http\Resources;

use App\Models\Subcategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Subcategory $resource
 *
 * The `category` context is always the subcategory's own `category_code` — never
 * guessed from a bare code (which is not globally unique, schema_design.md §3.2).
 */
class SubcategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subcategory = $this->resource;

        return [
            'id' => $subcategory->id,
            'code' => $subcategory->code,
            'name' => $subcategory->name,
            'guide_name' => $subcategory->guide_name,
            'is_active' => $subcategory->is_active,
            'category' => [
                'code' => $subcategory->category_code,
                'name' => $subcategory->category?->name,
            ],
        ];
    }
}
