<?php

namespace Tests\Feature\Api;

use App\Enums\AssetCondition;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Location;
use App\Models\MutationLog;
use App\Models\Room;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * Tahap 5.6 — `GET /api/dashboard` (read-only aggregation).
 */
class DashboardApiTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    private const URL = '/api/dashboard';

    /**
     * A deterministic fixture that is NOT trivially satisfied by an empty database:
     *
     *  locations : LA (7 assets), LB (0), LZ (inactive -> hidden)
     *  categories: KA (7 assets), KB (0), KZ (inactive -> hidden)
     *  rooms     : "Ruang Satu"@LA (5 assets), "Ruang Kosong"@LA (0 -> hidden)
     *  assets in LA/KA/001:
     *    3x baik in Ruang Satu
     *    1x kurang_baik in Ruang Satu
     *    1x baik + written_off in Ruang Satu
     *    1x rusak_berat, no room
     *    1x NULL condition, no room
     *    1x baik in Ruang Satu, SOFT-DELETED  (must not count anywhere)
     */
    private function seedDashboard(): void
    {
        $la = Location::factory()->create(['code' => 'LA', 'name' => 'Lokasi Alpha']);
        $lb = Location::factory()->create(['code' => 'LB', 'name' => 'Lokasi Beta']);
        Location::factory()->inactive()->create(['code' => 'LZ', 'name' => 'Lokasi Nonaktif']);

        $ka = Category::factory()->create(['code' => 'KA', 'name' => 'Kategori Alpha']);
        Category::factory()->create(['code' => 'KB', 'name' => 'Kategori Beta']);
        Category::factory()->inactive()->create(['code' => 'KZ', 'name' => 'Kategori Nonaktif']);

        $sub = Subcategory::factory()->forCategory($ka)->create(['code' => '001', 'name' => 'MEJA']);

        $ruangSatu = Room::factory()->forLocation($la)->create(['name' => 'Ruang Satu']);
        Room::factory()->forLocation($la)->create(['name' => 'Ruang Kosong']);

        Asset::factory()->count(3)->forSubcategory($sub)->inRoom($ruangSatu)->create(['condition' => AssetCondition::Baik]);
        Asset::factory()->forSubcategory($sub)->inRoom($ruangSatu)->create(['condition' => AssetCondition::KurangBaik]);
        Asset::factory()->forSubcategory($sub)->inRoom($ruangSatu)->writtenOff('2024-01-01')->create(['condition' => AssetCondition::Baik]);
        Asset::factory()->forSubcategory($sub)->create(['location_code' => 'LA', 'room_id' => null, 'condition' => AssetCondition::RusakBerat]);
        Asset::factory()->forSubcategory($sub)->unknownCondition()->create(['location_code' => 'LA', 'room_id' => null]);

        $deleted = Asset::factory()->forSubcategory($sub)->inRoom($ruangSatu)->create(['condition' => AssetCondition::Baik]);
        $deleted->delete();
    }

    /* ------------------------------------------------------------------ authorization */

    public function test_guest_gets_401(): void
    {
        $this->getJson(self::URL)->assertStatus(401)->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_inactive_user_gets_403(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Viewer, 'is_active' => false]));
        $this->getJson(self::URL)->assertStatus(403);
    }

    public function test_every_active_role_gets_200(): void
    {
        foreach ([UserRole::Viewer, UserRole::Operator, UserRole::Admin] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));
            $this->getJson(self::URL)
                ->assertOk()
                ->assertJsonStructure(['data' => ['summary', 'by_location', 'by_category', 'by_room', 'recent_mutations']]);
        }
    }

    public function test_wrong_methods_are_405(): void
    {
        Sanctum::actingAs($this->viewer());

        $this->postJson(self::URL)->assertStatus(405);
        $this->putJson(self::URL)->assertStatus(405);
        $this->patchJson(self::URL)->assertStatus(405);
        $this->deleteJson(self::URL)->assertStatus(405);
    }

    /* ------------------------------------------------------------------ envelope */

    public function test_uses_the_standard_data_envelope(): void
    {
        Sanctum::actingAs($this->viewer());

        $body = $this->getJson(self::URL)->assertOk()->json();

        $this->assertSame(['data'], array_keys($body));
        $this->assertSame(
            ['summary', 'by_location', 'by_category', 'by_room', 'recent_mutations'],
            array_keys($body['data']),
        );
        foreach (['success', 'message', 'status', 'errors'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $body);
        }
    }

    /* ------------------------------------------------------------------ summary */

    public function test_summary_counts_are_computed_from_the_database(): void
    {
        $this->seedDashboard();
        Sanctum::actingAs($this->viewer());

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.summary.total_assets', 7)
            ->assertJsonPath('data.summary.written_off', 1)
            ->assertJsonPath('data.summary.by_condition.baik', 4)
            ->assertJsonPath('data.summary.by_condition.kurang_baik', 1)
            ->assertJsonPath('data.summary.by_condition.rusak_berat', 1)
            ->assertJsonPath('data.summary.by_condition.unknown', 1);
    }

    public function test_written_off_assets_stay_in_the_total(): void
    {
        Sanctum::actingAs($this->viewer());
        [$loc, , $sub] = $this->scope('WA', 'WK', '001');
        Asset::factory()->count(2)->forSubcategory($sub)->create(['location_code' => 'WA']);
        Asset::factory()->forSubcategory($sub)->writtenOff('2024-06-01')->create(['location_code' => 'WA']);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.summary.total_assets', 3)
            ->assertJsonPath('data.summary.written_off', 1);
    }

    public function test_soft_deleted_assets_are_excluded_from_every_count(): void
    {
        $this->seedDashboard();
        // add another soft-deleted asset to make the point louder
        [$loc, , $sub] = $this->scope('LA', 'KA', '001');
        $gone = Asset::factory()->forSubcategory($sub)->create(['location_code' => 'LA', 'condition' => AssetCondition::Baik]);
        $gone->delete();

        Sanctum::actingAs($this->viewer());
        $data = $this->getJson(self::URL)->assertOk()->json('data');

        $this->assertSame(7, $data['summary']['total_assets']);
        $this->assertSame(4, $data['summary']['by_condition']['baik']);
        $this->assertSame(7, collect($data['by_location'])->firstWhere('code', 'LA')['asset_count']);
        $this->assertSame(7, collect($data['by_category'])->firstWhere('code', 'KA')['asset_count']);
    }

    /* ------------------------------------------------------------------ by_location */

    public function test_by_location_lists_every_active_location_including_empty_ones(): void
    {
        $this->seedDashboard();
        Sanctum::actingAs($this->viewer());

        $rows = $this->getJson(self::URL)->assertOk()->json('data.by_location');

        $this->assertSame(['LA', 'LB'], array_column($rows, 'code'), 'inactive LZ hidden, ordered by code');
        $this->assertSame(7, collect($rows)->firstWhere('code', 'LA')['asset_count']);
        $this->assertSame(0, collect($rows)->firstWhere('code', 'LB')['asset_count']);
        $this->assertSame('Lokasi Alpha', collect($rows)->firstWhere('code', 'LA')['name']);
    }

    /* ------------------------------------------------------------------ by_category */

    public function test_by_category_lists_every_active_category_including_empty_ones(): void
    {
        $this->seedDashboard();
        Sanctum::actingAs($this->viewer());

        $rows = $this->getJson(self::URL)->assertOk()->json('data.by_category');

        $this->assertSame(['KA', 'KB'], array_column($rows, 'code'));
        $this->assertSame(7, collect($rows)->firstWhere('code', 'KA')['asset_count']);
        $this->assertSame(0, collect($rows)->firstWhere('code', 'KB')['asset_count']);
    }

    /* ------------------------------------------------------------------ by_room */

    public function test_by_room_only_lists_rooms_that_hold_active_assets(): void
    {
        $this->seedDashboard();
        Sanctum::actingAs($this->viewer());

        $rows = $this->getJson(self::URL)->assertOk()->json('data.by_room');

        $this->assertSame(['Ruang Satu'], array_column($rows, 'name'), 'empty room hidden');
        $this->assertSame(5, $rows[0]['asset_count']);
        $this->assertSame('LA', $rows[0]['location_code']);
        $this->assertSame('Lokasi Alpha', $rows[0]['location_name']);
    }

    public function test_by_room_keeps_same_named_rooms_in_different_locations_separate(): void
    {
        $la = Location::factory()->create(['code' => 'MA', 'name' => 'Lokasi M-A']);
        $lb = Location::factory()->create(['code' => 'MB', 'name' => 'Lokasi M-B']);
        $cat = Category::factory()->create(['code' => 'MK', 'name' => 'Kategori M']);
        $sub = Subcategory::factory()->forCategory($cat)->create(['code' => '001']);
        $roomA = Room::factory()->forLocation($la)->create(['name' => 'Gudang']);
        $roomB = Room::factory()->forLocation($lb)->create(['name' => 'Gudang']);
        Asset::factory()->forSubcategory($sub)->inRoom($roomA)->create();
        Asset::factory()->count(2)->forSubcategory($sub)->inRoom($roomB)->create();

        Sanctum::actingAs($this->viewer());
        $rows = collect($this->getJson(self::URL)->assertOk()->json('data.by_room'));

        $gudangA = $rows->firstWhere('id', $roomA->id);
        $gudangB = $rows->firstWhere('id', $roomB->id);

        $this->assertSame(['Gudang', 'MA', 1], [$gudangA['name'], $gudangA['location_code'], $gudangA['asset_count']]);
        $this->assertSame(['Gudang', 'MB', 2], [$gudangB['name'], $gudangB['location_code'], $gudangB['asset_count']]);
    }

    public function test_by_room_is_deterministically_ordered(): void
    {
        Sanctum::actingAs($this->viewer());
        $la = Location::factory()->create(['code' => 'OA']);
        $lb = Location::factory()->create(['code' => 'OB']);
        $cat = Category::factory()->create(['code' => 'OK']);
        $sub = Subcategory::factory()->forCategory($cat)->create(['code' => '001']);
        $rB = Room::factory()->forLocation($lb)->create(['name' => 'Zeta']);
        $rA2 = Room::factory()->forLocation($la)->create(['name' => 'Beta']);
        $rA1 = Room::factory()->forLocation($la)->create(['name' => 'Alfa']);
        foreach ([$rB, $rA2, $rA1] as $room) {
            Asset::factory()->forSubcategory($sub)->inRoom($room)->create();
        }

        $rows = $this->getJson(self::URL)->assertOk()->json('data.by_room');
        // location code asc, then room name asc
        $this->assertSame(
            [[$rA1->id], [$rA2->id], [$rB->id]],
            array_map(fn ($r) => [$r['id']], $rows),
        );
    }

    /* ------------------------------------------------------------------ recent_mutations */

    public function test_recent_mutations_is_empty_array_when_there_is_no_history(): void
    {
        $this->seedDashboard();
        Sanctum::actingAs($this->viewer());

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.recent_mutations', []);
    }

    public function test_recent_mutations_is_capped_at_10_and_ordered_newest_first(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->existingAsset('001');

        foreach (range(1, 12) as $i) {
            MutationLog::factory()->create([
                'asset_id' => $asset->id,
                'type' => 'pindah_ruangan',
                'mutation_date' => sprintf('2026-%02d-01', $i),
                'from_room_label' => "From {$i}",
                'to_room_label' => "To {$i}",
            ]);
        }

        $rows = $this->getJson(self::URL)->assertOk()->json('data.recent_mutations');

        $this->assertCount(10, $rows);
        $this->assertSame('2026-12-01', $rows[0]['mutation_date']);
        $this->assertSame('2026-03-01', $rows[9]['mutation_date']);
        $dates = array_column($rows, 'mutation_date');
        $this->assertSame($dates, collect($dates)->sortDesc()->values()->all());
    }

    public function test_recent_mutations_uses_the_mutation_log_resource_shape(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->existingAsset('001');
        $actor = $this->operator();
        MutationLog::factory()->create([
            'asset_id' => $asset->id,
            'type' => 'pindah_ruangan',
            'mutation_date' => '2026-05-01',
            'from_room_label' => 'Ruang A',
            'to_room_label' => 'Ruang B',
            'condition_before' => AssetCondition::Baik,
            'condition_after' => AssetCondition::KurangBaik,
            'performed_by' => $actor->id,
        ]);

        $row = $this->getJson(self::URL)->assertOk()->json('data.recent_mutations.0');

        $this->assertSame(
            [
                'id', 'mutation_type', 'mutation_date', 'from', 'to', 'condition_before', 'condition_after',
                'mutation_note', 'performed_by', 'created_at',
                'event_type', 'batch_operation_id', 'before_snapshot', 'after_snapshot',
                // Tahap 6.5 — revert/undo, additive fields (see MutationLogResource).
                'reverted_mutation_id', 'is_revertable', 'already_reverted', 'can_revert',
            ],
            array_keys($row),
        );
        $this->assertSame('Ruang A', $row['from']['room_label']);
        $this->assertSame(['id', 'name'], array_keys($row['performed_by']));
        foreach (['email', 'role', 'is_active', 'password'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row['performed_by']);
        }
    }

    public function test_recent_mutations_room_labels_are_historical_snapshots(): void
    {
        [$loc] = $this->scope();
        $room = Room::factory()->forLocation($loc)->create(['name' => 'Ruangan Personalia & SARPRAS']);
        $asset = $this->existingAsset('001');
        MutationLog::factory()->create([
            'asset_id' => $asset->id,
            'type' => 'pindah_ruangan',
            'mutation_date' => '2026-05-01',
            'to_room_id' => $room->id,
            'to_location_code' => 'ZL',
            'to_room_label' => 'Ruangan Personalia & SARPRAS',
        ]);

        // master room renamed afterwards
        $room->update(['name' => 'Ruangan Personalia']);

        Sanctum::actingAs($this->viewer());
        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.recent_mutations.0.to.room_label', 'Ruangan Personalia & SARPRAS');
    }

    /* ------------------------------------------------------------------ performance / side effects */

    public function test_query_count_is_bounded_regardless_of_data_volume(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->existingAsset('001');

        $seedMutations = function (int $n) use ($asset): void {
            for ($i = 0; $i < $n; $i++) {
                MutationLog::factory()->create([
                    'asset_id' => $asset->id,
                    'type' => 'pindah_ruangan',
                    'performed_by' => User::factory()->create()->id,
                ]);
            }
        };

        $seedMutations(3);
        $small = $this->countQueries(fn () => $this->getJson(self::URL)->assertOk());

        $seedMutations(20);
        Location::factory()->count(5)->create();
        Category::factory()->count(5)->create();
        $large = $this->countQueries(fn () => $this->getJson(self::URL)->assertOk());

        $this->assertLessThanOrEqual($small, $large, "Dashboard query count grew {$small} -> {$large}");
        $this->assertLessThanOrEqual(8, $large, 'dashboard should be a small fixed number of aggregate queries');
    }

    public function test_endpoint_has_no_write_side_effects(): void
    {
        Sanctum::actingAs($this->viewer());

        $this->seedDashboard();
        [$loc] = $this->scope();
        $room = Room::factory()->forLocation($loc)->create(['name' => 'Ruang Tak Berubah']);
        $asset = $this->existingAsset('001');
        MutationLog::factory()->create(['asset_id' => $asset->id, 'type' => 'pindah_ruangan']);

        $before = [
            'assets' => DB::table('assets')->count(),
            'assets_trashed' => Asset::onlyTrashed()->count(),
            'mutation_logs' => DB::table('mutation_logs')->count(),
            'rooms' => DB::table('rooms')->count(),
            'users' => DB::table('users')->count(),
            'room_name' => Room::query()->whereKey($room->id)->value('name'),
            'asset_updated_at' => $asset->fresh()->updated_at,
        ];

        $this->getJson(self::URL)->assertOk();
        $this->getJson(self::URL)->assertOk();

        $this->assertSame($before['assets'], DB::table('assets')->count());
        $this->assertSame($before['assets_trashed'], Asset::onlyTrashed()->count());
        $this->assertSame($before['mutation_logs'], DB::table('mutation_logs')->count());
        $this->assertSame($before['rooms'], DB::table('rooms')->count());
        $this->assertSame($before['users'], DB::table('users')->count());
        $this->assertSame($before['room_name'], Room::query()->whereKey($room->id)->value('name'));
        $this->assertEquals($before['asset_updated_at'], $asset->fresh()->updated_at);
    }

    private function countQueries(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $callback();

        return $count;
    }
}
