<?php

namespace App\Import\Matching;

use App\Import\Parsing\ValueNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Location-aware room resolution (Tahap 4 §12–§16, schema_design.md §6.2):
 *
 *   1. exact canonical room:  rooms WHERE location_code = :loc AND norm(name) = :key
 *   2. alias:                  room_aliases WHERE location_code = :loc AND match_key = :key
 *   3. none:                   room_id = NULL, method = 'none'  (warning: room_unmapped)
 *
 * NEVER queries rooms/aliases without a location scope. NEVER creates a room, an alias,
 * or a "Lainnya" bucket. NO fuzzy matching.
 *
 * Tahap 6.8.2 (Part B): both `names` and `aliases` below are scoped to ACTIVE
 * rooms only — this governs NEW import matching alone. An inactive room (or
 * an alias pointing at one) simply stops resolving here, which `RowValidator`
 * already treats as `room_unmapped` (a warning, never silently reassigned) —
 * no new import status was invented. This has ZERO effect on existing/
 * historical assets: `Asset::room()` and every read endpoint resolve rooms
 * by `room_id` directly, never through this matcher, so a historical asset
 * whose room later goes inactive keeps displaying correctly (see
 * `FiltersAssets`, `AssetResource`). Deactivating a room never touches this
 * matcher's cache in-process; a fresh cache is built per artisan/HTTP
 * lifecycle (`forgetCache()` exists for the rare case a single long-lived
 * process needs to re-read after a change).
 */
final class RoomMatcher
{
    /** @var array<string, array{names: array<string,int>, aliases: array<string,int>}> */
    private array $cache = [];

    public function match(?string $locationCode, ?string $rawRoom): RoomMatchResult
    {
        $rawRoom = $rawRoom !== null ? trim($rawRoom) : null;
        $key = ValueNormalizer::roomMatchKey($rawRoom);

        if ($locationCode === null || $rawRoom === null || $rawRoom === '') {
            return new RoomMatchResult(null, RoomMatchResult::METHOD_NONE, $key, $rawRoom);
        }

        $data = $this->load($locationCode);

        if (isset($data['names'][$key])) {
            return new RoomMatchResult($data['names'][$key], RoomMatchResult::METHOD_EXACT_NAME, $key, $rawRoom);
        }
        if (isset($data['aliases'][$key])) {
            return new RoomMatchResult($data['aliases'][$key], RoomMatchResult::METHOD_ALIAS, $key, $rawRoom);
        }

        return new RoomMatchResult(null, RoomMatchResult::METHOD_NONE, $key, $rawRoom);
    }

    public function forgetCache(): void
    {
        $this->cache = [];
    }

    /**
     * @return array{names: array<string,int>, aliases: array<string,int>}
     */
    private function load(string $locationCode): array
    {
        if (isset($this->cache[$locationCode])) {
            return $this->cache[$locationCode];
        }

        $names = [];
        foreach (
            DB::table('rooms')
                ->where('location_code', $locationCode)
                ->where('is_active', true)
                ->get(['id', 'name']) as $room
        ) {
            $names[ValueNormalizer::roomMatchKey($room->name)] = (int) $room->id;
        }

        $aliases = [];
        foreach (
            DB::table('room_aliases')
                ->join('rooms', 'room_aliases.room_id', '=', 'rooms.id')
                ->where('room_aliases.location_code', $locationCode)
                ->where('rooms.is_active', true)
                ->get(['room_aliases.room_id', 'room_aliases.match_key']) as $alias
        ) {
            $aliases[$alias->match_key] = (int) $alias->room_id;
        }

        return $this->cache[$locationCode] = ['names' => $names, 'aliases' => $aliases];
    }
}
