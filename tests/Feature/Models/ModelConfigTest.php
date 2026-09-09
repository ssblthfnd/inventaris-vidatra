<?php

namespace Tests\Feature\Models;

use App\Enums\AssetCondition;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Location;
use App\Models\MutationLog;
use App\Models\Room;
use App\Models\RoomAlias;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tahap 5.1 — model metadata: primary keys, key type, incrementing, casts.
 */
class ModelConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_location_has_a_non_incrementing_string_primary_key(): void
    {
        $location = Location::factory()->create(['code' => '01']);

        $this->assertSame('code', $location->getKeyName());
        $this->assertFalse($location->incrementing);
        $this->assertSame('string', $location->getKeyType());
        $this->assertSame('01', $location->getKey());
        $this->assertTrue($location->is_active);
        $this->assertIsBool($location->is_active);
    }

    public function test_category_has_a_non_incrementing_string_primary_key(): void
    {
        $category = Category::factory()->create(['code' => '02']);

        $this->assertSame('code', $category->getKeyName());
        $this->assertFalse($category->incrementing);
        $this->assertSame('string', $category->getKeyType());
        $this->assertSame('02', $category->getKey());
        $this->assertIsBool($category->is_active);
    }

    public function test_subcategory_uses_the_surrogate_id_as_primary_key(): void
    {
        $subcategory = Subcategory::factory()->create();

        $this->assertSame('id', $subcategory->getKeyName());
        $this->assertTrue($subcategory->incrementing);
        $this->assertIsInt($subcategory->id);
        $this->assertIsBool($subcategory->is_active);
    }

    public function test_room_and_room_alias_casts(): void
    {
        $room = Room::factory()->create();
        $this->assertIsBool($room->is_active);

        $alias = RoomAlias::factory()->create();
        $this->assertContains($alias->source, ['manual', 'tahap1_seed']);
    }

    public function test_asset_casts(): void
    {
        $asset = Asset::factory()->create([
            'asset_year' => 2020,
            'quantity' => 1,
            'is_written_off' => 1,
            'written_off_on' => '2024-05-01',
            'purchase_date' => '2020-03-15',
            'condition' => 'kurang_baik',
        ]);

        $this->assertIsInt($asset->asset_year);
        $this->assertIsInt($asset->quantity);
        $this->assertIsBool($asset->is_written_off);
        $this->assertTrue($asset->is_written_off);
        $this->assertInstanceOf(Carbon::class, $asset->written_off_on);
        $this->assertSame('2024-05-01', $asset->written_off_on->toDateString());
        $this->assertInstanceOf(Carbon::class, $asset->purchase_date);
        $this->assertInstanceOf(AssetCondition::class, $asset->condition);
        $this->assertSame(AssetCondition::KurangBaik, $asset->condition);
    }

    public function test_asset_sequence_no_is_a_string_never_cast_to_int(): void
    {
        $asset = Asset::factory()->create(['sequence_no' => '0001']);
        $this->assertIsString($asset->sequence_no);
        $this->assertSame('0001', $asset->fresh()->sequence_no);

        $this->assertArrayNotHasKey('sequence_no', $asset->getCasts());
        $this->assertArrayNotHasKey('asset_code', $asset->getCasts());
    }

    public function test_asset_condition_may_be_null(): void
    {
        $asset = Asset::factory()->unknownCondition()->create();
        $this->assertNull($asset->fresh()->condition);
    }

    public function test_mutation_log_has_no_updated_at(): void
    {
        $this->assertNull(MutationLog::UPDATED_AT);
        $this->assertFalse(Schema::hasColumn('mutation_logs', 'updated_at'));

        $log = MutationLog::factory()->create([
            'condition_before' => 'baik',
            'condition_after' => 'rusak_berat',
        ]);
        $this->assertInstanceOf(AssetCondition::class, $log->condition_before);
        $this->assertSame(AssetCondition::RusakBerat, $log->condition_after);
        $this->assertInstanceOf(Carbon::class, $log->mutation_date);
        $this->assertNull($log->updated_at);
    }

    public function test_import_batch_and_row_casts(): void
    {
        $batch = ImportBatch::factory()->create(['total_rows' => 186, 'valid_rows' => 180]);
        $this->assertIsInt($batch->total_rows);
        $this->assertIsInt($batch->valid_rows);

        $row = ImportRow::factory()->create();
        $this->assertIsInt($row->row_number);
        $this->assertIsArray($row->raw_payload);
        $this->assertIsArray($row->validation_messages);
    }

    public function test_user_role_and_is_active_casts_still_work(): void
    {
        $user = User::factory()->admin()->create();

        $this->assertInstanceOf(UserRole::class, $user->role);
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertIsBool($user->is_active);
        $this->assertTrue($user->isAdmin());
        $this->assertTrue($user->hasRole(UserRole::Admin, UserRole::Operator));
    }
}
