<?php

namespace App\Import\Validation;

use Illuminate\Support\Facades\DB;

/**
 * In-memory snapshot of the master tables, loaded once per import run. Used for
 * read-only validation. The importer NEVER writes to these tables (Tahap 4 §41).
 */
final class MasterData
{
    /** @var array<string,true> */
    private array $locations = [];
    /** @var array<string,true> */
    private array $categories = [];
    /** @var array<string,true>  key = "categoryCode|subcategoryCode" */
    private array $subcategoryPairs = [];

    public function __construct()
    {
        foreach (DB::table('locations')->pluck('code') as $code) {
            $this->locations[$code] = true;
        }
        foreach (DB::table('categories')->pluck('code') as $code) {
            $this->categories[$code] = true;
        }
        foreach (DB::table('subcategories')->get(['category_code', 'code']) as $s) {
            $this->subcategoryPairs["{$s->category_code}|{$s->code}"] = true;
        }
    }

    public function hasLocation(?string $code): bool
    {
        return $code !== null && isset($this->locations[$code]);
    }

    public function hasCategory(?string $code): bool
    {
        return $code !== null && isset($this->categories[$code]);
    }

    public function hasSubcategoryPair(?string $categoryCode, ?string $subcategoryCode): bool
    {
        return $categoryCode !== null
            && $subcategoryCode !== null
            && isset($this->subcategoryPairs["{$categoryCode}|{$subcategoryCode}"]);
    }
}
