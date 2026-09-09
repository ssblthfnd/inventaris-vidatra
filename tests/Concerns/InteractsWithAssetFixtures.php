<?php

namespace Tests\Concerns;

use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Location;
use App\Models\Room;
use App\Models\Subcategory;
use App\Models\User;

/**
 * Shared fixtures for the Tahap 5.4 asset write / lifecycle tests.
 *
 * Master codes use letters (`ZL`, `ZC`, …) so they never collide with the numeric
 * codes the factories draw from.
 */
trait InteractsWithAssetFixtures
{
    protected function operator(bool $active = true): User
    {
        return User::factory()->create(['role' => UserRole::Operator, 'is_active' => $active]);
    }

    protected function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }

    protected function viewer(): User
    {
        return User::factory()->create(['role' => UserRole::Viewer, 'is_active' => true]);
    }

    /**
     * Ensure a (location, category, subcategory) scope exists.
     *
     * @return array{0: Location, 1: Category, 2: Subcategory}
     */
    protected function scope(string $loc = 'ZL', string $cat = 'ZC', string $sub = '001', bool $active = true): array
    {
        $location = Location::query()->firstOrCreate(
            ['code' => $loc],
            ['name' => "Lokasi {$loc}", 'is_active' => $active],
        );
        $category = Category::query()->firstOrCreate(
            ['code' => $cat],
            ['name' => "Kategori {$cat}", 'is_active' => $active],
        );
        $subcategory = Subcategory::query()->firstOrCreate(
            ['category_code' => $cat, 'code' => $sub],
            ['name' => "Subkategori {$cat}-{$sub}", 'is_active' => $active],
        );

        return [$location, $category, $subcategory];
    }

    protected function room(string $loc = 'ZL', array $overrides = []): Room
    {
        [$location] = $this->scope($loc, 'ZC', '001');

        return Room::factory()->forLocation($location)->create($overrides);
    }

    /**
     * Create an existing asset with an explicit sequence in a scope (bypasses the
     * generator — this is historical-style data).
     */
    protected function existingAsset(string $seq, int $year = 2020, array $overrides = [], string $loc = 'ZL', string $cat = 'ZC', string $sub = '001'): Asset
    {
        $this->scope($loc, $cat, $sub);

        return Asset::factory()
            ->identity($loc, $cat, $sub, $seq, $year)
            ->create($overrides)
            ->fresh();
    }

    /**
     * A valid `POST /api/assets` payload for the default scope.
     *
     * @return array<string, mixed>
     */
    protected function validCreatePayload(array $overrides = []): array
    {
        $this->scope();

        return array_merge([
            'location_code' => 'ZL',
            'category_code' => 'ZC',
            'subcategory_code' => '001',
            'asset_year' => 2025,
            'condition' => 'baik',
        ], $overrides);
    }
}
