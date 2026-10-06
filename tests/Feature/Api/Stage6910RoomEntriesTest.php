<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Location;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tahap 6.9 R10 — `POST /api/rooms/entries`: 1..100 rooms in one atomic request,
 * with the same rules, duplicate semantics and location scope as `POST /api/rooms`.
 */
class Stage6910RoomEntriesTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/rooms/entries';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['02', '03', '04'] as $code) {
            Location::query()->firstOrCreate(['code' => $code], ['name' => "Lokasi {$code}", 'is_active' => true]);
        }
    }

    private function user(UserRole $role, ?string $locationCode = null): User
    {
        return User::factory()->create(['role' => $role, 'location_code' => $locationCode, 'is_active' => true]);
    }

    private function row(string $location, string $name, array $overrides = []): array
    {
        return array_merge(['location_code' => $location, 'name' => $name, 'pic' => null, 'notes' => null], $overrides);
    }

    public function test_one_room_is_created_active_with_its_fields(): void
    {
        Sanctum::actingAs($this->user(UserRole::Admin));

        $this->postJson(self::URL, ['items' => [$this->row('02', '  Ruang Guru ', ['pic' => ' Bu Ani ', 'notes' => '  '])]])
            ->assertStatus(201)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.name', 'Ruang Guru')
            ->assertJsonPath('data.0.location.code', '02')
            ->assertJsonPath('data.0.pic', 'Bu Ani')
            ->assertJsonPath('data.0.notes', null)
            ->assertJsonPath('data.0.is_active', true);
    }

    public function test_many_rooms_across_locations_for_a_global_actor_keep_request_order(): void
    {
        Sanctum::actingAs($this->user(UserRole::Admin));

        $response = $this->postJson(self::URL, ['items' => [
            $this->row('03', 'Lab IPA'),
            $this->row('02', 'Lab IPA'),
            $this->row('02', 'Perpustakaan'),
        ]])->assertStatus(201)->assertJsonPath('count', 3);

        $this->assertSame(['03', '02', '02'], array_column(array_column($response->json('data'), 'location'), 'code'));
        $this->assertSame(['Lab IPA', 'Lab IPA', 'Perpustakaan'], array_column($response->json('data'), 'name'));
        $this->assertDatabaseCount('rooms', 3);
    }

    public function test_the_same_name_twice_in_one_location_flags_both_rows(): void
    {
        Sanctum::actingAs($this->user(UserRole::Admin));

        $this->postJson(self::URL, ['items' => [
            $this->row('02', 'Gudang'),
            $this->row('02', 'Aula'),
            $this->row('02', 'GUDANG'),
        ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.name', 'items.2.name'])
            ->assertJsonMissingValidationErrors(['items.1.name']);

        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_a_name_held_by_an_existing_room_active_or_not_is_rejected(): void
    {
        Sanctum::actingAs($this->user(UserRole::Admin));
        Room::factory()->create(['location_code' => '02', 'name' => 'Gudang', 'is_active' => true]);
        Room::factory()->create(['location_code' => '02', 'name' => 'Arsip', 'is_active' => false]);

        $response = $this->postJson(self::URL, ['items' => [
            $this->row('02', 'Gudang'),
            $this->row('02', 'arsip'),
            $this->row('03', 'Gudang'),
        ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.name', 'items.1.name'])
            ->assertJsonMissingValidationErrors(['items.2.name']);

        $this->assertSame(
            ['Ruangan dengan nama ini sudah ada di lokasi tersebut.'],
            $response->json('errors')['items.0.name'],
        );

        $this->assertDatabaseCount('rooms', 2);
    }

    public function test_an_invalid_row_rejects_the_whole_request(): void
    {
        Sanctum::actingAs($this->user(UserRole::Admin));
        Location::query()->create(['code' => '09', 'name' => 'Lokasi Tutup', 'is_active' => false]);

        $this->postJson(self::URL, ['items' => [
            $this->row('02', 'Ruang A'),
            $this->row('02', ''),
            $this->row('09', 'Ruang C'),
            $this->row('02', str_repeat('x', 101)),
            $this->row('02', 'Ruang E', ['is_active' => false]),
        ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.1.name', 'items.2.location_code', 'items.3.name', 'items.4.is_active'])
            ->assertJsonMissingValidationErrors(['items.0.name']);

        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_items_shape_and_size_limits(): void
    {
        Sanctum::actingAs($this->user(UserRole::Admin));

        $this->postJson(self::URL, ['items' => []])->assertStatus(422)->assertJsonValidationErrors(['items']);
        $this->postJson(self::URL, ['items' => ['x']])->assertStatus(422)->assertJsonValidationErrors(['items.0']);

        $rows = array_map(fn (int $i): array => $this->row('02', "Ruang {$i}"), range(1, 101));
        $this->postJson(self::URL, ['items' => $rows])->assertStatus(422)->assertJsonValidationErrors(['items']);

        $this->postJson(self::URL, ['items' => array_slice($rows, 0, 100)])->assertStatus(201)->assertJsonPath('count', 100);
    }

    public function test_unit_admin_creates_rooms_in_its_own_location(): void
    {
        Sanctum::actingAs($this->user(UserRole::UnitAdmin, '02'));

        $this->postJson(self::URL, ['items' => [$this->row('02', 'Ruang A'), $this->row('02', 'Ruang B')]])
            ->assertStatus(201)->assertJsonPath('count', 2);
    }

    public function test_unit_admin_is_denied_a_foreign_location(): void
    {
        Sanctum::actingAs($this->user(UserRole::UnitAdmin, '02'));

        $this->postJson(self::URL, ['items' => [$this->row('03', 'Ruang A')]])->assertStatus(403);

        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_unit_admin_mixed_location_request_is_denied_as_a_whole(): void
    {
        Sanctum::actingAs($this->user(UserRole::UnitAdmin, '02'));

        $this->postJson(self::URL, ['items' => [
            $this->row('02', 'Ruang A'),
            $this->row('03', 'Ruang B'),
        ]])->assertStatus(403);

        $this->assertDatabaseCount('rooms', 0);
        $this->assertSame(0, Room::query()->where('name', 'Ruang A')->count());
    }

    public function test_viewer_and_operator_cannot_create_rooms(): void
    {
        $this->postJson(self::URL, ['items' => [$this->row('02', 'Ruang A')]])->assertStatus(401);

        Sanctum::actingAs($this->user(UserRole::Viewer));
        $this->postJson(self::URL, ['items' => [$this->row('02', 'Ruang A')]])->assertStatus(403);

        Sanctum::actingAs($this->user(UserRole::Operator));
        $this->postJson(self::URL, ['items' => [$this->row('02', 'Ruang A')]])->assertStatus(403);

        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_admin_and_super_admin_create_rooms(): void
    {
        Sanctum::actingAs($this->user(UserRole::Admin));
        $this->postJson(self::URL, ['items' => [$this->row('02', 'Ruang A')]])->assertStatus(201);

        Sanctum::actingAs($this->user(UserRole::SuperAdmin));
        $this->postJson(self::URL, ['items' => [$this->row('04', 'Ruang A'), $this->row('03', 'Ruang A')]])
            ->assertStatus(201)->assertJsonPath('count', 2);

        $this->assertDatabaseCount('rooms', 3);
    }

    public function test_single_room_endpoint_is_unchanged(): void
    {
        Sanctum::actingAs($this->user(UserRole::Admin));

        $this->postJson('/api/rooms', ['location_code' => '02', 'name' => ' Gudang '])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Gudang')
            ->assertJsonPath('data.is_active', true);

        $this->postJson('/api/rooms', ['location_code' => '02', 'name' => 'gudang'])
            ->assertStatus(422)->assertJsonValidationErrors(['name']);

        Sanctum::actingAs($this->user(UserRole::UnitAdmin, '02'));
        $this->postJson('/api/rooms', ['location_code' => '03', 'name' => 'Ruang X'])->assertStatus(403);
    }
}
