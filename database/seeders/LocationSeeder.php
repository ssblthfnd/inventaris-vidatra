<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Master lokasi — data/reference/master_data.md §1.
 * Kode dipertahankan apa adanya (CHAR(2), leading zero signifikan).
 */
class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $rows = [
            ['code' => '01', 'name' => 'YAYASAN', 'alias' => 'PH'],
            ['code' => '02', 'name' => 'SD', 'alias' => null],
            ['code' => '03', 'name' => 'SMP', 'alias' => null],
            ['code' => '04', 'name' => 'SMA', 'alias' => null],
        ];

        foreach ($rows as $r) {
            DB::table('locations')->updateOrInsert(
                ['code' => $r['code']],
                $r + ['is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }
}
