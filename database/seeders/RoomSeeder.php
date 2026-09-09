<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Master ruangan kanonik — data/reference/mapping_ruangan.md §1.
 * 15 ruangan, semua lokasi `01`. PIC dari keputusan user (§1). Tidak ada ruangan 'Lainnya'.
 */
class RoomSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // [name, pic]
        $rooms = [
            ['Ruangan Ketua Harian', 'Ketua Harian'],
            ['Ruangan Personalia & SARPRAS', 'Kabid SARPRAS'],
            ['Ruangan Admin Personalia / SARPRAS', 'Admin Personalia / SARPRAS'],
            ['Ruangan Pendidikan', 'Kabid Pendidikan & Admin Pendidikan'],
            ['Ruangan Keuangan', 'Kabag Keuangan & Admin Keuangan'],
            ['Ruangan Rapat', null],
            ['Ruangan Humas IT', 'Admin IT'],
            ['Ruangan Usaha', null],
            ['Musholla', null],
            ['CS', null],
            ['Ruangan Cikini', null],
            ['Ruangan PLS', null],
            ['Gudang', null],
            ['WC Pria', null],
            ['WC Wanita', null],
        ];

        foreach ($rooms as [$name, $pic]) {
            DB::table('rooms')->updateOrInsert(
                ['location_code' => '01', 'name' => $name],
                [
                    'pic' => $pic,
                    'notes' => null,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }
}
