<?php

namespace Tests\Feature\Api;

use App\Import\ImportManager;
use App\Models\ImportBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\Concerns\InteractsWithImportFixtures;
use Tests\TestCase;

/**
 * Tahap 6.1 — Import Excel UI authorization matrix.
 *
 * Every import endpoint reuses the SAME `can:operator` gate the asset write API and
 * label printing already use (docs/api_convention.md §3: "operator — READ + write
 * assets / mutations / imports / room aliases"): admin and operator are both allowed,
 * viewer is forbidden, unauthenticated is 401. No import-specific authorization logic
 * exists anywhere — this file only proves the existing gate is actually wired to
 * every new route.
 */
class ImportAuthorizationTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use InteractsWithImportFixtures;
    use RefreshDatabase;

    /**
     * Stages a batch directly through {@see ImportManager} (no HTTP round trip, so
     * no authenticated user is required) — used to have a real `{batch}` id to probe
     * every sub-endpoint against, independent of who is "logged in" for a given test.
     */
    private function existingBatch(): ImportBatch
    {
        $this->scope('01', '02', '001');
        $upload = $this->makeImportUpload('02', [$this->validImportRowCells()]);
        $summary = app(ImportManager::class)->stageFile($upload->getPathname());

        return ImportBatch::findOrFail($summary['batch_id']);
    }

    /* ------------------------------------------------------------------ unauthenticated */

    public function test_unauthenticated_gets_401_for_every_import_endpoint(): void
    {
        $batch = $this->existingBatch();

        $this->getJson('/api/imports/template?category=02')->assertStatus(401);
        $this->getJson('/api/imports')->assertStatus(401);
        $this->getJson("/api/imports/{$batch->id}")->assertStatus(401);
        $this->getJson("/api/imports/{$batch->id}/rows")->assertStatus(401);
        $this->getJson("/api/imports/{$batch->id}/report")->assertStatus(401);
        $this->postJson("/api/imports/{$batch->id}/promote")->assertStatus(401);
    }

    /* ------------------------------------------------------------------ viewer forbidden */

    public function test_viewer_is_forbidden_from_every_import_endpoint(): void
    {
        $batch = $this->existingBatch();
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/imports/template?category=02')->assertStatus(403);
        $this->getJson('/api/imports')->assertStatus(403);
        $this->getJson("/api/imports/{$batch->id}")->assertStatus(403);
        $this->getJson("/api/imports/{$batch->id}/rows")->assertStatus(403);
        $this->getJson("/api/imports/{$batch->id}/report")->assertStatus(403);
        $this->postJson("/api/imports/{$batch->id}/promote")->assertStatus(403);
    }

    /* ------------------------------------------------------------------ operator allowed */

    public function test_operator_can_access_every_import_endpoint(): void
    {
        $batch = $this->existingBatch();
        Sanctum::actingAs($this->operator());

        $this->get('/api/imports/template?category=02')->assertOk();
        $this->getJson('/api/imports')->assertOk();
        $this->getJson("/api/imports/{$batch->id}")->assertOk();
        $this->getJson("/api/imports/{$batch->id}/rows")->assertOk();
        $this->getJson("/api/imports/{$batch->id}/report")->assertOk();
        $this->postJson("/api/imports/{$batch->id}/promote")->assertOk();
    }

    /* ------------------------------------------------------------------ admin allowed */

    public function test_admin_can_access_every_import_endpoint(): void
    {
        $batch = $this->existingBatch();
        Sanctum::actingAs($this->admin());

        $this->get('/api/imports/template?category=02')->assertOk();
        $this->getJson('/api/imports')->assertOk();
        $this->getJson("/api/imports/{$batch->id}")->assertOk();
        $this->getJson("/api/imports/{$batch->id}/rows")->assertOk();
        $this->getJson("/api/imports/{$batch->id}/report")->assertOk();
        $this->postJson("/api/imports/{$batch->id}/promote")->assertOk();
    }

    public function test_inactive_operator_is_forbidden(): void
    {
        Sanctum::actingAs($this->operator(active: false));

        $this->getJson('/api/imports')->assertStatus(403);
    }
}
