<?php

namespace Tests\Feature\Concurrency;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\BuildsTestImages;
use Tests\TestCase;

/**
 * Foto Aset — REAL concurrent uploads: two separate PHP processes
 * (tests/Support/asset_photo_upload_worker.php), each sending one
 * `POST /api/assets/{asset}/photos` through the full HTTP kernel against the
 * same MySQL test database, racing for the last free slot of an asset that
 * already has two photos.
 *
 * Why not RefreshDatabase: its wrapping transaction is invisible to other
 * processes. This test commits its own rows under dedicated codes (`ZQ`) and
 * deletes them again in tearDown; it never runs `migrate:fresh`. Both this
 * process (TestCase guard) and every worker refuse to run unless the database
 * is `inventaris_vidatra_test`. Files go to a temporary directory, never to
 * storage/app/private.
 *
 * Two scenarios:
 *  - forced overlap: worker A holds its transaction open (asset row locked)
 *    right after inserting slot 3; worker B is released only once A is inside
 *    that window. B must block on the row lock and then be refused.
 *  - barrier start: both workers released at the same moment, several rounds,
 *    plus a second barrier right before `SELECT … FOR UPDATE`, so both are
 *    demonstrably at the lock step before either requests the lock; whoever
 *    wins, exactly one gets slot 3 and the other a 409/422.
 * Timestamps around the `SELECT … FOR UPDATE` prove the requests overlapped.
 *
 * Run alone: php vendor/bin/phpunit tests/Feature/Concurrency
 * Optional timing evidence: set PHOTO_RACE_REPORT=<file> and each race appends
 * its workers' status and lock timestamps to that file.
 */
class AssetPhotoConcurrencyTest extends TestCase
{
    use BuildsTestImages;

    private const LOC = 'ZQ';

    private const CAT = 'ZQ';

    private const SUB = '903';

    private string $workDir;

    private string $storageRoot;

    private int $assetId;

    private int $userId;

