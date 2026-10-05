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
 * Asset scope everywhere: **all non-deleted assets** = the default `assets`
 * query (`deleted_at IS NULL`). A written-off asset is still an asset and is
 * counted (R9.4-15 D15-2: "Total Aset" keeps this meaning); a soft-deleted
 * asset is not (it is in the Trash). No `withTrashed()`. Every breakdown —
 * condition, location, category, room incl. the "Tanpa Ruangan" bucket — sums
 * to `summary.total_assets`.
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
    /** Tahap 6.9 R9.4-15 (D15-3) — label of the virtual `room_id IS NULL` bucket in `by_room` (never a real room). */
    public const ROOMLESS_LABEL = 'Tanpa Ruangan';

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
     * All active locations, including those with zero (matching) assets, plus
     * (Tahap 6.9 R9.4-15, D15-5) any INACTIVE location that still holds
     * matching assets — deactivating a location does not remove its assets, so
     * they stay represented and the rows keep summing to `summary.total_assets`.
     * An inactive location with no matching asset stays hidden, as before.
     * Each row carries `is_active`. Ordered by code. When scoped, the JOIN condition itself excludes
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
            ->groupBy('l.code', 'l.name', 'l.is_active')
            ->havingRaw('l.is_active = 1 OR COUNT(a.id) > 0')
            ->orderBy('l.code')
            ->select('l.code', 'l.name', 'l.is_active')
            ->selectRaw('COUNT(a.id) as asset_count')
            ->get()
            ->map(fn ($r) => [
                'code' => $r->code,
                'name' => $r->name,
                'is_active' => (bool) $r->is_active,
                'asset_count' => (int) $r->asset_count,
            ])
            ->all();
    }

    /**
     * All active categories, including those with zero (matching) assets, plus
     * any inactive category that still holds matching assets (D15-5, same rule
     * as {@see byLocation()}). Ordered by code. Same scoped-JOIN treatment.
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
            ->groupBy('c.code', 'c.name', 'c.is_active')
            ->havingRaw('c.is_active = 1 OR COUNT(a.id) > 0')
            ->orderBy('c.code')
            ->select('c.code', 'c.name', 'c.is_active')
            ->selectRaw('COUNT(a.id) as asset_count')
            ->get()
            ->map(fn ($r) => [
                'code' => $r->code,
                'name' => $r->name,
                'is_active' => (bool) $r->is_active,
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
     * Tahap 6.9 R9.4-15 (D15-3) — matching assets with no room (`room_id IS
     * NULL`) follow LAST as one virtual bucket ({@see ROOMLESS_LABEL}; `id`,
     * `location_code`, `location_name` NULL), only when there is at least one —
     * the same "only buckets that hold an asset" rule as real rooms. Scoped by
     * the asset's own `location_code` (a roomless asset has no room to scope
     * by). With it, the rows sum to `summary.total_assets`.
     *
     * @param  ?list<string>  $locationCodes
     * @return list<array<string, mixed>>
     */
    private function byRoom(?array $locationCodes): array
    {
        $rooms = DB::table('rooms as r')
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

        $roomless = Asset::query()
            ->whereNull('room_id')
            ->when($locationCodes !== null, fn ($q) => $q->whereIn('location_code', $locationCodes))
            ->count();

        if ($roomless > 0) {
            $rooms[] = [
                'id' => null,
                'name' => self::ROOMLESS_LABEL,
                'location_code' => null,
                'location_name' => null,
                'asset_count' => $roomless,
            ];
        }

        return $rooms;
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
