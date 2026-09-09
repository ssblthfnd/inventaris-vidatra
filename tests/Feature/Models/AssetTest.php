<?php

namespace Tests\Feature\Models;

use App\Enums\AssetCondition;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Location;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssetTest extends TestCase
{
    use RefreshDatabase;

    private function identityFixtures(): array
    {
        $location = Location::factory()->create(['code' => '01']);
        $category = Category::factory()->create(['code' => '02']);
        $subcategory = Subcategory::factory()->forCategory($category)->create(['code' => '001', 'name' => 'MEJA']);

        return [$location, $category, $subcategory];
    }

    public function test_asset_code_is_generated_by_the_database_without_application_input(): void
    {
        [$location, $category, $subcategory] = $this->identityFixtures();

        $asset = Asset::factory()->identity('01', '02', '001', '001', 2026)->create();

        // never provided by the app
        $this->assertNull($asset->getAttributes()['asset_code'] ?? null);

        $asset->refresh();
        $this->assertSame('01.02.001.001.2026', $asset->asset_code);
    }

    public function test_asset_code_reflects_suffixed_sequence(): void
    {
        $this->identityFixtures();

        $asset = Asset::factory()->identity('01', '02', '001', '0017B', 2024)->create()->fresh();

        $this->assertSame('0017B', $asset->sequence_no);
        $this->assertSame('01.02.001.0017B.2024', $asset->asset_code);
    }

    public function test_assigning_asset_code_via_mass_assignment_is_ignored(): void
    {
        $this->identityFixtures();

        $asset = Asset::factory()->identity('01', '02', '001', '002', 2026)
            ->create()
            ->fresh();

        $asset->fill(['asset_code' => 'HACKED']);
        $this->assertSame('01.02.001.002.2026', $asset->asset_code); // fill() ignored it (not fillable)
    }

    public function test_sequence_no_is_preserved_verbatim(): void
    {
        $this->identityFixtures();

        foreach (['001', '0001', '005A', '005B', '0017B', '0010B'] as $i => $seq) {
            $asset = Asset::factory()->identity('01', '02', '001', $seq, 2020 + $i)->create()->fresh();
            $this->assertSame($seq, $asset->sequence_no);
        }
    }

    public function test_soft_delete_hides_the_asset_from_normal_queries_but_keeps_the_row(): void
    {
        $this->identityFixtures();
        $asset = Asset::factory()->identity('01', '02', '001', '900', 2026)->create();
        $id = $asset->id;

        $asset->delete();

        $this->assertNotNull($asset->fresh()->deleted_at);
        $this->assertNull(Asset::find($id));
        $this->assertNotNull(Asset::withTrashed()->find($id));
        $this->assertSame(1, DB::table('assets')->where('id', $id)->count());
    }

    public function test_business_disposal_uses_is_written_off_not_soft_delete(): void
    {
        $this->identityFixtures();
        $asset = Asset::factory()->writtenOff('2024-09-13')->identity('01', '02', '001', '901', 2026)->create();

        $asset->refresh();
        $this->assertTrue($asset->is_written_off);
        $this->assertSame('2024-09-13', $asset->written_off_on->toDateString());
        $this->assertNull($asset->deleted_at);
        $this->assertTrue(Asset::whereKey($asset->id)->exists()); // still queryable
    }

    public function test_condition_round_trips_as_enum_and_null(): void
    {
        $this->identityFixtures();

        $a = Asset::factory()->identity('01', '02', '001', '910', 2026)->create(['condition' => AssetCondition::RusakBerat]);
        $this->assertSame('rusak_berat', DB::table('assets')->where('id', $a->id)->value('condition'));
        $this->assertSame(AssetCondition::RusakBerat, $a->fresh()->condition);

        $b = Asset::factory()->identity('01', '02', '001', '911', 2026)->create(['condition' => null]);
        $this->assertNull(DB::table('assets')->where('id', $b->id)->value('condition'));
        $this->assertNull($b->fresh()->condition);
    }

    public function test_quantity_defaults_to_one(): void
    {
        $this->identityFixtures();
        $asset = Asset::factory()->identity('01', '02', '001', '912', 2026)->create();
        $this->assertSame(1, $asset->fresh()->quantity);
    }
}
