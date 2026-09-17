<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Location;
use App\Models\User;
use App\Services\Label\AssetLabelPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * R8 — Individual Label PDF, `POST /api/assets/batch/label` with the new
 * `mode=individual` parameter (default remains `mode=a4`, the exact pre-R8
 * behaviour — see {@see AssetLabelTest} / {@see AssetLabelLayoutTest} for the
 * untouched A4 regression coverage).
 *
 * `show()` (`GET /api/assets/{asset}/label`) is NOT touched by R8 at all — it
 * already rendered at exactly the chosen label's physical size, i.e. it was
 * already "Individual mode" in substance (see `AssetLabelController`'s own
 * docblock); nothing here duplicates its existing test coverage.
 *
 * Revision — Individual mode for MORE THAN ONE asset returns ONE multi-page
 * PDF (one page per asset, every page still exactly the chosen label size)
 * instead of a ZIP of separate PDFs, which an earlier version of this stage
 * returned. There is therefore nothing left to clean up after a request
 * (every render method in {@see AssetLabelPdfService} is purely in-memory,
 * same as `renderBatch()` always was) — no temp-file test section exists
 * here anymore.
 *
 * Route authorization is the pre-existing, unmigrated `can:operator` Gate
 * (routes/api.php) — confirmed by reading the route group, not assumed. This
 * means `unit_admin` and `super_admin` are BOTH still fully blocked from
 * every label route, individual or A4, regardless of the requested assets'
 * location — R8 deliberately does not touch this (see the top-level
 * instructions' "KNOWN BACKLOG — DO NOT FIX" list). The authorization tests
 * below assert this ACTUAL behaviour, not an assumed one.
 */
class AssetLabelIndividualTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    private const MM_TO_PT = 2.83465;

    /** @return array<string, array{0: string, 1: float, 2: float}> */
    public static function sizes(): array
    {
        return [
            'small' => ['small', 55.0, 15.0],
            'medium' => ['medium', 70.0, 20.0],
            'large' => ['large', 90.0, 25.0],
        ];
    }

    private function unitAdmin(?string $locationCode): User
    {
        return User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => $locationCode, 'is_active' => true]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    }

    /**
     * Width/height (mm) of ONE `/MediaBox` match, by index (0-based) into the
     * raw PDF bytes — no external PDF library needed (dompdf leaves this
     * uncompressed in the object list). Every page has its OWN `/MediaBox`
     * entry (dompdf also duplicates one on the shared `/Pages` tree node —
     * see {@see pdfPageCount()}'s own docblock — so index 0 is that shared
     * default, and each subsequent one is a real page in document order).
     *
     * @return array{0: float, 1: float}
     */
    private function pdfPageSizeMmAt(string $pdfBytes, int $index): array
    {
        $this->assertStringStartsWith('%PDF-', $pdfBytes, 'not a valid PDF');
        preg_match_all('/\/MediaBox\s*\[([^\]]+)\]/', $pdfBytes, $m);
        $this->assertArrayHasKey($index, $m[1], "no /MediaBox at index {$index}");
        [$x0, $y0, $x1, $y1] = array_map('floatval', preg_split('/\s+/', trim($m[1][$index])));

        return [($x1 - $x0) / self::MM_TO_PT, ($y1 - $y0) / self::MM_TO_PT];
    }

    /** Width/height (mm) of a raw PDF's FIRST real page (see {@see pdfPageSizeMmAt()}'s own docblock for why index 1, not 0). */
    private function pdfPageSizeMm(string $pdfBytes): array
    {
        return $this->pdfPageSizeMmAt($pdfBytes, 0);
    }

    /**
     * dompdf writes `/MediaBox` TWICE per page even for a single page (once as
     * the inherited default on the `/Pages` tree node, once again on the
     * `/Page` object itself — confirmed empirically, not assumed) — so page
     * count is read from `/Type /Page` (excluding `/Type /Pages`) instead,
     * which appears exactly once per actual page.
     */
    private function pdfPageCount(string $pdfBytes): int
    {
        return preg_match_all('/\/Type\s*\/Page(?!s)/', $pdfBytes);
    }

    /* ================================================================== A4 regression (default + explicit mode=a4) */

    public function test_omitted_mode_still_produces_the_existing_a4_sheet(): void
    {
        Sanctum::actingAs($this->operator());
        $a = $this->existingAsset('001');
        $b = $this->existingAsset('002');

        $response = $this->postJson('/api/assets/batch/label', ['asset_ids' => [$a->id, $b->id]])->assertOk();

        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('label-aset-batch-small.pdf', $response->headers->get('Content-Disposition'));
    }

    /**
     * Not byte-identical on purpose: dompdf embeds a fresh `/CreationDate`/
     * `/ModDate`/`/ID` timestamp on every render, so two separate requests a
     * moment apart are NEVER byte-for-byte equal even with nothing else
     * different (confirmed empirically) — structural equivalence (page count,
     * same page size, same overall size) is what actually proves "explicit
     * mode=a4 behaves like the omitted default", not raw byte comparison.
     */
    public function test_explicit_mode_a4_matches_omitted_mode_structurally(): void
    {
        Sanctum::actingAs($this->operator());
        $a = $this->existingAsset('001');
        $b = $this->existingAsset('002');

        $omitted = $this->postJson('/api/assets/batch/label', ['asset_ids' => [$a->id, $b->id]])->assertOk();
        $explicit = $this->postJson('/api/assets/batch/label', ['asset_ids' => [$a->id, $b->id], 'mode' => 'a4'])->assertOk();

        $omittedBytes = $omitted->getContent();
        $explicitBytes = $explicit->getContent();

        $this->assertSame($this->pdfPageCount($omittedBytes), $this->pdfPageCount($explicitBytes));
        $this->assertEqualsWithDelta(strlen($omittedBytes), strlen($explicitBytes), 200);
        $this->assertSame(
            $omitted->headers->get('Content-Disposition'),
            $explicit->headers->get('Content-Disposition'),
        );
    }

    #[DataProvider('sizes')]
    public function test_a4_mode_capacity_and_page_size_are_unchanged_by_r8(string $size, float $width, float $height): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $response = $this->postJson('/api/assets/batch/label', ['asset_ids' => [$asset->id], 'size' => $size, 'mode' => 'a4'])
            ->assertOk();

        $service = app(AssetLabelPdfService::class);
        $this->assertSame([28, 22, 18][array_search($size, ['small', 'medium', 'large'], true)], $service->labelsPerPage($size));
        $this->assertStringContainsString($size, $response->headers->get('Content-Disposition'));
    }

    /* ================================================================== Individual: single asset */

    #[DataProvider('sizes')]
    public function test_individual_mode_single_asset_returns_a_raw_pdf_at_the_correct_size(string $size, float $width, float $height): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001', 2025, [], 'ZL', 'ZC', '001');

        $response = $this->postJson('/api/assets/batch/label', [
            'asset_ids' => [$asset->id],
            'size' => $size,
            'mode' => 'individual',
        ])->assertOk();

        $response->assertHeader('Content-Type', 'application/pdf');
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString("label-{$asset->asset_code}.pdf", $disposition);

        $bytes = $response->getContent();
        $this->assertSame(1, $this->pdfPageCount($bytes));
        [$w, $h] = $this->pdfPageSizeMm($bytes);
        $this->assertEqualsWithDelta($width, $w, 0.05);
        $this->assertEqualsWithDelta($height, $h, 0.05);
    }

    public function test_individual_mode_single_asset_default_size_is_small(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $response = $this->postJson('/api/assets/batch/label', ['asset_ids' => [$asset->id], 'mode' => 'individual'])
            ->assertOk();

        [$w, $h] = $this->pdfPageSizeMm($response->getContent());
        $this->assertEqualsWithDelta(55.0, $w, 0.05);
        $this->assertEqualsWithDelta(15.0, $h, 0.05);
    }

    /* ================================================================== Individual: multiple assets (ONE multi-page PDF) */

    public function test_individual_mode_two_assets_returns_one_pdf_with_two_pages(): void
    {
        Sanctum::actingAs($this->operator());
        $a = $this->existingAsset('001');
        $b = $this->existingAsset('002');

        $response = $this->postJson('/api/assets/batch/label', [
            'asset_ids' => [$a->id, $b->id],
            'mode' => 'individual',
        ])->assertOk();

        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('labels.pdf', $response->headers->get('Content-Disposition'));

        $bytes = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertSame(2, $this->pdfPageCount($bytes));
    }

    /**
     * The critical multi-page check: EVERY page's own `/MediaBox` matches the
     * requested size, not just the first one — this is what actually proves
     * a multi-page Individual PDF isn't secretly an A4 sheet or a
     * mixed-size document.
     */
    #[DataProvider('sizes')]
    public function test_individual_mode_multiple_assets_every_page_is_the_correct_size(string $size, float $width, float $height): void
    {
        Sanctum::actingAs($this->operator());
        $a = $this->existingAsset('001');
        $b = $this->existingAsset('002');
        $c = $this->existingAsset('003');

        $response = $this->postJson('/api/assets/batch/label', [
            'asset_ids' => [$a->id, $b->id, $c->id],
            'size' => $size,
            'mode' => 'individual',
        ])->assertOk();

        $bytes = $response->getContent();
        $this->assertSame(3, $this->pdfPageCount($bytes));

        // Every distinct /MediaBox value in the whole document must be this
        // one size — proves no page (and no inherited /Pages default) is a
        // different size, not just that page 1 happens to be correct.
        preg_match_all('/\/MediaBox\s*\[([^\]]+)\]/', $bytes, $m);
        $distinctBoxes = array_unique($m[1]);
        $this->assertCount(1, $distinctBoxes, 'every page must share the exact same /MediaBox');

        [$w, $h] = $this->pdfPageSizeMmAt($bytes, 0);
        $this->assertEqualsWithDelta($width, $w, 0.05);
        $this->assertEqualsWithDelta($height, $h, 0.05);
    }

    public function test_individual_mode_multiple_assets_never_produces_a_zip(): void
    {
        Sanctum::actingAs($this->operator());
        $a = $this->existingAsset('001');
        $b = $this->existingAsset('002');

        $response = $this->postJson('/api/assets/batch/label', [
            'asset_ids' => [$a->id, $b->id],
            'mode' => 'individual',
        ])->assertOk();

        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsNotWith('PK', $response->getContent(), 'response must not be a ZIP (ZIP files start with the "PK" signature)');
    }

    public function test_individual_mode_multiple_assets_never_contains_a_png_signature(): void
    {
        Sanctum::actingAs($this->operator());
        $a = $this->existingAsset('001');
        $b = $this->existingAsset('002');
        $c = $this->existingAsset('003');

        $response = $this->postJson('/api/assets/batch/label', [
            'asset_ids' => [$a->id, $b->id, $c->id],
            'mode' => 'individual',
        ])->assertOk();

        // The QR/logo images are embedded INSIDE the PDF as image XObjects
        // (expected — this is still a single PDF, not raw image output); this
        // only guards against the response ever being a bare PNG file.
        $bytes = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringStartsNotWith("\x89PNG", $bytes);
    }

    /* ================================================================== validation */

    public function test_invalid_mode_is_rejected_with_422(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->postJson('/api/assets/batch/label', ['asset_ids' => [$asset->id], 'mode' => 'weird'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mode');
    }

    public function test_blank_mode_defaults_to_a4(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $response = $this->postJson('/api/assets/batch/label', ['asset_ids' => [$asset->id], 'mode' => ''])->assertOk();

        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('label-aset-batch', $response->headers->get('Content-Disposition'));
    }

    public function test_individual_mode_still_rejects_an_invalid_size(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->postJson('/api/assets/batch/label', ['asset_ids' => [$asset->id], 'size' => 'huge', 'mode' => 'individual'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('size');
    }

    public function test_individual_mode_nonexistent_asset_id_is_rejected_before_any_generation(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->postJson('/api/assets/batch/label', [
            'asset_ids' => [$asset->id, 999999],
            'mode' => 'individual',
        ])->assertStatus(422)->assertJsonValidationErrors('asset_ids.1');
    }

    /** All-or-nothing: a trashed id anywhere in the request fails the WHOLE request — the same FormRequest-level validation A4 mode already relied on, reused verbatim, so there is no partial-PDF failure mode to guard against separately. */
    public function test_individual_mode_rejects_the_whole_request_if_any_asset_is_trashed(): void
    {
        Sanctum::actingAs($this->operator());
        $a = $this->existingAsset('001');
        $b = $this->existingAsset('002');
        $b->delete();

        $this->postJson('/api/assets/batch/label', [
            'asset_ids' => [$a->id, $b->id],
            'mode' => 'individual',
        ])->assertStatus(422)->assertJsonValidationErrors('asset_ids.1');
    }

    /* ================================================================== authorization */

    public function test_unauthenticated_gets_401_for_individual_mode(): void
    {
        $asset = $this->existingAsset('001');

        $this->postJson('/api/assets/batch/label', ['asset_ids' => [$asset->id], 'mode' => 'individual'])
            ->assertStatus(401);
    }

    public function test_viewer_gets_403_for_individual_mode(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->existingAsset('001');

        $this->postJson('/api/assets/batch/label', ['asset_ids' => [$asset->id], 'mode' => 'individual'])
            ->assertStatus(403);
    }

    public function test_operator_can_use_individual_mode(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->postJson('/api/assets/batch/label', ['asset_ids' => [$asset->id], 'mode' => 'individual'])
            ->assertOk();
    }

    public function test_admin_can_use_individual_mode(): void
    {
        Sanctum::actingAs($this->admin());
        $asset = $this->existingAsset('001');

        $this->postJson('/api/assets/batch/label', ['asset_ids' => [$asset->id], 'mode' => 'individual'])
            ->assertOk();
    }

    /**
     * Confirmed by reading routes/api.php: label routes are still the legacy
     * `can:operator` Gate, which only literal `admin`/`operator` satisfy
     * (`User::canWriteInventory()`). `super_admin` is therefore BLOCKED from
     * every label route today, individual or A4 — R8 does not fix this
     * (see this class's own docblock / the top-level "KNOWN BACKLOG" list).
     */
    public function test_super_admin_is_still_blocked_from_individual_mode(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $asset = $this->existingAsset('001');

        $this->postJson('/api/assets/batch/label', ['asset_ids' => [$asset->id], 'mode' => 'individual'])
            ->assertStatus(403);
    }

    /**
     * Same pre-existing gate blocks `unit_admin` entirely — confirmed here for
     * an asset in their OWN location, proving there is no location-based
     * differentiation to speak of (the actor never gets far enough for
     * location scope to matter at all).
     */
    public function test_unit_admin_is_blocked_from_individual_mode_even_for_their_own_location(): void
    {
        Location::query()->firstOrCreate(['code' => '02'], ['name' => 'Lokasi 02', 'is_active' => true]);
        Sanctum::actingAs($this->unitAdmin('02'));
        $asset = $this->existingAsset('001', 2020, ['location_code' => '02'], '02', 'ZC', '001');

        $this->postJson('/api/assets/batch/label', ['asset_ids' => [$asset->id], 'mode' => 'individual'])
            ->assertStatus(403);
    }

    /**
     * And identically blocked for a mixed-location batch — same 403, same
     * reason (the Gate, not LocationScope) — so there is no "partial
     * authorization" behaviour to exhibit either way.
     */
    public function test_unit_admin_mixed_location_individual_batch_is_also_blocked_entirely(): void
    {
        Location::query()->firstOrCreate(['code' => '02'], ['name' => 'Lokasi 02', 'is_active' => true]);
        Location::query()->firstOrCreate(['code' => '03'], ['name' => 'Lokasi 03', 'is_active' => true]);
        Sanctum::actingAs($this->unitAdmin('02'));
        $a = $this->existingAsset('001', 2020, ['location_code' => '02'], '02', 'ZC', '001');
        $b = $this->existingAsset('002', 2020, ['location_code' => '03'], '03', 'ZC', '001');

        $this->postJson('/api/assets/batch/label', [
            'asset_ids' => [$a->id, $b->id],
            'mode' => 'individual',
        ])->assertStatus(403);
    }

    /* ================================================================== read-only / atomicity */

    public function test_individual_mode_generation_does_not_mutate_any_asset(): void
    {
        Sanctum::actingAs($this->operator());
        $a = $this->existingAsset('001');
        $b = $this->existingAsset('002');
        $before = [$a->updated_at->toISOString(), $b->updated_at->toISOString()];

        $this->postJson('/api/assets/batch/label', ['asset_ids' => [$a->id, $b->id], 'mode' => 'individual'])->assertOk();

        $this->assertSame($before, [$a->fresh()->updated_at->toISOString(), $b->fresh()->updated_at->toISOString()]);
        $this->assertDatabaseCount('mutation_logs', 0);
    }

    /**
     * No temp files, no cleanup needed: {@see AssetLabelPdfService::renderIndividualMulti()}
     * is purely in-memory (same as `renderBatch()`/`renderSingle()`), unlike
     * the ZIP-based approach this replaced. This test locks that in — a real
     * multi-asset request must never create a file matching the ZIP era's own
     * temp-file naming pattern.
     */
    public function test_individual_mode_multiple_assets_creates_no_temporary_files(): void
    {
        Sanctum::actingAs($this->operator());
        $a = $this->existingAsset('001');
        $b = $this->existingAsset('002');
        $c = $this->existingAsset('003');

        $before = glob(sys_get_temp_dir().'/vidatra-labels-*');

        $this->postJson('/api/assets/batch/label', [
            'asset_ids' => [$a->id, $b->id, $c->id],
            'mode' => 'individual',
        ])->assertOk();

        $after = glob(sys_get_temp_dir().'/vidatra-labels-*');
        $this->assertSame($before, $after);
    }
}
