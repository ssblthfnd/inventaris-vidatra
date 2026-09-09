<?php

namespace Database\Seeders;

use App\Import\Parsing\ValueNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Kamus alias ruangan — data/reference/mapping_ruangan.md §3 (keputusan final Tahap 1B).
 *
 * Semua 59 string mentah kolom "Ruangan" pada 3 workbook, semua lokasi `01`.
 * `match_key` dihitung dari raw_value (UPPER + collapse whitespace). Beberapa raw string
 * hanya berbeda kapitalisasi -> menghasilkan match_key yang sama -> di-dedup (unique
 * (location_code, match_key)). Bila dua raw dengan match_key sama memetakan ke ruangan
 * BERBEDA, seeder gagal (indikasi kesalahan data referensi).
 *
 * Importer TIDAK BOLEH menambah alias otomatis (Tahap 4 §16, §41) — hanya seeder ini
 * atau penambahan manual lewat mekanisme aplikasi.
 */
class RoomAliasSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // raw_value => canonical room name  (mapping_ruangan.md §3.1 .. §3.15)
        $map = [
            // Ruangan Ketua Harian
            'KETUA HARIAN' => 'Ruangan Ketua Harian',
            'KETUA HARIAN1' => 'Ruangan Ketua Harian',
            'KETUA HARIAN2' => 'Ruangan Ketua Harian',
            'Ketua Harian' => 'Ruangan Ketua Harian',
            'ADM KA HARIAN' => 'Ruangan Ketua Harian',

            // Ruangan Personalia & SARPRAS
            'PERSO DAN UMUM' => 'Ruangan Personalia & SARPRAS',
            'SARANA DAN HUMAS' => 'Ruangan Personalia & SARPRAS',
            'SARANA & HUMAS' => 'Ruangan Personalia & SARPRAS',
            'ADM UMUM' => 'Ruangan Personalia & SARPRAS',
            'SARANA PRASARANA' => 'Ruangan Personalia & SARPRAS',
            'PERSONALIA' => 'Ruangan Personalia & SARPRAS',
            'SARANA' => 'Ruangan Personalia & SARPRAS',
            'Sarpras' => 'Ruangan Personalia & SARPRAS',
            'SARANA DAN HUM' => 'Ruangan Personalia & SARPRAS',
            'umum' => 'Ruangan Personalia & SARPRAS',
            'Personalia' => 'Ruangan Personalia & SARPRAS',
            'PERSO DAN HUMAS' => 'Ruangan Personalia & SARPRAS',
            'Sarana' => 'Ruangan Personalia & SARPRAS',
            'KABIS SARPRAS' => 'Ruangan Personalia & SARPRAS',

            // Ruangan Admin Personalia / SARPRAS
            'Adm Personalia' => 'Ruangan Admin Personalia / SARPRAS',
            'ADM PERSONALIA' => 'Ruangan Admin Personalia / SARPRAS',
            'ADM PERSO' => 'Ruangan Admin Personalia / SARPRAS',
            'Adm Sarpras' => 'Ruangan Admin Personalia / SARPRAS',

            // Ruangan Pendidikan
            'PENDIDIKAN' => 'Ruangan Pendidikan',
            'PENDIDIKAN1' => 'Ruangan Pendidikan',
            'PENDIDIKAN2' => 'Ruangan Pendidikan',
            'KABID PENDIDIKAN' => 'Ruangan Pendidikan',

            // Ruangan Keuangan
            'KEUANGAN' => 'Ruangan Keuangan',
            'BAG. KEUANGAN' => 'Ruangan Keuangan',
            'Adm Keuangan' => 'Ruangan Keuangan',
            'BAG. KUANGAN' => 'Ruangan Keuangan',
            'KABAG KEUANGAN' => 'Ruangan Keuangan',
            'ADM KEUANGAN' => 'Ruangan Keuangan',
            'Keuangan' => 'Ruangan Keuangan',
            'Kabag Keuangan' => 'Ruangan Keuangan',
            'Kabag keuangan' => 'Ruangan Keuangan',
            'Adm keuangan' => 'Ruangan Keuangan',
            'R. KEUANGAN' => 'Ruangan Keuangan',

            // Ruangan Rapat
            'R. RAPAT' => 'Ruangan Rapat',
            'R. RAPAT YAYASAN' => 'Ruangan Rapat',
            'R.RAPAT' => 'Ruangan Rapat',
            'RAPAT MEETING' => 'Ruangan Rapat',
            'RUANG RAPAT' => 'Ruangan Rapat',
            'R. MEETING' => 'Ruangan Rapat',

            // Ruangan Humas IT
            'HUMAS IT' => 'Ruangan Humas IT',

            // Ruangan Usaha
            'USAHA' => 'Ruangan Usaha',
            'Adm Usaha' => 'Ruangan Usaha',
            'Kabag Usaha' => 'Ruangan Usaha',
            'ADM USAHA' => 'Ruangan Usaha',
            'ADM Usaha' => 'Ruangan Usaha',
            'KABAG Usaha' => 'Ruangan Usaha',

            // Musholla
            'MUSHOLLAH' => 'Musholla',

            // CS
            'CS' => 'CS',

            // Ruangan Cikini
            'CIKINI' => 'Ruangan Cikini',

            // Ruangan PLS
            'Adm PLS' => 'Ruangan PLS',
            'Kabid PLS' => 'Ruangan PLS',

            // Gudang
            'GUDANG' => 'Gudang',

            // WC Pria / Wanita
            'WC PRIA' => 'WC Pria',
            'WC WANITA' => 'WC Wanita',
        ];

        $roomIds = DB::table('rooms')->where('location_code', '01')->pluck('id', 'name');

        $byKey = []; // match_key => ['raw' => ..., 'room' => ...]
        foreach ($map as $raw => $roomName) {
            if (! isset($roomIds[$roomName])) {
                throw new RuntimeException("RoomAliasSeeder: canonical room not found: [{$roomName}] — run RoomSeeder first.");
            }
            $key = ValueNormalizer::roomMatchKey($raw);
            if (isset($byKey[$key]) && $byKey[$key]['room'] !== $roomName) {
                throw new RuntimeException(
                    "RoomAliasSeeder: match_key [{$key}] maps to two rooms: "
                    . "[{$byKey[$key]['room']}] and [{$roomName}] — check mapping_ruangan.md."
                );
            }
            $byKey[$key] ??= ['raw' => $raw, 'room' => $roomName];
        }

        foreach ($byKey as $key => $info) {
            DB::table('room_aliases')->updateOrInsert(
                ['location_code' => '01', 'match_key' => $key],
                [
                    'raw_value' => $info['raw'],
                    'room_id' => $roomIds[$info['room']],
                    'source' => 'tahap1_seed',
                    'notes' => null,
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }
}
