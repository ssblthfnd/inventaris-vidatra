<?php

namespace Tests\Feature\Api;

use App\Enums\AssetCondition;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\MutationLog;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * Tahap 5.5 — `GET /api/assets/{asset}/mutations` (read-only mutation history).
 */
class MutationHistoryApiTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    private function asset(array $overrides = []): Asset
    {
        return $this->existingAsset('001', overrides: $overrides);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function log(Asset $asset, array $attrs = []): MutationLog
    {
        return MutationLog::factory()->create(array_merge([
            'asset_id' => $asset->id,
            'type' => 'pindah_ruangan',
            'mutation_date' => '2026-01-01',
        ], $attrs));
    }

    private function url(Asset $asset, string $query = ''): string
    {
        return "/api/assets/{$asset->id}/mutations".($query === '' ? '' : "?{$query}");
    }

    /* ------------------------------------------------------------------ authorization */

    public function test_unauthenticated_gets_401(): void
    {
        $asset = $this->asset();

        $this->getJson($this->url($asset))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_inactive_user_gets_403(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Viewer, 'is_active' => false]));

        $this->getJson($this->url($this->asset()))->assertStatus(403);
    }

    public function test_every_active_role_gets_200(): void
    {
        $asset = $this->asset();
        $this->log($asset);

        foreach ([UserRole::Viewer, UserRole::Operator, UserRole::Admin] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));
            $this->getJson($this->url($asset))
                ->assertOk()
                ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
        }
    }

    /* ------------------------------------------------------------------ asset scoping */

    public function test_only_the_requested_assets_mutations_are_returned(): void
    {
        Sanctum::actingAs($this->viewer());

        $a = $this->existingAsset('001');
        $b = $this->existingAsset('002');
        $this->log($a);
        $this->log($a);
        $this->log($b);
        $this->log($b);
        $this->log($b);

        $this->getJson($this->url($a))->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(2, 'data');
        $this->getJson($this->url($b))->assertOk()->assertJsonPath('meta.total', 3)->assertJsonCount(3, 'data');
    }

    public function test_unknown_asset_is_404(): void
    {
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/assets/999999/mutations')->assertStatus(404);
    }

    public function test_soft_deleted_asset_history_is_readable_not_404(): void
    {
        // Tahap 5.8.9: unlike GET /api/assets/{asset} (which still 404s a trashed
        // asset for a viewer), history stays readable for every active role — it's
        // meaningful precisely because the asset was deleted.
        Sanctum::actingAs($this->viewer());
        $asset = $this->asset();
        $this->log($asset);
        $asset->delete();

        $this->getJson($this->url($asset))->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_unknown_asset_id_on_a_trashed_lookup_is_still_404(): void
    {
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/assets/999999/mutations')->assertStatus(404);
    }

    public function test_asset_with_no_history_returns_200_and_an_empty_page(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->asset();

        $this->getJson($this->url($asset))
            ->assertOk()
            ->assertExactJson([
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 0],
            ]);
    }

    /* ------------------------------------------------------------------ pagination */

    public function test_default_per_page_is_20(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->asset();
        MutationLog::factory()->count(25)->create(['asset_id' => $asset->id, 'type' => 'pindah_ruangan']);

        $this->getJson($this->url($asset))
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonMissingPath('meta.links')
            ->assertJsonMissingPath('links');
    }

    public function test_custom_per_page_and_page(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->asset();
        MutationLog::factory()->count(7)->create(['asset_id' => $asset->id, 'type' => 'pindah_ruangan']);

        $this->getJson($this->url($asset, 'per_page=3&page=2'))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 3);
    }

    public function test_per_page_100_is_accepted_and_101_is_rejected(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->asset();

        $this->getJson($this->url($asset, 'per_page=100'))->assertOk()->assertJsonPath('meta.per_page', 100);
        $this->getJson($this->url($asset, 'per_page=101'))->assertStatus(422)->assertJsonValidationErrors('per_page');
        $this->getJson($this->url($asset, 'per_page=0'))->assertStatus(422)->assertJsonValidationErrors('per_page');
    }

    public function test_invalid_page_is_rejected(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->asset();

        $this->getJson($this->url($asset, 'page=0'))->assertStatus(422)->assertJsonValidationErrors('page');
        $this->getJson($this->url($asset, 'page=abc'))->assertStatus(422)->assertJsonValidationErrors('page');
    }

    /* ------------------------------------------------------------------ sorting */

    public function test_default_sort_is_mutation_date_desc_then_id_desc(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->asset();
        $old = $this->log($asset, ['mutation_date' => '2025-01-01']);
        $newA = $this->log($asset, ['mutation_date' => '2026-06-01']);
        $newB = $this->log($asset, ['mutation_date' => '2026-06-01']);

        $ids = array_column($this->getJson($this->url($asset))->json('data'), 'id');

        $this->assertSame([$newB->id, $newA->id, $old->id], $ids);
    }

    public function test_ascending_sort_works(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->asset();
        $this->log($asset, ['mutation_date' => '2025-01-01']);
        $this->log($asset, ['mutation_date' => '2026-01-01']);

        $dates = array_column(
            $this->getJson($this->url($asset, 'sort=mutation_date&direction=asc'))->json('data'),
            'mutation_date',
        );
        $this->assertSame(['2025-01-01', '2026-01-01'], $dates);
    }

    public function test_created_at_and_id_sorts_are_allowed(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->asset();
        $a = $this->log($asset);
        $b = $this->log($asset);

        $this->getJson($this->url($asset, 'sort=created_at&direction=asc'))->assertOk();

        $ids = array_column($this->getJson($this->url($asset, 'sort=id&direction=asc'))->json('data'), 'id');
        $this->assertSame([$a->id, $b->id], $ids);
    }

    public function test_invalid_sort_or_direction_is_rejected(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->asset();

        foreach (['password', '1 desc', 'id desc, (select 1)', 'from_room_label'] as $bad) {
            $this->getJson($this->url($asset, 'sort='.urlencode($bad)))
                ->assertStatus(422)->assertJsonValidationErrors('sort');
        }

        $this->getJson($this->url($asset, 'direction=sideways'))
            ->assertStatus(422)->assertJsonValidationErrors('direction');
    }

    /* ------------------------------------------------------------------ filtering */

    public function test_filter_by_mutation_type(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->asset();
        $this->log($asset, ['type' => 'pindah_ruangan']);
        $this->log($asset, ['type' => 'pindah_ruangan']);
        $this->log($asset, ['type' => 'perbaikan']); // legacy row of another type

        $this->getJson($this->url($asset, 'mutation_type=pindah_ruangan'))
            ->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_unknown_mutation_type_is_rejected(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->asset();

        $this->getJson($this->url($asset, 'mutation_type=write_off'))
            ->assertStatus(422)->assertJsonValidationErrors('mutation_type');
    }

    public function test_filter_by_date_from_and_date_to(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->asset();
        $this->log($asset, ['mutation_date' => '2026-01-10']);
        $this->log($asset, ['mutation_date' => '2026-02-15']);
        $this->log($asset, ['mutation_date' => '2026-03-20']);

        $this->getJson($this->url($asset, 'date_from=2026-02-01'))->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson($this->url($asset, 'date_to=2026-02-01'))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($this->url($asset, 'date_from=2026-02-01&date_to=2026-02-28'))
            ->assertOk()->assertJsonPath('meta.total', 1);
        // boundaries are inclusive
        $this->getJson($this->url($asset, 'date_from=2026-02-15&date_to=2026-02-15'))
            ->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_invalid_date_format_is_rejected(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->asset();

        $this->getJson($this->url($asset, 'date_from=10-01-2026'))->assertStatus(422)->assertJsonValidationErrors('date_from');
        $this->getJson($this->url($asset, 'date_to=not-a-date'))->assertStatus(422)->assertJsonValidationErrors('date_to');
    }

    public function test_date_from_after_date_to_is_rejected(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->asset();

        $this->getJson($this->url($asset, 'date_from=2026-05-01&date_to=2026-01-01'))
            ->assertStatus(422)->assertJsonValidationErrors('date_to');
    }

    public function test_filter_by_performed_by(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->asset();
        $actor = $this->operator();
        $this->log($asset, ['performed_by' => $actor->id]);
        $this->log($asset, ['performed_by' => null]);

        $this->getJson($this->url($asset, "performed_by={$actor->id}"))
            ->assertOk()->assertJsonPath('meta.total', 1);
    }

    /* ------------------------------------------------------------------ search */

    public function test_q_searches_room_labels_and_note(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->asset();
        $this->log($asset, ['from_room_label' => 'Ruangan Personalia & SARPRAS', 'to_room_label' => 'Ruangan Keuangan']);
        $this->log($asset, ['from_room_label' => 'Gudang', 'to_room_label' => 'Aula', 'notes' => 'pindah karena renovasi besar']);
        $this->log($asset, ['from_room_label' => 'Lab', 'to_room_label' => 'Kelas']);

        $this->getJson($this->url($asset, 'q=personalia'))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($this->url($asset, 'q=keuangan'))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($this->url($asset, 'q='.urlencode('renovasi besar')))->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_q_over_100_chars_is_rejected(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->asset();

        $this->getJson($this->url($asset, 'q='.str_repeat('a', 101)))
            ->assertStatus(422)->assertJsonValidationErrors('q');
    }

    public function test_q_wildcards_are_escaped(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->asset();
        $this->log($asset, ['to_room_label' => 'Ruang 100% Baru']);
        $this->log($asset, ['to_room_label' => 'Ruang A_B']);
        $this->log($asset, ['to_room_label' => 'Ruang Lain']);

        // "%" is a literal, not "match anything"
        $this->getJson($this->url($asset, 'q='.urlencode('100%')))
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.to.room_label', 'Ruang 100% Baru');

        // "_" is a literal, not "match one char"
        $this->getJson($this->url($asset, 'q='.urlencode('A_B')))
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.to.room_label', 'Ruang A_B');
    }

    /* ------------------------------------------------------------------ resource shape */

    public function test_resource_exposes_the_documented_shape(): void
    {
        Sanctum::actingAs($this->viewer());
        [$location] = $this->scope();
        $roomA = Room::factory()->forLocation($location)->create(['name' => 'Ruang A']);
        $roomB = Room::factory()->forLocation($location)->create(['name' => 'Ruang B']);
        $asset = $this->asset();
        $actor = $this->operator();

        $this->log($asset, [
            'mutation_date' => '2026-09-08',
            'from_location_code' => 'ZL', 'to_location_code' => 'ZL',
            'from_room_id' => $roomA->id, 'to_room_id' => $roomB->id,
            'from_room_label' => 'Ruang A', 'to_room_label' => 'Ruang B',
            'condition_before' => AssetCondition::Baik, 'condition_after' => AssetCondition::KurangBaik,
            'notes' => 'catatan',
            'performed_by' => $actor->id,
        ]);

        $this->getJson($this->url($asset))
            ->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id', 'mutation_type', 'mutation_date',
                    'from' => ['location_code', 'room_id', 'room_label'],
                    'to' => ['location_code', 'room_id', 'room_label'],
                    'condition_before', 'condition_after', 'mutation_note',
                    'performed_by' => ['id', 'name'],
                    'created_at',
                ]],
            ])
            ->assertJsonPath('data.0.mutation_type', 'pindah_ruangan')
            ->assertJsonPath('data.0.from.room_label', 'Ruang A')
            ->assertJsonPath('data.0.to.room_label', 'Ruang B')
            ->assertJsonPath('data.0.condition_before', 'baik')
            ->assertJsonPath('data.0.condition_after', 'kurang_baik')
            ->assertJsonPath('data.0.mutation_note', 'catatan')
            ->assertJsonPath('data.0.performed_by.id', $actor->id);
    }

    public function test_resource_hides_internal_fields(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->asset();
        $actor = $this->operator();
        $this->log($asset, ['performed_by' => $actor->id]);

        $row = $this->getJson($this->url($asset))->json('data.0');

        foreach (['asset_id', 'type', 'notes', 'updated_at', 'deleted_at'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row);
        }
        foreach (['password', 'remember_token', 'email', 'role', 'is_active'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row['performed_by']);
        }
    }

    public function test_null_performer_and_null_rooms_are_handled(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->asset();
        $this->log($asset, [
            'performed_by' => null,
            'from_room_id' => null, 'from_room_label' => null, 'from_location_code' => null,
            'to_room_id' => null, 'to_room_label' => null, 'to_location_code' => null,
            'condition_before' => null, 'condition_after' => null,
        ]);

        $this->getJson($this->url($asset))
            ->assertOk()
            ->assertJsonPath('data.0.performed_by', null)
            ->assertJsonPath('data.0.from.room_id', null)
            ->assertJsonPath('data.0.from.room_label', null)
            ->assertJsonPath('data.0.condition_before', null);
    }

    /* ------------------------------------------------------------------ historical snapshots */

    public function test_room_label_snapshot_survives_a_later_room_rename(): void
    {
        [$location] = $this->scope();
        $roomA = Room::factory()->forLocation($location)->create(['name' => 'Ruangan Personalia & SARPRAS']);
        $roomB = Room::factory()->forLocation($location)->create(['name' => 'Ruangan Keuangan']);

        // real flow: create in room A, move to room B
        Sanctum::actingAs($this->operator());
        $created = $this->postJson('/api/assets', $this->validCreatePayload(['room_id' => $roomA->id]))
            ->assertStatus(201)->json('data');
        $this->patchJson("/api/assets/{$created['id']}", ['room_id' => $roomB->id])->assertOk();

        // the master room is renamed afterwards
        $roomA->update(['name' => 'Ruangan Personalia']);
        $roomB->update(['name' => 'Ruangan Bendahara']);

        Sanctum::actingAs($this->viewer());
        $this->getJson("/api/assets/{$created['id']}/mutations")
            ->assertOk()
            ->assertJsonPath('data.0.from.room_label', 'Ruangan Personalia & SARPRAS')
            ->assertJsonPath('data.0.to.room_label', 'Ruangan Keuangan');
    }

    public function test_condition_snapshot_is_the_events_own_value_not_the_current_asset(): void
    {
        Sanctum::actingAs($this->operator());
        [$location] = $this->scope();
        $roomA = Room::factory()->forLocation($location)->create();
        $roomB = Room::factory()->forLocation($location)->create();

        $created = $this->postJson('/api/assets', $this->validCreatePayload([
            'room_id' => $roomA->id,
            'condition' => 'baik',
        ]))->assertStatus(201)->json('data');

        $this->patchJson("/api/assets/{$created['id']}", ['room_id' => $roomB->id])->assertOk();
        // asset condition changes AFTER the move (Tahap 5.8.8: this now records its
        // own separate EDIT event — filter to the room-move event specifically to
        // isolate ITS condition snapshot from the later EDIT's).
        $this->patchJson("/api/assets/{$created['id']}", ['condition' => 'rusak_berat'])->assertOk();

        Sanctum::actingAs($this->viewer());
        $this->getJson("/api/assets/{$created['id']}/mutations?mutation_type=pindah_ruangan")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.condition_before', 'baik')
            ->assertJsonPath('data.0.condition_after', 'baik');

        $this->assertSame('rusak_berat', Asset::find($created['id'])->condition->value);
    }

    public function test_deactivated_performer_still_appears_in_history(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->asset();
        $actor = User::factory()->create(['role' => UserRole::Operator, 'is_active' => true, 'name' => 'Mantan Operator']);
        $this->log($asset, ['performed_by' => $actor->id]);

        $actor->update(['is_active' => false]);

        $this->getJson($this->url($asset))
            ->assertOk()
            ->assertJsonPath('data.0.performed_by.id', $actor->id)
            ->assertJsonPath('data.0.performed_by.name', 'Mantan Operator');
    }

    /* ------------------------------------------------------------------ N+1 / side effects */

    public function test_query_count_does_not_grow_with_the_number_of_rows(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->asset();

        $make = function (int $n) use ($asset): void {
            for ($i = 0; $i < $n; $i++) {
                MutationLog::factory()->create([
                    'asset_id' => $asset->id,
                    'type' => 'pindah_ruangan',
                    'performed_by' => User::factory()->create()->id,
                ]);
            }
        };

        $make(10);
        $small = $this->countQueries(fn () => $this->getJson($this->url($asset, 'per_page=100'))->assertOk()->assertJsonCount(10, 'data'));

        $make(40);
        $large = $this->countQueries(fn () => $this->getJson($this->url($asset, 'per_page=100'))->assertOk()->assertJsonCount(50, 'data'));

        $this->assertLessThanOrEqual($small + 1, $large, "Query count grew {$small} -> {$large} — likely N+1 on the performer.");
    }

    public function test_endpoint_has_no_write_side_effects(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->asset(['brand_model' => 'unchanged']);
        [$location] = $this->scope();
        $room = Room::factory()->forLocation($location)->create(['name' => 'Ruang Tetap']);
        $this->log($asset, ['to_room_id' => $room->id, 'to_location_code' => 'ZL', 'to_room_label' => 'Ruang Tetap']);

        $before = [
            'assets' => DB::table('assets')->count(),
            'mutation_logs' => DB::table('mutation_logs')->count(),
            'rooms' => Room::query()->where('id', $room->id)->value('name'),
            'asset_updated_at' => $asset->fresh()->updated_at,
        ];

        $this->getJson($this->url($asset))->assertOk();
        $this->getJson($this->url($asset, 'q=tetap&sort=id&per_page=5'))->assertOk();

        $this->assertSame($before['assets'], DB::table('assets')->count());
        $this->assertSame($before['mutation_logs'], DB::table('mutation_logs')->count());
        $this->assertSame($before['rooms'], Room::query()->where('id', $room->id)->value('name'));
        $this->assertEquals($before['asset_updated_at'], $asset->fresh()->updated_at);
    }

    public function test_no_write_routes_exist_for_mutations(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->asset();
        $log = $this->log($asset);

        $this->postJson($this->url($asset), [])->assertStatus(405);
        $this->putJson("/api/assets/{$asset->id}/mutations/{$log->id}", [])->assertStatus(404);
        $this->deleteJson("/api/assets/{$asset->id}/mutations/{$log->id}")->assertStatus(404);
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
