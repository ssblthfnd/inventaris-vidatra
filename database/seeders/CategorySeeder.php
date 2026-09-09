<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Master kategori — data/reference/master_data.md §2.
 * Nama final memakai ejaan data Excel aktual (MEUBELAIR, bukan MEBEULAIR).
 * Kategori 01/04/05 belum punya data aset tapi tetap di-seed sebagai master valid.
 */
class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $rows = [
            ['code' => '01', 'name' => 'TANAH DAN BANGUNAN'],
            ['code' => '02', 'name' => 'MEUBELAIR'],
            ['code' => '03', 'name' => 'ELEKTRONIK'],
            ['code' => '04', 'name' => 'MEKANIK'],
            ['code' => '05', 'name' => 'ALAT RUMAH TANGGA'],
            ['code' => '06', 'name' => 'ALAT KEBERSIHAN'],
        ];

        foreach ($rows as $r) {
            DB::table('categories')->updateOrInsert(
                ['code' => $r['code']],
                $r + ['is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }
}
