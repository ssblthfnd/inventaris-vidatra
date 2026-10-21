<?php

namespace App\Services\Dashboard;

use App\Enums\AssetSource;
use App\Models\Asset;
use App\Models\MutationLog;
use App\Models\User;
use App\Support\LocationScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
 * condition, location, category, room incl. the "Tanpa Ruangan" bucket, and
 * source (Tahap 2) — sums to `summary.total_assets`.
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
            'by_source' => $this->bySource($locationCodes),
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
            // the "Aset aktif" KPI — same total/active/written-off split as the report's summary
            'active_assets' => (int) $row->total_assets - (int) $row->written_off,
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
     * `GET /api/dashboard/rooms` — the same rows as {@see byRoom()} (rooms holding at
     * least one non-deleted asset, plus the "Tanpa Ruangan" bucket), searched,
     * filtered, sorted and paginated in SQL so the table stays cheap however many
     * rooms exist.
     *
     * `$locationCode` is one requested location, still narrowed by LocationScope:
     * a unit_admin asking for another unit's location gets no rows, never that
     * unit's data (same rule as `GET /api/assets`). It filters on the asset's own
     * location, so it narrows the roomless bucket too. `$likePattern` is an
     * already-escaped LIKE pattern for the row name (the bucket's label included).
     * `$sort` = `count` (most assets first, then name) or `name` (A–Z); either way
     * the roomless bucket stays last, as in `by_room`, and `id` breaks ties.
     */
    public function roomPage(User $user, ?string $likePattern, ?string $locationCode, string $sort, int $perPage): LengthAwarePaginator
    {
        $locationCodes = LocationScope::for($user)->resolveFilterCodes($locationCode === null ? [] : [$locationCode]);
        $inScope = fn ($q, string $column) => $q->when($locationCodes !== null, fn ($w) => $w->whereIn($column, $locationCodes));

        $rooms = DB::table('rooms as r')
            ->join('assets as a', function (JoinClause $join) use ($inScope): void {
                $join->on('a.room_id', '=', 'r.id')->whereNull('a.deleted_at');
                $inScope($join, 'a.location_code');
            })
            ->join('locations as l', 'l.code', '=', 'r.location_code')
            ->groupBy('r.id', 'r.name', 'r.location_code', 'l.name')
            ->select('r.id', 'r.name', 'r.location_code')
            ->selectRaw('l.name as location_name')
            ->selectRaw('COUNT(a.id) as asset_count');

        // one aggregate row; HAVING drops it when there is no roomless asset
        $roomless = DB::table('assets')
            ->whereNull('deleted_at')
            ->whereNull('room_id')
            ->tap(fn (QueryBuilder $q) => $inScope($q, 'location_code'))
            ->selectRaw('NULL as id, ? as name, NULL as location_code, NULL as location_name, COUNT(*) as asset_count', [self::ROOMLESS_LABEL])
            ->havingRaw('COUNT(*) > 0');

        $query = DB::query()
            ->fromSub($rooms->unionAll($roomless), 'x')
            ->when($likePattern !== null, fn ($q) => $q->where('x.name', 'like', $likePattern))
            ->orderByRaw('x.id IS NULL');

        if ($sort === 'name') {
            $query->orderBy('x.name')->orderBy('x.location_name');
        } else {
            $query->orderByDesc('x.asset_count')->orderBy('x.name');
        }

        return $query->orderBy('x.id')->paginate($perPage)->through(fn ($r) => [
            'id' => $r->id === null ? null : (int) $r->id,
            'name' => $r->name,
            'location_code' => $r->location_code,
            'location_name' => $r->location_name,
            'asset_count' => (int) $r->asset_count,
        ]);
    }

    /**
     * Tahap 2 — active assets per {@see AssetSource}: ALL six categories, in
     * enum order, zero-count ones included (same fixed-bucket convention as
     * `summary.by_condition`). `asset_source` is NOT NULL, so the rows always sum
     * to `summary.total_assets` (non-deleted, written-off included, same location
     * scope). One GROUP BY query.
     *
     * @param  ?list<string>  $locationCodes
     * @return list<array{value: string, label: string, asset_count: int}>
     */
    private function bySource(?array $locationCodes): array
    {
        $counts = Asset::query()
            ->when($locationCodes !== null, fn ($q) => $q->whereIn('location_code', $locationCodes))
            ->selectRaw('asset_source, COUNT(*) as asset_count')
            ->groupBy('asset_source')
            ->toBase()
            ->pluck('asset_count', 'asset_source');

        return self::sourceBuckets($counts->all());
    }

    /**
     * Tahap 2 — shared by this service and ReportService: every {@see AssetSource}
     * in enum order with its count from `$counts` (keyed by stored value), 0 when
     * absent.
     *
     * @param  array<string, int|string>  $counts
     * @return list<array{value: string, label: string, asset_count: int}>
     */
    public static function sourceBuckets(array $counts): array
    {
        return array_map(fn (AssetSource $source): array => [
            'value' => $source->value,
            'label' => $source->label(),
            'asset_count' => (int) ($counts[$source->value] ?? 0),
        ], AssetSource::cases());
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
