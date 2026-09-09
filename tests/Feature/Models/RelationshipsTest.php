<?php

namespace Tests\Feature\Models;

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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_location_relationships(): void
    {
        $location = Location::factory()->create();
        Room::factory()->count(2)->forLocation($location)->create();
        RoomAlias::factory()->forRoom(Room::factory()->forLocation($location)->create())->create();
        Asset::factory()->count(3)->create(['location_code' => $location->code]);

        $this->assertInstanceOf(Collection::class, $location->rooms);
        $this->assertCount(3, $location->rooms); // 2 + 1 (the alias's room)
        $this->assertCount(1, $location->roomAliases);
        $this->assertCount(3, $location->assets);
    }

    public function test_category_relationships(): void
    {
        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->forCategory($category)->create();
        Asset::factory()->forSubcategory($subcategory)->create();
        ImportBatch::factory()->create(['category_code' => $category->code]);

        $this->assertCount(1, $category->subcategories);
        $this->assertCount(1, $category->assets);
        $this->assertCount(1, $category->importBatches);
        $this->assertTrue($subcategory->category->is($category));
    }

    public function test_subcategory_assets_query_is_composite_aware(): void
    {
        $categoryA = Category::factory()->create(['code' => 'AA']);
        $categoryB = Category::factory()->create(['code' => 'BB']);

        // Same subcategory CODE ('001') under two different categories.
        $subA = Subcategory::factory()->forCategory($categoryA)->create(['code' => '001']);
        $subB = Subcategory::factory()->forCategory($categoryB)->create(['code' => '001']);

        Asset::factory()->count(2)->forSubcategory($subA)->create();
        Asset::factory()->count(5)->forSubcategory($subB)->create();

        $this->assertSame(2, $subA->assets()->count());
        $this->assertSame(5, $subB->assets()->count());
        $this->assertTrue($subA->assets()->get()->every(fn (Asset $a) => $a->category_code === 'AA'));
    }

    public function test_asset_belongs_to_relationships(): void
    {
        $location = Location::factory()->create();
        $subcategory = Subcategory::factory()->create();
        $room = Room::factory()->forLocation($location)->create();
        $creator = User::factory()->create();
        $updater = User::factory()->create();

        $asset = Asset::factory()
            ->forSubcategory($subcategory)
            ->inRoom($room)
            ->create([
                'location_code' => $location->code,
                'created_by' => $creator->id,
                'updated_by' => $updater->id,
            ]);

        $this->assertTrue($asset->location->is($location));
        $this->assertSame($subcategory->category_code, $asset->category->code);
        $this->assertTrue($asset->room->is($room));
        $this->assertTrue($asset->createdBy->is($creator));
        $this->assertTrue($asset->updatedBy->is($updater));
    }

    public function test_asset_subcategory_accessor_resolves_the_exact_subcategory(): void
    {
        $catA = Category::factory()->create(['code' => 'AA']);
        $catB = Category::factory()->create(['code' => 'BB']);
        $subA = Subcategory::factory()->forCategory($catA)->create(['code' => '007', 'name' => 'KIPAS ANGIN']);
        Subcategory::factory()->forCategory($catB)->create(['code' => '007', 'name' => 'TELEVISI']);

        $asset = Asset::factory()->forSubcategory($subA)->create();

        $this->assertInstanceOf(Subcategory::class, $asset->subcategory);
        $this->assertTrue($asset->subcategory->is($subA));
        $this->assertSame('KIPAS ANGIN', $asset->subcategory->name);
    }

    public function test_asset_has_many_mutation_logs(): void
    {
        $asset = Asset::factory()->create();
        MutationLog::factory()->count(3)->create(['asset_id' => $asset->id]);

        $this->assertCount(3, $asset->mutationLogs);
    }

    public function test_room_relationships(): void
    {
        $room = Room::factory()->create();
        RoomAlias::factory()->count(2)->forRoom($room)->create();
        Asset::factory()->inRoom($room)->create();

        $this->assertTrue($room->location->is($room->location));
        $this->assertCount(2, $room->roomAliases);
        $this->assertCount(1, $room->assets);
    }

    public function test_room_alias_relationships(): void
    {
        $room = Room::factory()->create();
        $creator = User::factory()->create();
        $alias = RoomAlias::factory()->forRoom($room)->create(['created_by' => $creator->id]);

        $this->assertTrue($alias->room->is($room));
        $this->assertSame($room->location_code, $alias->location->code);
        $this->assertTrue($alias->createdBy->is($creator));
    }

    public function test_mutation_log_relationships(): void
    {
        $asset = Asset::factory()->create();
        $performer = User::factory()->create();
        $log = MutationLog::factory()->create([
            'asset_id' => $asset->id,
            'performed_by' => $performer->id,
            'from_location_code' => $asset->location_code,
        ]);

        $this->assertTrue($log->asset->is($asset));
        $this->assertTrue($log->createdBy->is($performer));
        $this->assertSame($asset->location_code, $log->fromLocation->code);
    }

    public function test_import_batch_and_row_relationships(): void
    {
        $batch = ImportBatch::factory()->create();
        $rows = ImportRow::factory()->count(4)->create(['import_batch_id' => $batch->id]);

        $this->assertCount(4, $batch->importRows);
        $this->assertTrue($rows->first()->importBatch->is($batch));

        $existing = Asset::factory()->create();
        $promoted = Asset::factory()->create();
        $row = ImportRow::factory()->create([
            'import_batch_id' => $batch->id,
            'duplicate_of_asset_id' => $existing->id,
            'promoted_asset_id' => $promoted->id,
        ]);

        $this->assertTrue($row->duplicateOfAsset->is($existing));
        $this->assertTrue($row->promotedAsset->is($promoted));
    }

    public function test_user_domain_relationships(): void
    {
        $user = User::factory()->create();

        Asset::factory()->count(2)->create(['created_by' => $user->id]);
        Asset::factory()->create(['updated_by' => $user->id]);
        MutationLog::factory()->create(['performed_by' => $user->id]);
        RoomAlias::factory()->forRoom(Room::factory()->create())->create(['created_by' => $user->id]);
        ImportBatch::factory()->create(['uploaded_by' => $user->id]);

        $this->assertCount(2, $user->createdAssets);
        $this->assertCount(1, $user->updatedAssets);
        $this->assertCount(1, $user->mutationLogs);
        $this->assertCount(1, $user->createdRoomAliases);
        $this->assertCount(1, $user->uploadedImportBatches);
    }
}
