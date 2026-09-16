<?php

namespace App\Services\Dashboard;

use App\Models\Asset;
use App\Models\MutationLog;
use App\Models\User;
use App\Support\LocationScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Aggregation source for `GET /api/dashboard` (Tahap 5.6; location-scoped as
 * of Stage 6.9 R4).
 *
 * Everything is done with database aggregation ("COUNT / GROUP BY / conditional
 * SUM / JOIN") — never `Asset::all()` then group in PHP — so the endpoint stays
 * cheap as the table grows.
 *
 * Asset scope everywhere: **active assets only** = the default `assets` query
 * (`deleted_at IS NULL`). A written-off asset is still an asset and is counted;
 * a soft-deleted asset is not. No `withTrashed()`.
 *
 * Stage 6.9 R4 — a global role (admin/super_admin/operator/viewer) gets
 * exactly the same numbers as before (no location constraint added at all).
 * A `unit_admin` gets every aggregate computed ONLY from assets in their own
 * assigned location — enforced at the query level (a location/category/room
 * with zero matching assets after scoping still appears with `asset_count =
 * 0`, same "show every active master row" convention as before scoping
 * existed; it is never simply hidden from the response, and it never
 * contains another unit's actual asset data).
 */
class DashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        // `resolveFilterCodes([])` with no explicit request = "what should
        // this actor see by default": null (no constraint) for a global
        // role, or exactly their own one location for unit_admin — there is
        // no location query parameter on this endpoint to merge with.
        $locationCodes = LocationScope::for($user)->resolveFilterCodes([]);

        return [
            'summary' => $this->summary($locationCodes),
            'by_location' => $this->byLocation($locationCodes),
            'by_category' => $this->byCategory($locationCodes),
            'by_room' => $this->byRoom($locationCodes),
            'recent_mutations' => $this->recentMutations($locationCodes),
        ];
    }

    /**
     * One conditional-aggregation query over active assets.
     *
     * @param  ?list<string>  $locationCodes  null = no constraint (global)
     * @return array<string, mixed>
     */
    private function summary(?array $locationCodes): array
    {
        $row = Asset::query()
            ->when($locationCodes !== null, fn ($q) => $q->whereIn('location_code', $locationCodes))
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
     * All active locations, including those with zero (matching) assets.
     * Ordered by code. When scoped, the JOIN condition itself excludes
     * out-of-scope assets — every active location still appears (same
     * "zero-count master rows" convention as before R4), but a location
     * outside the actor's scope always shows `asset_count = 0`, never the
     * real cross-unit count.
     *
     * @param  ?list<string>  $locationCodes
     * @return list<array<string, mixed>>
     */
    private function byLocation(?array $locationCodes): array
    {
        return DB::table('locations as l')
            ->leftJoin('assets as a', function (JoinClause $join) use ($locationCodes): void {
                $join->on('a.location_code', '=', 'l.code')->whereNull('a.deleted_at');
                if ($locationCodes !== null) {
                    $join->whereIn('a.location_code', $locationCodes);
                }
            })
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
     * All active categories, including those with zero (matching) assets.
     * Ordered by code. Same scoped-JOIN treatment as {@see byLocation()}.
     *
     * @param  ?list<string>  $locationCodes
     * @return list<array<string, mixed>>
     */
    private function byCategory(?array $locationCodes): array
    {
        return DB::table('categories as c')
            ->leftJoin('assets as a', function (JoinClause $join) use ($locationCodes): void {
                $join->on('a.category_code', '=', 'c.code')->whereNull('a.deleted_at');
                if ($locationCodes !== null) {
                    $join->whereIn('a.location_code', $locationCodes);
                }
            })
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
     * Rooms that currently hold at least one (matching) active asset (INNER
     * JOIN). Grouping is location-aware (`r.id`), so same-named rooms in
     * different locations stay separate. Ordered by location code, then room
     * name, then id. Scoping the JOIN here means an out-of-scope location's
     * rooms never appear at all — there is no matching asset row left to
     * join them to, so unlike `byLocation()`/`byCategory()` this list is
     * naturally narrowed, not just zero-counted.
     *
     * @param  ?list<string>  $locationCodes
     * @return list<array<string, mixed>>
     */
    private function byRoom(?array $locationCodes): array
    {
        return DB::table('rooms as r')
            ->join('assets as a', function (JoinClause $join) use ($locationCodes): void {
                $join->on('a.room_id', '=', 'r.id')->whereNull('a.deleted_at');
                if ($locationCodes !== null) {
                    $join->whereIn('a.location_code', $locationCodes);
                }
            })
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
     * The 10 most recent mutation logs, performer eager-loaded. Serialised by
     * MutationLogResource — historical room-label snapshots, no
     * re-resolution from the current `rooms` master. When scoped, restricted
     * to mutations whose asset is in the actor's location — `withTrashed()`
     * on the `asset` existence check so a scoped actor sees the exact same
     * set a global actor would, minus location, never incidentally hiding a
     * since-trashed asset's mutations that a global actor would still see.
     *
     * @param  ?list<string>  $locationCodes
     * @return Collection<int, MutationLog>
     */
    private function recentMutations(?array $locationCodes): Collection
    {
        return MutationLog::query()
            ->with('createdBy')
            ->when(
                $locationCodes !== null,
                fn ($q) => $q->whereHas('asset', fn ($aq) => $aq->withTrashed()->whereIn('location_code', $locationCodes))
            )
            ->orderByDesc('mutation_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }
}
