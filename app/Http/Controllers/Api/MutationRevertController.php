<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\AssetResource;
use App\Http\Resources\MutationLogResource;
use App\Models\Asset;
use App\Models\MutationLog;
use App\Services\Asset\MutationRevertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/mutations/{mutation}/revert` (Tahap 6.5), `can:operator` — a
 * viewer gets 403, matching every other inventory-write endpoint's gate
 * (unlike Tahap 6.4's user management, which is stricter at `can:admin`).
 *
 * All business logic — conflict detection, batch-group atomicity, locking,
 * audit logging — lives in {@see MutationRevertService}; this controller only
 * maps the HTTP verb/route to it and shapes the response. Read-only route
 * model binding resolves the mutation; there is deliberately no FormRequest
 * body to validate — a revert takes no client-supplied data, only the
 * mutation id in the URL and the acting user from the session.
 */
class MutationRevertController extends ApiController
{
    public function revert(Request $request, MutationLog $mutation, MutationRevertService $service): JsonResponse
    {
        $result = $service->revert($mutation, $request->user());

        $assets = collect($result['assets'])
            ->each(fn (Asset $asset) => $asset->load(['location', 'category', 'room']));
        Asset::loadSubcategoriesFor($assets);

        $count = count($result['reverted_mutation_ids']);

        return response()->json([
            'message' => $count > 1
                ? "{$count} mutasi berhasil di-revert."
                : 'Mutasi berhasil di-revert.',
            'reverted_mutation_ids' => $result['reverted_mutation_ids'],
            'new_mutations' => MutationLogResource::collection($result['new_logs']),
            'assets' => AssetResource::collection($assets),
        ]);
    }
}
