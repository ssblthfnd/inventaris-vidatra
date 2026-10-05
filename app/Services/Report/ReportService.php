<?php

namespace App\Services\Report;

use App\Services\Dashboard\DashboardService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Aggregation source for `GET /api/reports/inventory` (Tahap 6.3).
 *
 * Unlike {@see DashboardService} (always global, no
 * filters), every aggregate here is computed over the SAME filtered asset scope
 * `GET /api/assets` / `GET /api/assets/export` would show for the same query
 * string — the caller passes in the exact `Builder` {@see
 * \App\Http\Controllers\Api\Concerns\FiltersAssets::assetsMatchingFilters()}
 * already built, so "Kategori = Elektronik, Kondisi = Baik" means byte-for-byte
 * the same thing on `/inventory` and `/reports`.
 *
 * Everything is database aggregation (COUNT / GROUP BY / conditional SUM / JOIN)
 * — the filtered result set is never pulled into PHP to be counted there. Master
 * data breakdowns (location/category) LEFT JOIN the filtered asset subquery onto
 * every active master row, so a location/category with zero matching assets
 * still appears with `asset_count = 0` (mirrors the Tahap 5.6 dashboard's own
 * "show zero-count master rows" convention); an inactive one appears only while
 * it still holds matching assets (R9.4-15 D15-5). Rooms stay an INNER join (only
 * rooms that actually hold a matching asset) — same convention the dashboard
 * already uses, since listing every room in the building would be noise — plus
 * the virtual "Tanpa Ruangan" bucket for matching assets with no room (D15-3).
 *
 * Scope note: `assetsMatchingFilters()` never resolves soft-deleted rows (no
 * `withTrashed()`), so trashed assets are structurally outside every number this
 * service produces — deliberately consistent with `/inventory`, which has no
 * trashed-asset filter either. `summary.total_assets` (all matching non-trashed
 * assets) is what the location/category/room/condition/year breakdowns sum back
 * to; the active/written-off split is a separate lifecycle cut of that same
 * total, not a different scope (see `summary()`) — the Reports page shows all
 * three cards ("Total Aset", "Aset Aktif", "Aset Ditulis Off", D15-1) so a
 * reader can tell which one the breakdowns add up to.
 */
class ReportService
{
    /**
     * @return array<string, mixed>
     */
    public function build(Builder $assets): array
    {
        return [
            'summary' => $this->summary($assets),
            'by_location' => $this->byLocation($assets),
            'by_category' => $this->byCategory($assets),
            'by_room' => $this->byRoom($assets),
            'by_condition' => $this->byCondition($assets),
            'by_year' => $this->byYear($assets),
        ];
    }

    /**
     * The filtered scope reduced to a plain, unhydrated subquery — used as the
     * join target for every master-data breakdown below. `->toBase()` drops
     * Eloquent model hydration and the `with()` eager loads `FiltersAssets`
     * attaches (irrelevant here and wasteful to run per breakdown).
     */
    private function filteredSubquery(Builder $assets): QueryBuilder
    {
        return (clone $assets)->toBase()->select([
            'id', 'location_code', 'category_code', 'room_id', 'condition', 'is_written_off', 'asset_year',
        ]);
    }

    /**
     * One conditional-aggregation query over the filtered scope.
     *
     * @return array<string, int>
     */
    private function summary(Builder $assets): array
    {
        $row = (clone $assets)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(is_written_off = 1), 0) as written_off')
            ->toBase()
            ->first();

        $total = (int) $row->total;
        $writtenOff = (int) $row->written_off;

        return [
            'total_assets' => $total,
            'active_assets' => $total - $writtenOff,
            'written_off_assets' => $writtenOff,
        ];
    }

    /**
     * All active locations, including those with zero matching assets, plus any
     * INACTIVE location that still holds matching assets (Tahap 6.9 R9.4-15,
     * D15-5 — same rule as the dashboard), each row with `is_active`.
     *
     * @return list<array<string, mixed>>
     */
    private function byLocation(Builder $assets): array
    {
        return DB::table('locations as l')
            ->leftJoinSub($this->filteredSubquery($assets), 'a', fn ($join) => $join->on('a.location_code', '=', 'l.code'))
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
     * All active categories, including those with zero matching assets, plus any
     * inactive category that still holds matching assets (D15-5), each row with
     * `is_active`.
     *
     * @return list<array<string, mixed>>
     */
    private function byCategory(Builder $assets): array
    {
        return DB::table('categories as c')
            ->leftJoinSub($this->filteredSubquery($assets), 'a', fn ($join) => $join->on('a.category_code', '=', 'c.code'))
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
     * Rooms that hold at least one matching asset (INNER join, same convention as
     * the dashboard's `by_room` — listing every empty room would be noise).
     * Grouping is location-aware (`r.id`) so same-named rooms in different
     * locations stay separate. Matching assets with no room follow last as the
     * virtual "Tanpa Ruangan" bucket (R9.4-15 D15-3, same shape and rule as the
     * dashboard's — {@see DashboardService::ROOMLESS_LABEL}), counted from the
     * same filtered scope.
     *
     * @return list<array<string, mixed>>
     */
    private function byRoom(Builder $assets): array
    {
        $rooms = DB::table('rooms as r')
            ->joinSub($this->filteredSubquery($assets), 'a', fn ($join) => $join->on('a.room_id', '=', 'r.id'))
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

        $roomless = (clone $assets)->toBase()->whereNull('room_id')->count();

        if ($roomless > 0) {
            $rooms[] = [
                'id' => null,
                'name' => DashboardService::ROOMLESS_LABEL,
                'location_code' => null,
                'location_name' => null,
                'asset_count' => $roomless,
            ];
        }

        return $rooms;
    }

    /**
     * Fixed condition buckets (same three canonical `AssetCondition` values plus
     * `unknown` = NULL, matching the dashboard/filter vocabulary exactly — no
     * inferred or additional statuses).
     *
     * @return array<string, int>
     */
    private function byCondition(Builder $assets): array
    {
        $row = (clone $assets)
            ->selectRaw("COALESCE(SUM(`condition` = 'baik'), 0) as baik")
            ->selectRaw("COALESCE(SUM(`condition` = 'kurang_baik'), 0) as kurang_baik")
            ->selectRaw("COALESCE(SUM(`condition` = 'rusak_berat'), 0) as rusak_berat")
            ->selectRaw('COALESCE(SUM(`condition` IS NULL), 0) as unknown')
            ->toBase()
            ->first();

        return [
            'baik' => (int) $row->baik,
            'kurang_baik' => (int) $row->kurang_baik,
            'rusak_berat' => (int) $row->rusak_berat,
            'unknown' => (int) $row->unknown,
        ];
    }

    /**
     * Distribution by `asset_year` — the column is `NOT NULL` with a DB `CHECK`
     * (1980..2100), so every matching asset contributes exactly one year, no
     * "unknown" bucket needed. Only years actually present are returned.
     *
     * @return list<array{year: int, asset_count: int}>
     */
    private function byYear(Builder $assets): array
    {
        return $this->filteredSubquery($assets)
            ->select('asset_year')
            ->selectRaw('COUNT(*) as asset_count')
            ->groupBy('asset_year')
            ->orderBy('asset_year')
            ->get()
            ->map(fn ($r) => [
                'year' => (int) $r->asset_year,
                'asset_count' => (int) $r->asset_count,
            ])
            ->all();
    }
}
