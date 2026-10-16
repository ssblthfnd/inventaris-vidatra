<?php

/*
|--------------------------------------------------------------------------
| Foto Aset — concurrency test worker (NOT a test case, never run directly)
|--------------------------------------------------------------------------
|
| Started as a separate PHP process by
| Tests\Feature\Concurrency\AssetPhotoConcurrencyTest. Boots the real
| application with APP_ENV=testing (→ .env.testing → inventaris_vidatra_test),
| refuses to run against any other database, points the `local` disk at the
| test's own temporary directory, then sends ONE real
| `POST /api/assets/{asset}/photos` through the HTTP kernel (routing,
| middleware, FormRequest, controller, AssetPhotoService, MySQL locks) as the
| given user, and writes what happened to a JSON result file.
|
| Coordination with the other worker is file-based (no extra dependency):
|   - `ready_file` is created once booted; the worker then waits for `go_file`
|     (a barrier the test opens when every worker is ready);
|   - `wait_for_file`: additionally wait until this file exists before sending;
|   - `hold_ms` + `signal_file`: right after inserting its photo row (inside the
|     transaction, asset row still locked) touch `signal_file` and sleep —
|     forcing the other worker's request into the locked window;
|   - `lock_barrier_dir` + `lock_barrier_parties`: a second barrier right
|     before the `SELECT … FOR UPDATE` — every worker waits there until all
|     have arrived, so all of them demonstrably reach the lock step.
| Timestamps (microtime) are recorded around the `SELECT … FOR UPDATE` on
| `assets`, so the test can prove that the two requests really overlapped.
|
| Usage: php asset_photo_upload_worker.php <job.json>
*/

use App\Models\AssetPhoto;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$job = json_decode((string) file_get_contents($argv[1] ?? ''), true, flags: JSON_THROW_ON_ERROR);
$result = ['worker' => $job['name'], 'pid' => getmypid()];
$write = static function () use (&$result, $job): void {
    file_put_contents($job['result_file'], json_encode($result, JSON_PRETTY_PRINT));
};
$waitFor = static function (string $file, float $timeout = 60.0): bool {
    $deadline = microtime(true) + $timeout;
    while (! file_exists($file)) {
        if (microtime(true) > $deadline) {
            return false;
        }
        usleep(2000);
    }

    return true;
};

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);

// The request exists before bootstrap (as in public/index.php); it is only sent later.
$file = new UploadedFile($job['image_path'], 'foto.jpg', 'image/jpeg', null, true);
$request = Request::create("/api/assets/{$job['asset_id']}/photos", 'POST', [], [], ['photo' => $file], ['HTTP_ACCEPT' => 'application/json']);
$app->instance('request', $request);
$kernel->bootstrap();

$database = config('database.connections.'.config('database.default').'.database');
if ($database !== 'inventaris_vidatra_test') {
    $result['fatal'] = "refusing to run against database [{$database}]";
    $write();
    exit(3);
}

config(['filesystems.disks.local.root' => $job['storage_root']]);
Storage::forgetDisk('local');

// Runs immediately BEFORE the `SELECT … FROM assets … FOR UPDATE` is sent, i.e.
// before this request can hold the row lock. With `lock_barrier_dir`, every worker
// also announces it has reached this point (`at_lock_at`, file `at-lock-<name>`) and
// waits until all `lock_barrier_parties` workers have — so all of them are at the
// lock step together, none holding the lock yet (coordination, not a fixed delay).
DB::connection()->beforeExecuting(function (string $query) use (&$result, $job): void {
    if (! str_contains($query, 'from `assets`') || ! str_contains($query, 'for update') || isset($result['lock_requested_at'])) {
        return;
    }

    if (! empty($job['lock_barrier_dir'])) {
        $result['at_lock_at'] = microtime(true);
        touch($job['lock_barrier_dir'].DIRECTORY_SEPARATOR.'at-lock-'.$job['name']);
        $deadline = microtime(true) + 30;
        while (count(glob($job['lock_barrier_dir'].DIRECTORY_SEPARATOR.'at-lock-*') ?: []) < (int) $job['lock_barrier_parties']) {
            if (microtime(true) > $deadline) {
                $result['fatal'] = 'lock barrier timeout (the other worker never reached the lock step)';

                throw new RuntimeException($result['fatal']);
            }
            usleep(1000);
        }
    }

    $result['lock_requested_at'] = microtime(true);
});
DB::listen(function ($query) use (&$result): void {
    if (str_contains($query->sql, 'from `assets`') && str_contains($query->sql, 'for update')) {
        $result['lock_acquired_at'] = microtime(true);
    }
});

if (($job['hold_ms'] ?? 0) > 0) {
    AssetPhoto::created(function () use ($job, &$result): void {
        $result['inserted_at'] = microtime(true);
        touch($job['signal_file']);
        usleep($job['hold_ms'] * 1000); // keep the transaction (and the asset row lock) open
    });
}

$user = User::query()->findOrFail($job['user_id']);

touch($job['ready_file']);
if (! $waitFor($job['go_file'])) {
    $result['fatal'] = 'barrier timeout';
    $write();
    exit(4);
}
if (! empty($job['wait_for_file']) && ! $waitFor($job['wait_for_file'])) {
    $result['fatal'] = 'signal timeout';
    $write();
    exit(4);
}

$app['auth']->guard('sanctum')->setUser($user);

$result['started_at'] = microtime(true);
$response = $kernel->handle($request);
$result['finished_at'] = microtime(true);
$kernel->terminate($request, $response);

$result['status'] = $response->getStatusCode();
$result['body'] = json_decode((string) $response->getContent(), true);
$write();
exit(0);
