<?php

namespace App\Console\Commands;

use App\Import\ImportManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Promote validated staging rows (valid + warning) into `assets`.
 * Idempotent: already-promoted rows are skipped.
 *
 *   php artisan inventory:promote 3
 *   php artisan inventory:promote --all
 */
class InventoryPromoteCommand extends Command
{
    protected $signature = 'inventory:promote
        {batch? : import_batches.id to promote}
        {--all : Promote every batch whose status is validated / partially_imported}';

    protected $description = 'Promote staged import rows into the assets table (transactional, per row)';

    public function handle(ImportManager $manager): int
    {
        if ($this->argument('batch') === null && ! $this->option('all')) {
            $this->error('Provide a {batch} id or use --all.');

            return self::INVALID;
        }

        $batchIds = $this->resolveBatchIds();
        if ($batchIds === []) {
            $this->info('Nothing to promote — no batch in status validated / partially_imported.');

            return self::SUCCESS;
        }

        $exit = self::SUCCESS;
        foreach ($batchIds as $id) {
            $this->line("\n<info>==></info> batch {$id}");
            try {
                $r = $manager->promoteBatch((int) $id);
                $this->line("  promoted: {$r['promoted']}   already promoted: {$r['skipped_already']}   failed: {$r['failed']}");
                foreach ($r['errors'] as $err) {
                    $this->error("    row {$err['row']}: {$err['message']}");
                }
                $batch = DB::table('import_batches')->find($id);
                $this->line("  batch status: {$batch->status}   imported_rows: {$batch->imported_rows}/{$batch->total_rows}");
            } catch (\Throwable $e) {
                $this->error("  FAILED: {$e->getMessage()}");
                $exit = self::FAILURE;
            }
        }

        return $exit;
    }

    /** @return list<int|string> */
    private function resolveBatchIds(): array
    {
        if ($this->option('all')) {
            return DB::table('import_batches')
                ->whereIn('status', ['validated', 'partially_imported'])
                ->orderBy('id')
                ->pluck('id')
                ->all();
        }
        $batch = $this->argument('batch');

        return $batch !== null ? [$batch] : [];
    }
}
