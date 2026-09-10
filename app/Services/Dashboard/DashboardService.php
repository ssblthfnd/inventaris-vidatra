<?php

namespace App\Services\Dashboard;

use App\Models\Asset;
use App\Models\MutationLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregation source for `GET /api/dashboard` (Tahap 5.6).
 *
 * Everything is done with database aggregation ("COUNT / GROUP BY / conditional
 * SUM / JOIN") — never `Asset::all()` then group in PHP — so the endpoint stays
 * cheap as the table grows.
 *
 * Asset scope everywhere: **active assets only** = the default `assets` query
 * (`deleted_at IS NULL`). A written-off asset is still an asset and is counted;
 * a soft-deleted asset is not. No `withTrashed()`.
 */
class DashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return [
            'summary' => $this->summary(),
            'by_location' => $this->byLocation(),
            'by_category' => $this->byCategory(),
            'by_room' => $this->byRoom(),
            'recent_mutations' => $this->recentMutations(),
        ];
    }

    /**
     * One conditional-aggregation query over active assets.
     *
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        $row = Asset::query()
            ->selectRaw('COUNT(*) as total_assets')
            ->selectRaw('COALESCE(SUM(is_written_off = 1), 0) as written_off')
            ->selectRaw("COALESCE(SUM(`condition` = 'baik'), 0) as cond_baik")
            ->selectRaw("COALESCE(SUM(`condition` = 'kurang_baik'), 0) as cond_kurang_baik")
            ->selectRaw("COALESCE(SUM(`condition` = 'rusak_berat'), 0) as cond_rusak_berat")
            ->selectRaw('COALESCE(SUM(`condition` IS NULL), 0) as cond_unknown')
            ->toBase()
            ->first();

        return [
            'total_assets' => (int) $row->total_assets,
            'written_off' => (int) $row->written_off,
            'by_condition' => [
                'baik' => (int) $row->cond_baik,
                'kurang_baik' => (int) $row->cond_kurang_baik,
                'rusak_berat' => (int) $row->cond_rusak_berat,
                'unknown' => (int) $row->cond_unknown,
            ],
        ];
    }

    /**
     * All active locations, including those with zero assets. Ordered by code.
     *
     * @return list<array<string, mixed>>
     */
    private function byLocation(): array
    {
        return DB::table('locations as l')
            ->leftJoin('assets as a', fn ($join) => $join
                ->on('a.location_code', '=', 'l.code')
                ->whereNull('a.deleted_at'))
            ->where('l.is_active', true)
            ->groupBy('l.code', 'l.name')
            ->orderBy('l.code')
            ->select('l.code', 'l.name')
            ->selectRaw('COUNT(a.id) as asset_count')
            ->get()
            ->map(fn ($r) => [
                'code' => $r->code,
                'name' => $r->name,
                'asset_count' => (int) $r->asset_count,
            ])
            ->all();
    }

    /**
     * All active categories, including those with zero assets. Ordered by code.
     *
     * @return list<array<string, mixed>>
     */
    private function byCategory(): array
    {
        return DB::table('categories as c')
            ->leftJoin('assets as a', fn ($join) => $join
                ->on('a.category_code', '=', 'c.code')
                ->whereNull('a.deleted_at'))
            ->where('c.is_active', true)
            ->groupBy('c.code', 'c.name')
            ->orderBy('c.code')
            ->select('c.code', 'c.name')
            ->selectRaw('COUNT(a.id) as asset_count')
            ->get()
            ->map(fn ($r) => [
                'code' => $r->code,
                'name' => $r->name,
                'asset_count' => (int) $r->asset_count,
            ])
            ->all();
    }

    /**
     * Rooms that currently hold at least one active asset (INNER JOIN). Grouping is
     * location-aware (`r.id`), so same-named rooms in different locations stay
     * separate. Ordered by location code, then room name, then id.
     *
     * @return list<array<string, mixed>>
     */
    private function byRoom(): array
    {
        return DB::table('rooms as r')
            ->join('assets as a', fn ($join) => $join
                ->on('a.room_id', '=', 'r.id')
                ->whereNull('a.deleted_at'))
            ->join('locations as l', 'l.code', '=', 'r.location_code')
            ->groupBy('r.id', 'r.name', 'r.location_code', 'l.name')
            ->orderBy('r.location_code')
            ->orderBy('r.name')
            ->orderBy('r.id')
            ->select('r.id', 'r.name', 'r.location_code')
            ->selectRaw('l.name as location_name')
            ->selectRaw('COUNT(a.id) as asset_count')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->name,
                'location_code' => $r->location_code,
                'location_name' => $r->location_name,
                'asset_count' => (int) $r->asset_count,
            ])
            ->all();
    }

    /**
     * The 10 most recent mutation logs (across all assets), performer eager-loaded.
     * Serialised by MutationLogResource — historical room-label snapshots, no
     * re-resolution from the current `rooms` master.
     *
     * @return Collection<int, MutationLog>
     */
    private function recentMutations(): Collection
    {
        return MutationLog::query()
            ->with('createdBy')
            ->orderByDesc('mutation_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }
}
