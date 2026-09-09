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
        foreach (DB::table('rooms')->where('location_code', $locationCode)->get(['id', 'name']) as $room) {
            $names[ValueNormalizer::roomMatchKey($room->name)] = (int) $room->id;
        }

        $aliases = [];
        foreach (DB::table('room_aliases')->where('location_code', $locationCode)->get(['room_id', 'match_key']) as $alias) {
            $aliases[$alias->match_key] = (int) $alias->room_id;
        }

        return $this->cache[$locationCode] = ['names' => $names, 'aliases' => $aliases];
    }
}
