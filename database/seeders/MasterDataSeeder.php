<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seluruh master data Tahap 1 (finalized) yang wajib ada sebelum import Tahap 4 dijalankan.
 * Importer TIDAK BOLEH membuat master data ini otomatis (Tahap 4 §41).
 *
 *   php artisan db:seed --class=Database\\Seeders\\MasterDataSeeder
 */
class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            LocationSeeder::class,
            CategorySeeder::class,
            SubcategorySeeder::class,
            RoomSeeder::class,
            RoomAliasSeeder::class,
        ]);
    }
}
