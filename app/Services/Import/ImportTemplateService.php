<?php

namespace App\Services\Import;

use App\Import\Excel\SheetScanner;
use App\Import\ImportManager;
use App\Import\Parsing\CategoryColumnMap;
use App\Import\Parsing\RowParser;
use App\Models\Category;
use App\Models\Location;
use App\Models\Room;
use App\Models\Subcategory;
use App\Services\Excel\AssetSheetColumns;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Generates a downloadable "DATA fill-in" Excel template for Tahap 6.1's Import Excel
 * UI — one workbook PER category (`02` MEUBELAIR / `03` ELEKTRONIK / `06` ALAT
 * KEBERSIHAN), matching {@see SheetScanner} and
 * {@see CategoryColumnMap} EXACTLY: same column letters, same
 * two-row-header offset, same LOKASI/KODE BARANG metadata-row contract. This class
 * only ever WRITES a fresh workbook — it never reads/imports anything and never
 * touches the database beyond the read-only master-data lookups used to label the
 * sheet for humans.
 *
 * Three things the existing importer actually requires (reverse-engineered from
 * {@see SheetScanner}, not invented here):
 *
 *  1. A "KODE BARANG" metadata row (col A = 2-digit category code, col B contains
 *     "KODE BARANG") somewhere in the first 40 rows — {@see ImportManager::stageFile()}
 *     uses THIS to set `import_batches.category_code`, which in turn selects the
 *     {@see CategoryColumnMap} used to parse every data row. Getting this row wrong
 *     silently mis-parses the whole file, so the template's KODE BARANG row is
 *     generated from the real `categories` row, never hand-typed.
 *  2. A header row where col A reads exactly "No. Urut" (or col B contains
 *     "NOMOR ASET") — this is the ONLY thing that tells the scanner where the
 *     header is. Every other header cell in this template exists purely for human
 *     readability; the scanner never reads header TEXT beyond this one anchor.
 *  3. Data rows start at `headerRow + 2` UNCONDITIONALLY (one label row, one
 *     condition-legend row) — so exactly one extra row must exist below the header
 *     even though its own content is never parsed either.
 *
 * Tahap 4's legacy workbooks additionally group rows under a "block header" (a row
 * carrying only a subcategory code + name) and let a row's own column D fall back to
 * that block when blank. This template deliberately does NOT reproduce block
 * headers: it asks the user to fill column D on every single row instead (the
 * primary path {@see RowParser} already supports), which is far
 * simpler to explain than the legacy block convention and requires no importer
 * change at all.
 *
 * DATA is left with header/metadata rows only — no example data row. An "example"
 * row has no way to be told apart from a real one anywhere in the existing importer
 * (there is no such concept), so the only way to guarantee a stray example can never
 * silently become a real asset is to not put one there at all.
 */
