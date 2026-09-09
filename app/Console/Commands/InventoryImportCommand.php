<?php

namespace App\Console\Commands;

use App\Import\ImportManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Stage + validate one (or all three) inventory workbook(s) into import_batches / import_rows.
 *
 *   php artisan inventory:import "data/excel/Inventaris Meubelair.xlsx"
 *   php artisan inventory:import --all
 *   php artisan inventory:import --all --promote        (also promote valid+warning rows)
 *
 * NEVER writes directly to `assets` — promotion is a separate, explicit step.
 *
 * `--force` semantics (IMPORTANT — it is NOT "override duplicate"):
 *   Without --force, the command refuses to stage a workbook when a non-rolled-back
 *   import_batch already exists for the same (source_filename, source_sheet), so you do
 *   not accidentally create a second staging batch for the same file.
 *   --force ONLY lifts that guard and lets a fresh batch be created.
 *   It does NOT weaken validation or duplicate detection: every re-staged row is still
 *   checked against existing `assets`; a row whose business identity already exists is
 *   marked validation_status = 'error' with duplicate_of_asset_id set, is never
 *   promotable, and promotion never creates a second asset.
 */
class InventoryImportCommand extends Command
{
    protected $signature = 'inventory:import
        {file? : Path to an .xlsx workbook (relative to project root or absolute)}
        {--all : Import the three known inventory workbooks from data/excel/}
        {--promote : After staging, promote valid+warning rows into assets}
        {--force : Allow a NEW staging batch even if one already exists for this file/sheet. Does NOT bypass validation or duplicate detection.}';

    protected $description = 'Stage an Excel inventory workbook into the import staging tables (staging only; promotion is separate)';

    /** The actual inventory workbooks. "Kartu Inventaris Ruangan.xlsx" is a template — never imported. */
    private const KNOWN_FILES = [
        'data/excel/Inventaris Meubelair.xlsx',
        'data/excel/Inventaris Elektronik.xlsx',
        'data/excel/Inventaris Alat Kebersihan.xlsx',
    ];

    public function handle(ImportManager $manager): int
    {
        $files = $this->resolveFiles();
        if ($files === []) {
            $this->error('Provide a {file} argument or use --all.');

            return self::INVALID;
        }

        if (DB::table('locations')->count() === 0 || DB::table('categories')->count() === 0) {
            $this->error('Master data is empty. Run:  php artisan db:seed --class=Database\\Seeders\\MasterDataSeeder');

            return self::FAILURE;
        }

        $batchIds = [];
        $exit = self::SUCCESS;

        foreach ($files as $file) {
            $abs = $this->absolutePath($file);
            $this->line("\n<info>==></info> {$file}");

            if (str_contains(mb_strtolower(basename($abs)), 'kartu inventaris ruangan')) {
                $this->warn('  skipped — "Kartu Inventaris Ruangan" is a template, not a source of truth.');

                continue;
            }

            try {
                // Guard: refuse to create a SECOND staging batch for the same file/sheet
                // unless --force. This only prevents accidental re-staging; it is NOT a
                // duplicate-asset check (that always runs during validation & promotion).
                if (! $this->option('force')) {
                    $probe = new \App\Import\Excel\SheetScanner($abs);
                    // cheap: iterate generator until we know the sheet name
                    foreach ($probe->rows() as $_) {
                        break;
                    }
                    $existing = $manager->existingNonRolledBackBatches(basename($abs), $probe->sheetName);
                    if ($existing > 0) {
                        $this->warn("  {$existing} existing batch(es) for this file/sheet — use --force to stage another.");

                        continue;
                    }
                }

                $summary = $manager->stageFile($abs);
                $batchIds[] = $summary['batch_id'];

                $this->table(
                    ['batch', 'sheet', 'loc', 'cat', 'total', 'valid', 'warning', 'error', 'skipped', 'status'],
                    [[
                        $summary['batch_id'], $summary['sheet'], $summary['location_code'],
                        $summary['category_code'], $summary['total'], $summary['valid'],
                        $summary['warning'], $summary['error'], $summary['skipped_rows'], $summary['status'],
                    ]],
                );

                if ($this->option('promote')) {
                    $p = $manager->promoteBatch($summary['batch_id']);
                    $this->line("  promoted: {$p['promoted']}  already: {$p['skipped_already']}  failed: {$p['failed']}");
                    foreach ($p['errors'] as $err) {
                        $this->error("    row {$err['row']}: {$err['message']}");
                    }
                }
            } catch (\Throwable $e) {
                $this->error("  FAILED: {$e->getMessage()}");
                $exit = self::FAILURE;
            }
        }

        if ($batchIds !== []) {
            $this->line("\nStaged batch id(s): " . implode(', ', $batchIds));
            $this->line('Reports:  php artisan inventory:report --batch=' . implode(' --batch=', $batchIds));
        }

        return $exit;
    }

    /** @return list<string> */
    private function resolveFiles(): array
    {
        if ($this->option('all')) {
            return self::KNOWN_FILES;
        }
        $file = $this->argument('file');

        return $file !== null ? [$file] : [];
    }

    private function absolutePath(string $file): string
    {
        if (is_file($file)) {
            return $file;
        }

        return base_path($file);
    }
}
