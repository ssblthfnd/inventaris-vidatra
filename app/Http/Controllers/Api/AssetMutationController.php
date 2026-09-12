<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\MutationLogIndexRequest;
use App\Http\Resources\MutationLogCollection;
use App\Http\Resources\MutationLogResource;
use App\Models\Asset;
use App\Models\MutationLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-only mutation history for one asset (Tahap 5.5; trashed-asset access Tahap 5.8.9).
 *
 *   GET /api/assets/{asset}/mutations   (can:viewer)
 *
 *  - Scoped to the bound asset via `asset_id` — never returns another asset's logs.
 *  - The route resolves a soft-deleted asset (`->withTrashed()`) — history stays
 *    readable after an asset is trashed, for EVERY active role (viewer included),
 *    unlike `AssetController::show()`'s deliberate viewer restriction. Purely a read
 *    path: it grants no lifecycle/mutation permission.
 *  - Append-only: there is deliberately no store / update / destroy here. Mutation rows
 *    are written only by `App\Services\Asset\AssetMutationRecorder` (Tahap 5.4).
 *  - N+1-free: the performer is eager-loaded; room *labels* are snapshot columns and
 *    need no `rooms` query.
 */
class AssetMutationController extends ApiController
{
    /** Historical text columns the `?q=` search covers (bound `LIKE`, wildcards escaped). */
    private const SEARCHABLE = [
        'from_room_label',
        'to_room_label',
        'from_location_code',
        'to_location_code',
        'notes',
    ];

    public function index(MutationLogIndexRequest $request, Asset $asset): MutationLogCollection
    {
        $filters = $request->validated();

        $query = $asset->mutationLogs()
            ->with('createdBy')
            ->when(isset($filters['mutation_type']), fn (Builder $q) => $q->where('type', $filters['mutation_type']))
            ->when(isset($filters['event_type']), fn (Builder $q) => $q->where('event_type', $filters['event_type']))
            ->when(isset($filters['performed_by']), fn (Builder $q) => $q->where('performed_by', $filters['performed_by']))
            ->when(isset($filters['date_from']), fn (Builder $q) => $q->where('mutation_date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn (Builder $q) => $q->where('mutation_date', '<=', $filters['date_to']))
            ->when(isset($filters['q']) && $filters['q'] !== '', fn (Builder $q) => $this->applySearch($q, $filters['q']));

        $sort = $request->sortColumn();
        $query->orderBy($sort, $request->sortDirection());
        if ($sort !== 'id') {
            $query->orderBy('id', 'desc'); // deterministic tie-breaker
        }

        $logs = $query->paginate($request->perPage())->withQueryString();

        $this->preloadAlreadyReverted($logs->getCollection());

        return new MutationLogCollection($logs);
    }

    /**
     * Bulk-preloads `already_reverted` (Tahap 6.5) as a transient attribute on
     * each row in ONE query, instead of {@see MutationLog::alreadyReverted()}
     * being called per row (which would N+1 the page). See
     * {@see MutationLogResource} for how it's consumed.
     *
     * @param  Collection<int, MutationLog>  $logs
     */
    private function preloadAlreadyReverted($logs): void
    {
        if ($logs->isEmpty()) {
            return;
        }

        $revertedIds = MutationLog::query()
            ->whereIn('reverted_mutation_id', $logs->pluck('id'))
            ->pluck('reverted_mutation_id')
            ->all();

        $logs->each(fn (MutationLog $log) => $log->setAttribute(
            'already_reverted',
            in_array($log->id, $revertedIds, true),
        ));
    }

    private function applySearch(Builder $query, string $term): void
    {
        $like = '%'.$this->escapeLike($term).'%';

        $query->where(function (Builder $q) use ($like): void {
            foreach (self::SEARCHABLE as $column) {
                $q->orWhere($column, 'like', $like);
            }
        });
    }
}