    /** @var list<string> the two pre-existing photos' paths */
    private array $existingPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is not available — real concurrency cannot be tested here.');
        }

        Artisan::call('migrate', ['--force' => true]); // non-destructive
        $this->deleteOwnRows();

        $this->workDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'photo-race-'.Str::random(8);
        $this->storageRoot = $this->workDir.DIRECTORY_SEPARATOR.'disk';
        mkdir($this->storageRoot, 0777, true);

        DB::table('locations')->insert(['code' => self::LOC, 'name' => 'Lokasi Uji Paralel', 'is_active' => 1]);
        DB::table('categories')->insert(['code' => self::CAT, 'name' => 'Kategori Uji Paralel', 'is_active' => 1]);
        DB::table('subcategories')->insert(['category_code' => self::CAT, 'code' => self::SUB, 'name' => 'Subkategori Uji Paralel', 'is_active' => 1]);
        $this->assetId = DB::table('assets')->insertGetId([
            'location_code' => self::LOC, 'category_code' => self::CAT, 'subcategory_code' => self::SUB,
            'sequence_no' => '9950', 'asset_year' => 2024, 'quantity' => 1, 'is_written_off' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->userId = User::factory()->create([
            'role' => UserRole::Operator, 'email' => 'photo-race-'.Str::random(6).'@example.test',
        ])->id;

        foreach ([1, 2] as $slot) {
            $this->existingPaths[] = $this->storePhoto($slot);
        }
    }

    protected function tearDown(): void
    {
        $this->deleteOwnRows();
        if (isset($this->workDir)) {
            $this->removeDirectory($this->workDir);
        }
        $this->cleanUpTestImages();
        parent::tearDown();
    }

    /* ================================================================== scenarios */

    public function test_a_request_forced_into_the_locked_window_waits_for_the_lock_and_is_refused(): void
    {
        $image = $this->tempImagePath($this->jpegBytes(800, 600), '.jpg');
        $signal = $this->workDir.DIRECTORY_SEPARATOR.'a-inserted';

        [$a, $b] = $this->race([
            ['name' => 'A', 'image_path' => $image, 'hold_ms' => 1500, 'signal_file' => $signal],
            ['name' => 'B', 'image_path' => $image, 'wait_for_file' => $signal],
        ]);

        $this->assertSame(201, $a['status'], json_encode($a));
        $this->assertSame(3, $a['body']['data']['slot']);
        $this->assertSame(422, $b['status'], json_encode($b));
        $this->assertStringContainsString('sudah memiliki 3 foto', $b['body']['errors']['photo'][0]);

        // Overlap proof: B asked for the row lock while A still held it, and only
        // got it after A's request (and so its commit) had finished.
        $this->assertLessThan($a['finished_at'], $b['lock_requested_at'], 'B reached the lock while A was still inside its transaction');
        $this->assertGreaterThanOrEqual($a['inserted_at'], $b['lock_requested_at']);
        $this->assertGreaterThan(1.0, $b['lock_acquired_at'] - $b['lock_requested_at'], 'B actually blocked on the row lock');
        $this->assertGreaterThanOrEqual($a['lock_acquired_at'], $b['lock_requested_at']);

        $this->assertConsistentFinalState(winnerPhotoId: $a['body']['data']['id']);
    }

    public function test_simultaneous_uploads_for_the_last_slot_never_exceed_three_photos(): void
    {
        $image = $this->tempImagePath($this->jpegBytes(800, 600), '.jpg');
        $rounds = 3;

        for ($round = 1; $round <= $rounds; $round++) {
            $this->resetToTwoPhotos();

            // second barrier: both workers wait right before `SELECT … FOR UPDATE` until both are there
            $lockBarrier = $this->workDir.DIRECTORY_SEPARATOR."lock-barrier-{$round}";
            mkdir($lockBarrier);

            $results = $this->race([
                ['name' => "A{$round}", 'image_path' => $image, 'lock_barrier_dir' => $lockBarrier, 'lock_barrier_parties' => 2],
                ['name' => "B{$round}", 'image_path' => $image, 'lock_barrier_dir' => $lockBarrier, 'lock_barrier_parties' => 2],
            ]);

            // Both workers reached the lock step: each recorded its arrival there and the
            // moment it sent the FOR UPDATE — measured inside the request, before the query
            // ran, never reconstructed afterwards — in the order the request lifecycle allows.
            foreach ($results as $r) {
                $this->assertIsFloat($r['at_lock_at'] ?? null, "round {$round}: {$r['worker']} reached the lock step");
                $this->assertIsFloat($r['lock_requested_at'] ?? null, "round {$round}: {$r['worker']} sent SELECT … FOR UPDATE");
                $this->assertIsFloat($r['lock_acquired_at'] ?? null, "round {$round}: {$r['worker']} obtained the lock");
                $this->assertLessThanOrEqual($r['at_lock_at'], $r['started_at']);
                $this->assertLessThanOrEqual($r['lock_requested_at'], $r['at_lock_at']);
                $this->assertLessThanOrEqual($r['lock_acquired_at'], $r['lock_requested_at']);
                $this->assertLessThanOrEqual($r['finished_at'], $r['lock_acquired_at']);
            }
            [$x, $y] = $results;
            // Neither had sent its FOR UPDATE (so neither held the lock, inserted or committed)
            // before BOTH had arrived at the lock step: they were in the critical section together.
            $this->assertLessThanOrEqual(
                min($x['lock_requested_at'], $y['lock_requested_at']),
                max($x['at_lock_at'], $y['at_lock_at']),
                "round {$round}: both workers were at the lock step before either requested the lock",
            );

            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame(201, $statuses[0], "round {$round}: ".json_encode($results));
            $this->assertContains($statuses[1], [409, 422], "round {$round}: the loser gets a conflict/validation answer, never 500");

            $this->assertTrue(
                $x['started_at'] < $y['finished_at'] && $y['started_at'] < $x['finished_at'],
                "round {$round}: the two requests' lifetimes overlap",
            );

            $winner = $x['status'] === 201 ? $x : $y;
            $loser = $x['status'] === 201 ? $y : $x;
            $this->assertSame(3, $winner['body']['data']['slot']);
            // the row lock serialised them: the loser only got it after the winner
            $this->assertGreaterThanOrEqual($winner['lock_acquired_at'], $loser['lock_acquired_at']);
            $this->assertConsistentFinalState(winnerPhotoId: $winner['body']['data']['id']);
        }
    }

    /* ================================================================== helpers */

    /**
     * Starts one worker process per job, opens the barrier once all of them have
     * booted, waits for all of them and returns their results (input order).
     *
     * @param  list<array<string, mixed>>  $jobs
     * @return list<array<string, mixed>>
     */
    private function race(array $jobs): array
    {
        $go = $this->workDir.DIRECTORY_SEPARATOR.'go-'.Str::random(6);
        $worker = base_path('tests/Support/asset_photo_upload_worker.php');
        $processes = [];

        foreach ($jobs as $i => $job) {
            $tag = $job['name'].'-'.Str::random(6);
            $job += [
                'asset_id' => $this->assetId,
                'user_id' => $this->userId,
                'storage_root' => $this->storageRoot,
                'ready_file' => $this->workDir.DIRECTORY_SEPARATOR."ready-{$tag}",
                'go_file' => $go,
                'result_file' => $this->workDir.DIRECTORY_SEPARATOR."result-{$tag}.json",
            ];
            $jobFile = $this->workDir.DIRECTORY_SEPARATOR."job-{$tag}.json";
            file_put_contents($jobFile, json_encode($job));

            $log = $this->workDir.DIRECTORY_SEPARATOR."out-{$tag}.log";
            $env = array_merge(getenv(), ['APP_ENV' => 'testing']);
            $process = proc_open([PHP_BINARY, $worker, $jobFile], [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, base_path(), $env);
            if (! is_resource($process)) {
                throw new RuntimeException('could not start worker');
            }
            $processes[$i] = ['process' => $process, 'job' => $job, 'log' => $log];
        }

        $results = [];
        try {
            $this->waitUntil(fn () => array_reduce($processes, fn ($ok, $p) => $ok && file_exists($p['job']['ready_file']), true), 'workers ready');
            touch($go);

            foreach ($processes as $i => $p) {
                $this->waitUntil(fn () => ! proc_get_status($p['process'])['running'], "worker {$p['job']['name']} finished");
                $this->assertFileExists($p['job']['result_file'], 'worker output: '.@file_get_contents($p['log']));
                $results[$i] = json_decode((string) file_get_contents($p['job']['result_file']), true);
                $this->assertArrayNotHasKey('fatal', $results[$i], json_encode($results[$i]));
                $this->assertNotSame(500, $results[$i]['status'], json_encode($results[$i]));
            }
        } finally {
            // never leave a worker behind (e.g. one still waiting after the other failed)
            foreach ($processes as $p) {
                if (proc_get_status($p['process'])['running']) {
                    proc_terminate($p['process']);
                }
                proc_close($p['process']);
            }
        }

        $this->reportTimings(array_values($results));

        return array_values($results);
    }

    /** @param  list<array<string, mixed>>  $results */
    private function reportTimings(array $results): void
    {
        $file = getenv('PHOTO_RACE_REPORT');
        if (! is_string($file) || $file === '') {
            return;
        }

        $t0 = min(array_column($results, 'started_at'));
        $ms = fn ($t) => $t === null ? '-' : sprintf('%7.1f', ($t - $t0) * 1000);
        $lines = [$this->name()];
        foreach ($results as $r) {
            $lines[] = sprintf(
                '  %-3s pid=%-6d status=%d  start=%s  at_lock=%s  lock_requested=%s  lock_acquired=%s  finished=%s  (ms, relative)',
                $r['worker'], $r['pid'], $r['status'], $ms($r['started_at']), $ms($r['at_lock_at'] ?? null), $ms($r['lock_requested_at'] ?? null),
                $ms($r['lock_acquired_at'] ?? null), $ms($r['finished_at']),
            );
        }
        file_put_contents($file, implode(PHP_EOL, $lines).PHP_EOL, FILE_APPEND);
    }

    private function assertConsistentFinalState(int $winnerPhotoId): void
    {
        $rows = DB::table('asset_photos')->where('asset_id', $this->assetId)->orderBy('slot')->get();

        $this->assertSame([1, 2, 3], $rows->pluck('slot')->map(fn ($s) => (int) $s)->all(), 'never more than three photos');
        $this->assertSame($winnerPhotoId, (int) $rows[2]->id);

        $onDisk = $this->filesOnDisk();
        $this->assertEqualsCanonicalizing($rows->pluck('path')->all(), $onDisk, "the loser's file is gone, nobody else's file was deleted");
        foreach ($this->existingPaths as $path) {
            $this->assertContains($path, $onDisk, 'the two pre-existing photos are untouched');
        }
        $this->assertSame(1, DB::table('mutation_logs')->where('asset_id', $this->assetId)->where('event_type', 'PHOTO_ADDED')->count());
    }

    private function resetToTwoPhotos(): void
    {
        foreach (DB::table('asset_photos')->where('asset_id', $this->assetId)->where('slot', '>', 2)->pluck('path') as $path) {
            @unlink($this->storageRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path));
        }
        DB::table('asset_photos')->where('asset_id', $this->assetId)->where('slot', '>', 2)->delete();
        DB::table('mutation_logs')->where('asset_id', $this->assetId)->delete();
        $this->assertSame(2, DB::table('asset_photos')->where('asset_id', $this->assetId)->count());
    }

    private function storePhoto(int $slot): string
    {
        $path = "asset-photos/{$this->assetId}/".Str::uuid().'.jpg';
        $absolute = $this->storageRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        @mkdir(dirname($absolute), 0777, true);
        $bytes = $this->jpegBytes(120, 90);
        file_put_contents($absolute, $bytes);
        DB::table('asset_photos')->insert([
            'asset_id' => $this->assetId, 'slot' => $slot, 'path' => $path, 'mime' => 'image/jpeg',
            'size_bytes' => strlen($bytes), 'width' => 120, 'height' => 90, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $path;
    }

    /** @return list<string> relative paths under the temporary disk root */
    private function filesOnDisk(): array
    {
        $dir = $this->storageRoot.DIRECTORY_SEPARATOR.'asset-photos'.DIRECTORY_SEPARATOR.$this->assetId;
        $files = is_dir($dir) ? array_values(array_diff(scandir($dir), ['.', '..'])) : [];

        return array_map(fn (string $f) => "asset-photos/{$this->assetId}/{$f}", $files);
    }

    private function waitUntil(callable $condition, string $what, float $timeout = 90.0): void
    {
        $deadline = microtime(true) + $timeout;
        while (! $condition()) {
            if (microtime(true) > $deadline) {
                $this->fail("Timed out waiting for: {$what}");
            }
            usleep(10000);
        }
    }

    private function deleteOwnRows(): void
    {
        $assetIds = DB::table('assets')->where('location_code', self::LOC)->pluck('id');
        DB::table('asset_photos')->whereIn('asset_id', $assetIds)->delete();
        DB::table('mutation_logs')->whereIn('asset_id', $assetIds)->delete();
        DB::table('assets')->whereIn('id', $assetIds)->delete();
        DB::table('subcategories')->where('category_code', self::CAT)->delete();
        DB::table('categories')->where('code', self::CAT)->delete();
        DB::table('locations')->where('code', self::LOC)->delete();
        DB::table('users')->where('email', 'like', 'photo-race-%@example.test')->delete();
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) && ! is_link($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
