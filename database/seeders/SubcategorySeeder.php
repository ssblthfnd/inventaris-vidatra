<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Master subkategori — data/reference/master_data.md §3.
 *
 * `name`       = nama final dari header blok + baris data Excel (source of truth).
 * `guide_name` = nama versi Panduan Penomoran (informasi historis; null bila Panduan
 *                tidak menamai kode tsb). Kode `03/007`, `03/018`, `03/020` KONFLIK arti
 *                dengan Panduan — nama final tetap mengikuti Excel (master_data.md §4).
 *
 * Kode `03/006`, `03/017`, `03/019` TIDAK dipakai (0 baris data) -> tidak di-seed.
 * Kategori `01`, `04`, `05` belum ada data -> subkategori tidak di-seed (master_data.md §12 D8).
 */
class SubcategorySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $data = [
            '02' => [
                ['001', 'MEJA', 'MEJA'],
                ['002', 'KURSI', 'KURSI'],
                ['003', 'LEMARI / RAK', 'LEMARI'],
                ['004', 'SAFETY BOX', 'SAFETY BOX'],
                ['005', 'PAPAN TULIS', null],
                ['006', 'FOTO / GAMBAR', null],
                ['007', 'TIANG BENDERA', null],
                ['008', 'TANGGA', null],
            ],
            '03' => [
                ['001', 'KOMPUTER', 'KOMPUTER'],
                ['002', 'LCD / KABEL', 'LCD'],
                ['003', 'PRINTER', 'PRINTER'],
                ['004', 'SCANNER', 'SCANNER'],
                ['005', 'TELEVISI / TAPE / RADIO / CD / DVD', 'TAPE / RADIO'],
                ['007', 'KIPAS ANGIN', 'TELEVISI'],
                ['008', 'HANDYCAM / KAMERA FOTO', 'KAMERA FOTO'],
                ['009', 'HANDYCAM / WEBCAM', 'HANDYCAM'],
                ['010', 'SPEAKER', 'SPEAKER / SALON'],
                ['011', 'AMPLIFIER / EQUALIZER / MIC', 'POWER / AMPLIFIER'],
                ['012', 'TELEPON / HP', 'TELEPON / HP'],
                ['013', 'BOR LISTRIK / GURINDA / KETAM', 'BOR LISTRIK'],
                ['014', 'JAM', 'JAM DINDING'],
                ['015', 'AC', 'AC'],
                ['016', 'DISPENSER', 'DISPENSER'],
                ['018', 'HUB INTERNET / MODEM', 'RISHOGRAPH'],
                ['020', 'LAPTOP', 'KULKAS'],
                ['021', 'KULKAS', null],
                ['022', 'PEMOTONG KERTAS', null],
                ['023', 'KALKULATOR ELECTRIC', null],
                ['024', 'ALAT KESEHATAN', null],
            ],
            '06' => [
                ['001', 'TEMPAT SAMPAH', 'TEMPAT SAMPAH'],
                ['002', 'VACUUM CLEANER', 'VACUUM CLEANER'],
                ['003', 'HIGHT PRESURE (SEMPROTAN)', null],
            ],
        ];

        foreach ($data as $categoryCode => $subs) {
            foreach ($subs as [$code, $name, $guideName]) {
                DB::table('subcategories')->updateOrInsert(
                    ['category_code' => $categoryCode, 'code' => $code],
                    [
                        'name' => $name,
                        'guide_name' => $guideName,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }
    }
}