final class ImportTemplateService
{
    public function generate(string $categoryCode): Spreadsheet
    {
        if (! CategoryColumnMap::isKnownCategory($categoryCode)) {
            throw new InvalidArgumentException("Unknown category [{$categoryCode}].");
        }

        $category = Category::query()->find($categoryCode);
        if ($category === null) {
            throw new InvalidArgumentException("Category [{$categoryCode}] does not exist in the master data.");
        }

        $spreadsheet = new Spreadsheet;

        $data = $spreadsheet->getActiveSheet();
        $data->setTitle('DATA');
        $this->buildDataSheet($data, $category);

        $panduan = $spreadsheet->createSheet();
        $panduan->setTitle('PANDUAN');
        $this->buildPanduanSheet($panduan, $category);

        $referensi = $spreadsheet->createSheet();
        $referensi->setTitle('REFERENSI');
        $this->buildReferensiSheet($referensi, $category);

        // DATA must be the ACTIVE sheet on save: SheetScanner always reads
        // getActiveSheet(), regardless of how many other sheets the workbook has.
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function buildDataSheet(Worksheet $sheet, Category $category): void
    {
        $categoryCode = $category->code;
        $specific = AssetSheetColumns::categorySpecificHeaders($categoryCode);

        $sheet->setCellValue('A1', 'TEMPLATE IMPOR INVENTARIS — '.mb_strtoupper($category->name));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        // row 2 intentionally blank (matches the legacy workbooks' own spacing)

        // --- LOKASI metadata row (row 3) — informational only; each DATA row's own
        // column B is what the importer actually reads per row. Left blank for the
        // user to fill in per-row; not required for the scanner to work.
        $sheet->setCellValue('B3', 'LOKASI');
        $sheet->setCellValue('F3', ':');
        $sheet->getStyle('B3')->getFont()->setBold(true);

        // --- KODE BARANG metadata row (row 4) — REQUIRED, drives category detection.
        $sheet->setCellValue('A4', $categoryCode);
        $sheet->setCellValue('B4', 'KODE BARANG');
        $sheet->setCellValue('F4', ':');
        $sheet->setCellValue('G4', $category->name);
        $sheet->getStyle('B4')->getFont()->setBold(true);

        // row 5 intentionally blank

        $headerRow = 6;
        $subHeaderRow = $headerRow + 1;

        foreach (AssetSheetColumns::COMMON_HEADERS as $col => $label) {
            $sheet->setCellValue("{$col}{$headerRow}", $label);
        }
        foreach ($specific as $col => $meta) {
            $sheet->setCellValue("{$col}{$headerRow}", $meta['label']);
        }
        foreach (AssetSheetColumns::CONDITION_SUBHEADERS as $col => $label) {
            $sheet->setCellValue("{$col}{$subHeaderRow}", $label);
        }
        foreach ($specific as $col => $meta) {
            if (isset($meta['sub'])) {
                $sheet->setCellValue("{$col}{$subHeaderRow}", $meta['sub']);
            }
        }

        $lastCol = array_key_last($specific) ?? 'P';
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$subHeaderRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$subHeaderRow}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$subHeaderRow}")->getAlignment()
            ->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$subHeaderRow}")->getBorders()
            ->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        foreach (array_keys(AssetSheetColumns::COMMON_HEADERS + $specific) as $col) {
            $sheet->getColumnDimension($col)->setWidth(16);
        }
        $sheet->getColumnDimension('P')->setWidth(24);
        $sheet->getColumnDimension('O')->setWidth(20);

        // Freeze everything above and including the header so it stays visible while
        // scrolling through data rows — first data row is deterministically headerRow+2.
        $sheet->freezePane('A'.($subHeaderRow + 1));

        // Data-validation dropdowns for the two most error-prone columns (condition
        // marks). Advisory only — the backend validator remains authoritative; this
        // just reduces obviously-wrong keystrokes in the columns that must carry an
        // exact recognised mark ({@see \App\Import\Parsing\ValueNormalizer::isConditionMark()}).
        $markValidation = function (string $col) use ($sheet, $subHeaderRow): void {
            for ($row = $subHeaderRow + 1; $row <= $subHeaderRow + 500; $row++) {
                $validation = $sheet->getCell("{$col}{$row}")->getDataValidation();
                $validation->setType(DataValidation::TYPE_LIST);
                $validation->setErrorStyle(DataValidation::STYLE_INFORMATION);
                $validation->setAllowBlank(true);
                $validation->setShowInputMessage(true);
                $validation->setShowErrorMessage(true);
                $validation->setShowDropDown(true);
                $validation->setPromptTitle('Tanda kondisi');
                $validation->setPrompt('Isi dengan "v" jika kondisi ini berlaku.');
                $validation->setFormula1('"v,-"');
            }
        };
        $markValidation('L');
        $markValidation('M');
        $markValidation('N');
    }

    private function buildPanduanSheet(Worksheet $sheet, Category $category): void
    {
        $categoryCode = $category->code;
        $specific = AssetSheetColumns::categorySpecificHeaders($categoryCode);

        $sheet->getColumnDimension('A')->setWidth(34);
        $sheet->getColumnDimension('B')->setWidth(90);

        $row = 1;
        $h1 = function (string $text) use ($sheet, &$row): void {
            $sheet->setCellValue("A{$row}", $text);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(13);
            $row += 2;
        };
        $h2 = function (string $text) use ($sheet, &$row): void {
            $sheet->setCellValue("A{$row}", $text);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;
        };
        $line = function (string $text, string $desc = '') use ($sheet, &$row): void {
            $sheet->setCellValue("A{$row}", $text);
            if ($desc !== '') {
                $sheet->setCellValue("B{$row}", $desc);
            }
            $sheet->getStyle("A{$row}:B{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $row++;
        };
        $blank = function () use (&$row): void {
            $row++;
        };

        $h1('PANDUAN PENGISIAN TEMPLATE IMPOR — '.mb_strtoupper($category->name));

        $h2('Tujuan template');
        $line(
            'Template ini digunakan untuk menyiapkan data aset '.mb_strtolower($category->name)
            .' agar dapat diimpor ke Inventaris Vidatra melalui menu Import Excel. '
            .'Isi sheet DATA, lalu unggah file ini di halaman Import Excel.'
        );
        $blank();

        $h2('Alur kerja (WAJIB dipahami)');
        $line('1. Unduh template ini.');
        $line('2. Isi sheet DATA sesuai kolom yang tersedia.');
        $line('3. Unggah file yang sudah diisi di halaman Import Excel.');
        $line('4. Sistem membaca dan memvalidasi setiap baris (belum menjadi data inventaris).');
        $line('5. Tinjau hasil pratinjau: baris Valid / Warning / Error / Duplikat.');
        $line('6. Klik "Promote / Import ke Inventaris" untuk baris yang Valid/Warning.');
        $line(
            'PENTING: mengunggah file TIDAK langsung membuat atau mengubah data inventaris. '
            .'Data baru dibuat hanya setelah langkah promosi (langkah 6) dijalankan secara eksplisit.'
        );
        $blank();

        $h2('Kolom yang WAJIB diisi di setiap baris');
        $line('B — Lokasi', 'Kode lokasi 2 digit, harus sesuai sheet REFERENSI (mis. 01).');
        $line('C — Kategori', "Kode kategori 2 digit. Untuk template ini selalu \"{$categoryCode}\" (".$category->name.').');
        $line('D — Subkategori', 'Kode subkategori 3 digit sesuai kategori di atas, lihat sheet REFERENSI. Isi pada SETIAP baris — jangan dikosongkan.');
        $line('E — No. Urut / Sequence', 'Nomor urut aset apa adanya (boleh mengandung huruf, mis. "005A"). Nilai ini akan menjadi bagian kode aset dan TIDAK diubah oleh sistem.');
        $line('F — Tahun', 'Tahun perolehan, 4 digit (mis. 2023).');
        $line('K — Jumlah Barang', 'Harus diisi 1. Setiap baris mewakili tepat satu unit aset.');
        $line('L / M / N — Keadaan Barang', 'Isi salah satu dengan tanda "v": L = Baik, M = Kurang Baik, N = Rusak Berat.');
        $blank();

        $h2('Kolom OPSIONAL');
        $line('G — Merk / Model, H — No. Seri Pabrik, I — Bahan', 'Boleh dikosongkan jika tidak diketahui.');
        $line('O — Ruangan', 'Nama ruangan apa adanya (lihat sheet REFERENSI untuk daftar ruangan yang sudah dikenal sistem). Jika dikosongkan atau tidak dikenali, baris tetap bisa diimpor sebagai Warning, namun ruangan aset akan kosong dan perlu dilengkapi kemudian.');
        $line('P — Ket. Mutasi dll', 'Catatan bebas.');
        foreach ($specific as $col => $meta) {
            $desc = match (true) {
                $categoryCode === '03' && $col === 'Q' => 'Jenis barang elektronik (mis. "CPU", "Monitor").',
                $categoryCode === '03' && $col === 'S' => 'Isi dengan kata yang mengandung "Dihapus"/"Di Junk" jika aset ini sudah di-write-off. Kosongkan jika tidak.',
                $categoryCode === '03' && $col === 'T' => 'Tanggal write-off, hanya jika kolom STATUS diisi.',
                $col === 'R' && $categoryCode !== '03' => 'Catatan bebas (kolom ini historis bernama "TANGGAL PEMBELIAN" namun diperlakukan sebagai catatan bebas oleh sistem).',
                default => 'Catatan/keterangan bebas.',
            };
            $line("{$col} — {$meta['label']}", $desc);
        }
        $blank();

        $h2('Format tanggal (kolom TANGGAL, jika ada)');
        $line('Diterima: YYYY-MM-DD, DD/MM/YYYY, DD-MM-YYYY, tanggal Excel asli, atau format Indonesia seperti "30 Mei 2023".');
        $line('Jika format tidak dikenali, tanggal akan dikosongkan dan baris ditandai Warning — bukan Error.');
        $blank();

        $h2('Kode aset historis — TIDAK dibuat ulang oleh sistem');
        $line(
            'Kode aset (Lokasi.Kategori.Subkategori.NoUrut.Tahun) diambil APA ADANYA dari kolom B, C, D, E, F. '
            .'Jangan mencoba membuat nomor urut baru secara manual di sini — sistem tidak menghasilkan nomor '
            .'baru untuk baris import. Nomor otomatis hanya berlaku untuk aset BARU yang ditambahkan melalui '
            .'menu "Tambah Aset" / "Tambah Banyak Aset" di aplikasi, bukan lewat template ini.'
        );
        $blank();

        $h2('Arti status baris setelah diunggah');
        $line('Valid', 'Data lengkap dan benar. Siap dipromosikan menjadi aset.');
        $line('Warning', 'Baris tetap bisa dipromosikan, namun ada hal yang perlu diperhatikan (misalnya ruangan tidak dikenali, atau tanggal tidak terbaca).');
        $line('Error', 'Baris TIDAK bisa dipromosikan sampai diperbaiki (misalnya kode lokasi/kategori/subkategori tidak dikenal, No. Urut kosong, atau data sudah pernah diimpor sebelumnya).');
        $line('Duplikat', 'Kombinasi Lokasi+Kategori+Subkategori+No. Urut+Tahun sudah ada — baik di baris lain pada file yang sama, maupun sudah menjadi aset di sistem. Ditandai sebagai Error dan tidak akan membuat aset kedua.');
        $blank();

        $h2('Setelah promosi');
        $line('Baris yang berhasil dipromosikan akan langsung muncul di menu Inventaris.');
        $line('Baris yang gagal atau belum dipromosikan tetap bisa dilihat dan diperbaiki, lalu diimpor ulang sebagai file baru.');
    }

    private function buildReferensiSheet(Worksheet $sheet, Category $category): void
    {
        $sheet->setCellValue('A1', 'REFERENSI (hanya untuk dibaca — tidak diproses oleh sistem)');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $sheet->setCellValue('A2', 'Sheet ini HANYA membantu Anda mengisi sheet DATA. Mengubah isi sheet ini tidak akan mengubah master data aplikasi.');
        $sheet->getStyle('A2')->getFont()->setItalic(true);

        $row = 4;
        $sheet->setCellValue("A{$row}", 'LOKASI');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;
        $sheet->fromArray(['Kode', 'Nama', 'Alias'], null, "A{$row}");
        $sheet->getStyle("A{$row}:C{$row}")->getFont()->setBold(true);
        $row++;
        foreach (Location::query()->where('is_active', true)->orderBy('code')->get(['code', 'name', 'alias']) as $location) {
            $sheet->fromArray([$location->code, $location->name, $location->alias ?? ''], null, "A{$row}");
            $row++;
        }
        $row++;

        $sheet->setCellValue("A{$row}", 'SUBKATEGORI — '.mb_strtoupper($category->name)." (kategori {$category->code})");
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;
        $sheet->fromArray(['Kode', 'Nama'], null, "A{$row}");
        $sheet->getStyle("A{$row}:B{$row}")->getFont()->setBold(true);
        $row++;
        foreach (Subcategory::query()->where('category_code', $category->code)->where('is_active', true)->orderBy('code')->get(['code', 'name']) as $sub) {
            $sheet->fromArray([$sub->code, $sub->name], null, "A{$row}");
            $row++;
        }
        $row++;

        $sheet->setCellValue("A{$row}", 'RUANGAN (per lokasi)');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;
        $sheet->fromArray(['Kode Lokasi', 'Nama Ruangan'], null, "A{$row}");
        $sheet->getStyle("A{$row}:B{$row}")->getFont()->setBold(true);
        $row++;
        foreach (Room::query()->where('is_active', true)->orderBy('location_code')->orderBy('name')->get(['location_code', 'name']) as $room) {
            $sheet->fromArray([$room->location_code, $room->name], null, "A{$row}");
            $row++;
        }
        $row++;

        $sheet->setCellValue("A{$row}", 'KEADAAN BARANG (isi salah satu kolom L/M/N di sheet DATA dengan "v")');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;
        foreach (['L = Baik', 'M = Kurang Baik', 'N = Rusak Berat'] as $text) {
            $sheet->setCellValue("A{$row}", $text);
            $row++;
        }

        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(40);
        $sheet->getColumnDimension('C')->setWidth(16);
    }
}
