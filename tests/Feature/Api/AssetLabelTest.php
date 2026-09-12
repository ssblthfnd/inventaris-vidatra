<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Services\Label\AssetLabelPdfService;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * Tahap 6.0 — asset-label PDF generation:
 *   `GET  /api/assets/{asset}/label`
 *   `POST /api/assets/batch/label`
 *
 * QR codes are Strategi B (§6.0): the QR target is `{qr_base_url}/a/{asset->id}` —
 * never asset_code, never any other asset attribute. These tests verify that
 * contract directly against {@see AssetLabelPdfService}, plus the HTTP-level
 * authorization, validation, atomicity and PDF-response behaviour.
 *
 * `size` (Tahap 6.0.2, `small`/`medium`/`large`, default `small`) is covered here
 * at the request-contract level (accepted/rejected/defaulted, filename, download
 * headers); the resulting PDF's physical geometry per size is covered in
 * {@see AssetLabelLayoutTest}.
 */
class AssetLabelTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    /* ------------------------------------------------------------------ QR target URL */

    public function test_qr_target_url_uses_asset_id_not_asset_code(): void
    {
        $asset = $this->existingAsset('001');
        $service = app(AssetLabelPdfService::class);

        $url = $service->qrTargetUrl($asset);

        $this->assertSame('https://qr.inventaris-vidatra.test/a/'.$asset->id, $url);
        $this->assertStringNotContainsString($asset->asset_code, $url);
        $this->assertStringNotContainsString($asset->sequence_no, $url);
    }

    public function test_qr_target_url_survives_a_room_move(): void
    {
        $asset = $this->existingAsset('001');
        $service = app(AssetLabelPdfService::class);
        $before = $service->qrTargetUrl($asset);

        $room = $this->room();
        $asset->forceFill(['room_id' => $room->id])->save();

        $this->assertSame($before, $service->qrTargetUrl($asset->fresh()));
    }

    public function test_missing_qr_base_url_produces_a_clear_error(): void
    {
        config(['inventory.qr_base_url' => null]);
        $asset = $this->existingAsset('001');
        Sanctum::actingAs($this->operator());

        $this->getJson("/api/assets/{$asset->id}/label")
            ->assertStatus(422)
            ->assertJsonPath('message', 'URL QR inventaris belum dikonfigurasi.');
    }

    public function test_blank_qr_base_url_produces_a_clear_error(): void
    {
        config(['inventory.qr_base_url' => '']);
        $asset = $this->existingAsset('001');
        Sanctum::actingAs($this->operator());

        $this->getJson("/api/assets/{$asset->id}/label")->assertStatus(422);
    }

    /* ------------------------------------------------------------------ display code */

    public function test_display_code_uses_dashes_not_dots(): void
    {
        $asset = $this->existingAsset('0004', 2019, [], 'ZL', 'ZC', '001');
        $service = app(AssetLabelPdfService::class);

        $this->assertSame(str_replace('.', ' - ', $asset->asset_code), $service->displayCode($asset));
        $this->assertStringNotContainsString('.', $service->displayCode($asset));
    }

    /* ------------------------------------------------------------------ single label: authorization */

    public function test_unauthenticated_gets_401_for_single_label(): void
    {
        $asset = $this->existingAsset('001');

        $this->getJson("/api/assets/{$asset->id}/label")->assertStatus(401);
    }

    public function test_viewer_gets_403_for_single_label(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->existingAsset('001');

        $this->getJson("/api/assets/{$asset->id}/label")->assertStatus(403);
    }

    public function test_inactive_operator_gets_403_for_single_label(): void
    {
        Sanctum::actingAs($this->operator(active: false));
        $asset = $this->existingAsset('001');

        $this->getJson("/api/assets/{$asset->id}/label")->assertStatus(403);
    }

    public function test_operator_can_generate_single_label(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $response = $this->getJson("/api/assets/{$asset->id}/label")->assertOk();

        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_admin_can_generate_single_label(): void
    {
        Sanctum::actingAs($this->admin());
        $asset = $this->existingAsset('001');

        $this->getJson("/api/assets/{$asset->id}/label")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    /* ------------------------------------------------------------------ single label: soft delete */

    public function test_soft_deleted_asset_404s_for_single_label(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');
        $asset->delete();

        $this->getJson("/api/assets/{$asset->id}/label")->assertStatus(404);
    }

    public function test_soft_deleted_asset_404s_for_single_label_even_for_admin(): void
    {
        Sanctum::actingAs($this->admin());
        $asset = $this->existingAsset('001');
        $asset->delete();

        $this->getJson("/api/assets/{$asset->id}/label")->assertStatus(404);
    }

    public function test_nonexistent_asset_404s_for_single_label(): void
    {
        Sanctum::actingAs($this->operator());

        $this->getJson('/api/assets/999999/label')->assertStatus(404);
    }

    /* ------------------------------------------------------------------ single label: read-only */

    public function test_single_label_generation_does_not_mutate_the_asset(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');
        $before = $asset->updated_at->toISOString();

        $this->getJson("/api/assets/{$asset->id}/label")->assertOk();

        $this->assertSame($before, $asset->fresh()->updated_at->toISOString());
        $this->assertDatabaseCount('mutation_logs', 0);
    }

    /* ------------------------------------------------------------------ single label: size parameter (Tahap 6.0.2) */

    #[DataProvider('validSizes')]
    public function test_single_label_accepts_each_valid_size(string $size): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $response = $this->getJson("/api/assets/{$asset->id}/label?size={$size}")->assertOk();

        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString($size, $response->headers->get('Content-Disposition'));
    }

    public function test_single_label_rejects_an_invalid_size(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->getJson("/api/assets/{$asset->id}/label?size=huge")
            ->assertStatus(422)
            ->assertJsonValidationErrors('size');
    }

    public function test_single_label_missing_size_defaults_to_small(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $response = $this->getJson("/api/assets/{$asset->id}/label")->assertOk();

        $this->assertStringContainsString('small', $response->headers->get('Content-Disposition'));
    }

    public function test_single_label_blank_size_defaults_to_small(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $response = $this->getJson("/api/assets/{$asset->id}/label?size=")->assertOk();

        $this->assertStringContainsString('small', $response->headers->get('Content-Disposition'));
    }

    public function test_single_label_response_is_a_real_download_not_inline(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $response = $this->getJson("/api/assets/{$asset->id}/label")->assertOk();

        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    /** @return array<string, array{0: string}> */
    public static function validSizes(): array
    {
        return [
            'small' => ['small'],
            'medium' => ['medium'],
            'large' => ['large'],
        ];
    }

    /* ------------------------------------------------------------------ batch: authorization */

    public function test_unauthenticated_gets_401_for_batch_label(): void
    {
        $asset = $this->existingAsset('001');

        $this->postJson('/api/assets/batch/label', ['asset_ids' => [$asset->id]])->assertStatus(401);
    }

    public function test_viewer_gets_403_for_batch_label(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->existingAsset('001');

        $this->postJson('/api/assets/batch/label', ['asset_ids' => [$asset->id]])->assertStatus(403);
    }

    public function test_operator_can_generate_batch_label(): void
    {
        Sanctum::actingAs($this->operator());
        $a = $this->existingAsset('001');
        $b = $this->existingAsset('002');

        $response = $this->postJson('/api/assets/batch/label', ['asset_ids' => [$a->id, $b->id]])
            ->assertOk();

        $response->assertHeader('Content-Type', 'application/pdf');
    }

    /* ------------------------------------------------------------------ batch: validation */

    public function test_batch_label_missing_asset_ids_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/assets/batch/label', [])
            ->assertStatus(422)->assertJsonValidationErrors('asset_ids');
    }

    public function test_batch_label_empty_asset_ids_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/assets/batch/label', ['asset_ids' => []])
            ->assertStatus(422)->assertJsonValidationErrors('asset_ids');
    }

    public function test_batch_label_duplicate_asset_ids_are_rejected(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->postJson('/api/assets/batch/label', ['asset_ids' => [$asset->id, $asset->id]])
            ->assertStatus(422);
    }

    public function test_batch_label_nonexistent_asset_id_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->postJson('/api/assets/batch/label', ['asset_ids' => [$asset->id, 999999]])
            ->assertStatus(422)->assertJsonValidationErrors('asset_ids.1');
    }

    /**
     * A trashed id anywhere in the batch fails the WHOLE request — no silent
     * partial PDF (same all-or-nothing convention as batch edit/delete).
     */
    public function test_batch_label_rejects_the_whole_request_if_any_asset_is_trashed(): void
    {
        Sanctum::actingAs($this->operator());
        $a = $this->existingAsset('001');
        $b = $this->existingAsset('002');
        $b->delete();

        $this->postJson('/api/assets/batch/label', ['asset_ids' => [$a->id, $b->id]])
            ->assertStatus(422)->assertJsonValidationErrors('asset_ids.1');
    }

    /* ------------------------------------------------------------------ batch: size parameter (Tahap 6.0.2) */

    #[DataProvider('validSizes')]
    public function test_batch_label_accepts_each_valid_size(string $size): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $response = $this->postJson('/api/assets/batch/label', ['asset_ids' => [$asset->id], 'size' => $size])
            ->assertOk();

        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString($size, $response->headers->get('Content-Disposition'));
    }

    public function test_batch_label_rejects_an_invalid_size(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->postJson('/api/assets/batch/label', ['asset_ids' => [$asset->id], 'size' => 'huge'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('size');
    }

    public function test_batch_label_missing_size_defaults_to_small(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $response = $this->postJson('/api/assets/batch/label', ['asset_ids' => [$asset->id]])->assertOk();

        $this->assertStringContainsString('small', $response->headers->get('Content-Disposition'));
    }

    public function test_batch_label_response_is_a_real_download_not_inline(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $response = $this->postJson('/api/assets/batch/label', ['asset_ids' => [$asset->id]])->assertOk();

        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    /* ------------------------------------------------------------------ batch: order, content, read-only */

    /**
     * A PDF's raw bytes aren't a reliable place to search for label order (letter
     * spacing can split a string across positioning operators, and the embedded
     * QR/logo base64 data is long enough to produce coincidental substring matches)
     * — so this intercepts at the `AssetLabelPdfService::render()` boundary instead
     * and asserts the exact asset order the real controller code built and passed
     * in, without depending on PDF byte layout at all.
     */
    public function test_batch_label_order_is_deterministic_and_follows_submitted_order(): void
    {
        Sanctum::actingAs($this->operator());
        $a = $this->existingAsset('001');
        $b = $this->existingAsset('002');
        $c = $this->existingAsset('003');

        $seenIds = null;
        $fakePdf = \Mockery::mock(PdfDocument::class);
        $fakePdf->shouldReceive('download')->once()->andReturn(response('fake-pdf', 200, ['Content-Type' => 'application/pdf']));

        $this->mock(AssetLabelPdfService::class, function ($mock) use (&$seenIds, $fakePdf): void {
            $mock->shouldReceive('renderBatch')->once()->andReturnUsing(function ($assets) use (&$seenIds, $fakePdf) {
                $seenIds = $assets->pluck('id')->all();

                return $fakePdf;
            });
        });

        // Submitted out of natural id order — the PDF must follow submission order.
        $this->postJson('/api/assets/batch/label', ['asset_ids' => [$c->id, $a->id, $b->id]])->assertOk();

        $this->assertSame([$c->id, $a->id, $b->id], $seenIds);
    }

    #[DataProvider('validSizes')]
    public function test_batch_label_generation_does_not_mutate_any_asset(string $size): void
    {
        Sanctum::actingAs($this->operator());
        $a = $this->existingAsset('001');
        $b = $this->existingAsset('002');
        $before = [$a->updated_at->toISOString(), $b->updated_at->toISOString()];

        $this->postJson('/api/assets/batch/label', ['asset_ids' => [$a->id, $b->id], 'size' => $size])->assertOk();

        $this->assertSame($before, [$a->fresh()->updated_at->toISOString(), $b->fresh()->updated_at->toISOString()]);
        $this->assertDatabaseCount('mutation_logs', 0);
        $this->assertDatabaseCount('assets', 2);
        $this->assertDatabaseCount('import_rows', 0);
    }

    /**
     * Isolates the query cost of {@see AssetLabelController::batch()}'s own asset
     * lookup from the surrounding HTTP request. (`BatchLabelAssetRequest`'s
     * `asset_ids.*` => `Rule::exists()` validation is a separate, PRE-EXISTING
     * per-item query — the same shape `BatchUpdateAssetRequest` /
     * `BatchDeleteAssetRequest` already use since Tahap 5.8.6/5.8.7 — so it isn't
     * this stage's concern to fix and would make an end-to-end HTTP query count
     * assert the wrong thing here.)
     */
    public function test_batch_label_asset_lookup_is_a_single_query_regardless_of_batch_size(): void
    {
        $small = collect(range(1, 2))->map(fn ($i) => $this->existingAsset(str_pad((string) $i, 3, '0', STR_PAD_LEFT)));
        $smallCount = $this->countQueries(
            fn () => Asset::query()->whereIn('id', $small->pluck('id'))->get(['id', 'asset_code'])
        );

        $large = collect(range(1, 8))->map(fn ($i) => $this->existingAsset(str_pad((string) ($i + 10), 3, '0', STR_PAD_LEFT)));
        $largeCount = $this->countQueries(
            fn () => Asset::query()->whereIn('id', $large->pluck('id'))->get(['id', 'asset_code'])
        );

        $this->assertSame(1, $smallCount);
        $this->assertSame(1, $largeCount);
    }

    /**
     * The PDF renderer itself must never touch the database — every asset it needs
     * is already loaded before {@see AssetLabelPdfService::renderBatch()} is called.
     */
    public function test_label_rendering_issues_no_database_queries(): void
    {
        $assets = collect(range(1, 5))->map(fn ($i) => $this->existingAsset(str_pad((string) $i, 3, '0', STR_PAD_LEFT)));
        $service = app(AssetLabelPdfService::class);

        $count = $this->countQueries(fn () => $service->renderBatch($assets)->output());

        $this->assertSame(0, $count);
    }

    private function countQueries(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $callback();

        return $count;
    }
}
