<?php

namespace Tests\Feature\Api;

use App\Enums\AssetSource;
use App\Enums\MutationEventType;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\Location;
use App\Models\MutationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\Feature\Database\Stage2AssetSourceMigrationTest;
use Tests\TestCase;

/**
 * Tahap 2 — Sumber Aset (`assets.asset_source`) across every asset write path,
 * the shared filter (list / export / report), the dashboard breakdown, location
 * scope, and mutation history / revert. Import is covered separately by
 * {@see Stage2ImportAssetSourceTest}; the migration by
 * {@see Stage2AssetSourceMigrationTest}.
 */
class Stage2AssetSourceTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function sourceProvider(): array
    {
        return array_combine(AssetSource::values(), array_map(fn (string $v) => [$v], AssetSource::values()));
    }

    private function sourceOf(Asset $asset): string
    {
        return $asset->fresh()->asset_source->value;
    }

    /* ================================================================== create */

    #[DataProvider('sourceProvider')]
    public function test_every_source_is_accepted_on_create_and_returned(string $source): void
    {
        Sanctum::actingAs($this->operator());

        $response = $this->postJson('/api/assets', $this->validCreatePayload(['asset_source' => $source]))
            ->assertCreated()
            ->assertJsonPath('data.asset_source', $source);

        $this->assertSame($source, Asset::findOrFail($response->json('data.id'))->asset_source->value);
    }

    public function test_create_without_a_source_is_rejected_never_defaulted(): void
    {
        Sanctum::actingAs($this->operator());
        $payload = $this->validCreatePayload();
        unset($payload['asset_source']);

        $this->postJson('/api/assets', $payload)->assertStatus(422)->assertJsonValidationErrors('asset_source');
        $this->assertSame(0, Asset::count());
    }

    public function test_create_with_an_unknown_or_null_source_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());

        foreach (['dana desa', 'YYS', null, '', ['bosda']] as $bad) {
            $this->postJson('/api/assets', $this->validCreatePayload(['asset_source' => $bad]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('asset_source');
        }
        $this->assertSame(0, Asset::count());
    }

    public function test_source_input_is_normalised_trim_whitespace_case_and_label(): void
    {
        Sanctum::actingAs($this->operator());

        $cases = [
            'BOSDA' => 'bosda',
            '  bosp ' => 'bosp',
            'Yayasan' => 'yayasan',
            'LAINNYA' => 'lainnya',
            ' Belum   diberi KETERANGAN ' => 'belum_diberi_keterangan',
            'belum_diberi_keterangan' => 'belum_diberi_keterangan',
        ];
        foreach ($cases as $input => $expected) {
            $this->postJson('/api/assets', $this->validCreatePayload(['asset_source' => $input]))
                ->assertCreated()
                ->assertJsonPath('data.asset_source', $expected);
        }
    }

    public function test_batch_create_applies_the_chosen_source_to_every_asset(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/assets/batch', $this->validCreatePayload(['asset_source' => 'bosp', 'count' => 3]))
            ->assertCreated();

        $this->assertSame(['bosp'], Asset::query()->pluck('asset_source')->map->value->unique()->values()->all());
        $this->assertSame(3, Asset::count());
    }

    public function test_batch_create_requires_a_source(): void
    {
        Sanctum::actingAs($this->operator());
        $payload = $this->validCreatePayload(['count' => 2]);
        unset($payload['asset_source']);

        $this->postJson('/api/assets/batch', $payload)->assertStatus(422)->assertJsonValidationErrors('asset_source');
        $this->assertSame(0, Asset::count());
    }

    /* ================================================================== update */

    public function test_update_changes_the_source_and_records_before_and_after(): void
    {
        $asset = $this->existingAsset('001');
        $this->assertSame('belum_diberi_keterangan', $this->sourceOf($asset)); // DB/model default
        Sanctum::actingAs($this->operator());

        $this->patchJson("/api/assets/{$asset->id}", ['asset_source' => 'bostk'])
            ->assertOk()
            ->assertJsonPath('data.asset_source', 'bostk');

        $log = MutationLog::query()->where('asset_id', $asset->id)->latest('id')->firstOrFail();
        $this->assertSame(MutationEventType::Edit, $log->event_type);
        $this->assertSame('belum_diberi_keterangan', $log->before_snapshot['asset_source']);
        $this->assertSame('bostk', $log->after_snapshot['asset_source']);
    }

    public function test_update_without_a_source_leaves_it_untouched(): void
    {
        $asset = $this->existingAsset('001', overrides: ['asset_source' => 'bosda']);
        Sanctum::actingAs($this->operator());

        $this->patchJson("/api/assets/{$asset->id}", ['brand_model' => 'Merek Baru'])->assertOk();

        $this->assertSame('bosda', $this->sourceOf($asset));
    }

    public function test_resubmitting_the_same_source_writes_no_history(): void
    {
        $asset = $this->existingAsset('001', overrides: ['asset_source' => 'yayasan']);
        Sanctum::actingAs($this->operator());
        $before = MutationLog::count();

        $this->patchJson("/api/assets/{$asset->id}", ['asset_source' => 'Yayasan'])->assertOk();

        $this->assertSame($before, MutationLog::count());
    }

    public function test_update_cannot_clear_or_corrupt_the_source(): void
    {
        $asset = $this->existingAsset('001', overrides: ['asset_source' => 'bosda']);
        Sanctum::actingAs($this->operator());

        foreach ([null, '', 'tidak ada'] as $bad) {
            $this->patchJson("/api/assets/{$asset->id}", ['asset_source' => $bad])
                ->assertStatus(422)
                ->assertJsonValidationErrors('asset_source');
        }
        $this->assertSame('bosda', $this->sourceOf($asset));
    }

    public function test_funding_source_stays_a_separate_free_text_column(): void
    {
        Sanctum::actingAs($this->operator());

        $id = $this->postJson('/api/assets', $this->validCreatePayload([
            'asset_source' => 'lainnya',
            'funding_source' => 'Hibah alumni 2024',
        ]))->assertCreated()->json('data.id');

        $this->patchJson("/api/assets/{$id}", ['asset_source' => 'yayasan'])->assertOk()
            ->assertJsonPath('data.funding_source', 'Hibah alumni 2024')
            ->assertJsonPath('data.asset_source', 'yayasan');

        $this->assertDatabaseHas('assets', ['id' => $id, 'funding_source' => 'Hibah alumni 2024', 'asset_source' => 'yayasan']);
    }

    /* ================================================================== batch edit */

    public function test_batch_edit_only_changes_the_source_when_it_is_sent(): void
    {
        $a = $this->existingAsset('001', overrides: ['asset_source' => 'bosda']);
        $b = $this->existingAsset('002', overrides: ['asset_source' => 'yayasan']);
        Sanctum::actingAs($this->operator());

        $this->patchJson('/api/assets/batch', ['asset_ids' => [$a->id, $b->id], 'changes' => ['notes' => 'Cek tahunan']])
            ->assertOk();
        $this->assertSame('bosda', $this->sourceOf($a));
        $this->assertSame('yayasan', $this->sourceOf($b));

        $this->patchJson('/api/assets/batch', ['asset_ids' => [$a->id, $b->id], 'changes' => ['asset_source' => 'LAINNYA']])
            ->assertOk()
            ->assertJsonPath('updated', 2);
        $this->assertSame('lainnya', $this->sourceOf($a));
        $this->assertSame('lainnya', $this->sourceOf($b));

        $log = MutationLog::query()->where('asset_id', $a->id)->latest('id')->firstOrFail();
        $this->assertSame(MutationEventType::BatchEdit, $log->event_type);
        $this->assertSame('bosda', $log->before_snapshot['asset_source']);
        $this->assertSame('lainnya', $log->after_snapshot['asset_source']);
    }

    public function test_batch_edit_rejects_a_null_or_unknown_source(): void
    {
        $a = $this->existingAsset('001', overrides: ['asset_source' => 'bosda']);
        Sanctum::actingAs($this->operator());

        foreach ([null, 'xyz'] as $bad) {
            $this->patchJson('/api/assets/batch', ['asset_ids' => [$a->id], 'changes' => ['asset_source' => $bad]])
                ->assertStatus(422)
                ->assertJsonValidationErrors('changes.asset_source');
        }
        $this->assertSame('bosda', $this->sourceOf($a));
    }

    /* ================================================================== filter / sort / export / report / dashboard */

    public function test_list_filter_is_multi_value_normalised_and_validated(): void
    {
        $bosda = $this->existingAsset('001', overrides: ['asset_source' => 'bosda']);
        $bosp = $this->existingAsset('002', overrides: ['asset_source' => 'bosp']);
        $this->existingAsset('003', overrides: ['asset_source' => 'yayasan']);
        $unknown = $this->existingAsset('004'); // belum_diberi_keterangan
        Sanctum::actingAs($this->viewer());

        $ids = $this->getJson('/api/assets?asset_source[]=bosda&asset_source[]=BOSP')->assertOk()->json('data.*.id');
        $this->assertEqualsCanonicalizing([$bosda->id, $bosp->id], $ids);

        $this->assertSame(
            [$unknown->id],
            $this->getJson('/api/assets?asset_source[]=belum_diberi_keterangan')->assertOk()->json('data.*.id'),
        );

        $this->getJson('/api/assets?asset_source[]=apbd')->assertStatus(422)->assertJsonValidationErrors('asset_source');
        $this->getJson('/api/assets?asset_source[]=bosda&asset_source[]=bosda')->assertStatus(422);
    }

    public function test_sort_by_source_follows_the_enum_order(): void
    {
        $this->existingAsset('001', overrides: ['asset_source' => 'belum_diberi_keterangan']);
        $this->existingAsset('002', overrides: ['asset_source' => 'yayasan']);
        $this->existingAsset('003', overrides: ['asset_source' => 'bosda']);
        Sanctum::actingAs($this->viewer());

        $sources = $this->getJson('/api/assets?sort=asset_source&direction=asc')->assertOk()->json('data.*.asset_source');

        $this->assertSame(['bosda', 'yayasan', 'belum_diberi_keterangan'], $sources);
    }

    public function test_export_follows_the_source_filter_and_writes_columns_u_and_v(): void
    {
        $this->existingAsset('001', overrides: ['asset_source' => 'bosda', 'funding_source' => 'Hibah X']);
        $this->existingAsset('002', overrides: ['asset_source' => 'yayasan']);
        Sanctum::actingAs($this->operator());

        $response = $this->get('/api/assets/export?asset_source[]=bosda')->assertOk();
        $sheet = $this->workbook($response->getContent())->getActiveSheet();

        $this->assertSame('SUMBER', $sheet->getCell('U6')->getValue());
        $this->assertSame('KETERANGAN SUMBER', $sheet->getCell('V6')->getValue());
        $this->assertSame('BOSDA', $sheet->getCell('U8')->getValue());
        $this->assertSame('Hibah X', $sheet->getCell('V8')->getValue());
        $this->assertSame('001', $sheet->getCell('E8')->getValue());
        $this->assertNull($sheet->getCell('E9')->getValue(), 'the yayasan asset must be filtered out');
        // historical columns keep their meaning
        $this->assertSame('No. Urut', $sheet->getCell('A6')->getValue());
        $this->assertSame('Ruangan', $sheet->getCell('O6')->getValue());
    }

    public function test_report_breaks_down_by_source_and_honours_the_filter(): void
    {
        $this->existingAsset('001', overrides: ['asset_source' => 'bosda']);
        $this->existingAsset('002', overrides: ['asset_source' => 'bosda', 'is_written_off' => true, 'written_off_on' => '2024-01-01']);
        $this->existingAsset('003', overrides: ['asset_source' => 'yayasan']);
        $this->existingAsset('004', overrides: ['asset_source' => 'bosp'])->delete(); // trashed: excluded
        Sanctum::actingAs($this->operator());

        $data = $this->getJson('/api/reports/inventory')->assertOk()->json('data');
        $this->assertSame(AssetSource::values(), array_column($data['by_source'], 'value'));
        $this->assertSame(3, $data['summary']['total_assets']);
        $this->assertSame(3, array_sum(array_column($data['by_source'], 'asset_count')));
        $this->assertSame(2, $this->bucket($data['by_source'], 'bosda'));
        $this->assertSame(0, $this->bucket($data['by_source'], 'bosp'));

        $filtered = $this->getJson('/api/reports/inventory?asset_source[]=yayasan')->assertOk()->json('data');
        $this->assertSame(1, $filtered['summary']['total_assets']);
        $this->assertSame(1, $this->bucket($filtered['by_source'], 'yayasan'));
        $this->assertSame(0, $this->bucket($filtered['by_source'], 'bosda'));
    }

    public function test_dashboard_lists_all_six_sources_and_sums_to_total(): void
    {
        $this->existingAsset('001', overrides: ['asset_source' => 'bostk']);
        $this->existingAsset('002', overrides: ['is_written_off' => true, 'written_off_on' => '2024-01-01']); // belum…
        $this->existingAsset('003', overrides: ['asset_source' => 'bostk'])->delete();
        Sanctum::actingAs($this->viewer());

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertSame(AssetSource::values(), array_column($data['by_source'], 'value'));
        $this->assertSame('Belum diberi keterangan', $data['by_source'][5]['label']);
        $this->assertSame($data['summary']['total_assets'], array_sum(array_column($data['by_source'], 'asset_count')));
        $this->assertSame(1, $this->bucket($data['by_source'], 'bostk'));
        $this->assertSame(1, $this->bucket($data['by_source'], 'belum_diberi_keterangan'));
        $this->assertSame(0, $this->bucket($data['by_source'], 'yayasan'));
    }

    /* ================================================================== location scope */

    public function test_unit_admin_only_sees_and_counts_its_own_location(): void
    {
        $own = $this->assetIn('02', 'bosda');
        $this->assetIn('03', 'bosda');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->assertSame([$own->id], $this->getJson('/api/assets?asset_source[]=bosda')->assertOk()->json('data.*.id'));
        $this->assertSame([], $this->getJson('/api/assets?asset_source[]=bosda&location_code[]=03')->assertOk()->json('data'));

        $dashboard = $this->getJson('/api/dashboard')->assertOk()->json('data.by_source');
        $this->assertSame(1, $this->bucket($dashboard, 'bosda'));

        $report = $this->getJson('/api/reports/inventory?asset_source[]=bosda')->assertOk()->json('data');
        $this->assertSame(1, $report['summary']['total_assets']);
        $this->assertSame(1, $this->bucket($report['by_source'], 'bosda'));
    }

    public function test_unit_admin_cannot_change_the_source_of_another_units_asset(): void
    {
        $own = $this->assetIn('02', 'bosda');
        $foreign = $this->assetIn('03', 'bosda');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->patchJson("/api/assets/{$foreign->id}", ['asset_source' => 'yayasan'])->assertForbidden();
        $this->patchJson('/api/assets/batch', ['asset_ids' => [$own->id, $foreign->id], 'changes' => ['asset_source' => 'yayasan']])
            ->assertForbidden();

        $this->assertSame('bosda', $this->sourceOf($own));
        $this->assertSame('bosda', $this->sourceOf($foreign));

        $this->patchJson("/api/assets/{$own->id}", ['asset_source' => 'yayasan'])->assertOk();
        $this->assertSame('yayasan', $this->sourceOf($own));
    }

    /* ================================================================== history / revert */

    public function test_reverting_a_source_edit_restores_the_previous_value(): void
    {
        $asset = $this->existingAsset('001', overrides: ['asset_source' => 'bosda']);
        Sanctum::actingAs($this->operator());
        $this->patchJson("/api/assets/{$asset->id}", ['asset_source' => 'bosp'])->assertOk();
        $log = MutationLog::query()->where('asset_id', $asset->id)->latest('id')->firstOrFail();

        $this->postJson("/api/mutations/{$log->id}/revert")->assertOk();

        $this->assertSame('bosda', $this->sourceOf($asset));
    }

    public function test_a_legacy_snapshot_without_asset_source_stays_revertable_and_leaves_the_source_alone(): void
    {
        $asset = $this->existingAsset('001', overrides: ['asset_source' => 'yayasan', 'brand_model' => 'Baru']);
        $legacy = fn (string $brand): array => [
            'id' => $asset->id, 'asset_code' => $asset->asset_code, 'location_code' => $asset->location_code,
            'category_code' => $asset->category_code, 'subcategory_code' => $asset->subcategory_code,
            'sequence_no' => $asset->sequence_no, 'asset_year' => $asset->asset_year, 'room_id' => null,
            'room_name' => null, 'room_raw_value' => null, 'brand_model' => $brand, 'detail_type' => null,
            'serial_no' => $asset->serial_no, 'material' => $asset->material, 'capacity_note' => null,
            'quantity' => 1, 'purchase_date' => null, 'funding_source' => $asset->funding_source,
            'condition' => $asset->condition?->value, 'notes' => $asset->notes, 'is_written_off' => false,
            'written_off_on' => null, 'written_off_note' => null, 'deleted_at' => null,
            // no 'asset_source' key — written before Tahap 2
        ];
        $log = MutationLog::create([
            'asset_id' => $asset->id, 'mutation_date' => now()->toDateString(),
            'event_type' => MutationEventType::Edit, 'performed_by' => $this->operator()->id,
            'before_snapshot' => $legacy('Lama'), 'after_snapshot' => $legacy('Baru'),
        ]);
        Sanctum::actingAs($this->operator());

        $this->getJson("/api/assets/{$asset->id}/mutations")->assertOk();
        $this->postJson("/api/mutations/{$log->id}/revert")->assertOk();

        $fresh = $asset->fresh();
        $this->assertSame('Lama', $fresh->brand_model);
        $this->assertSame('yayasan', $fresh->asset_source->value);
    }

    /* ================================================================== helpers */

    private function bucket(array $buckets, string $value): int
    {
        foreach ($buckets as $b) {
            if ($b['value'] === $value) {
                return $b['asset_count'];
            }
        }

        $this->fail("bucket {$value} missing");
    }

    private function workbook(string $content): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'source-export-');
        @unlink($path); // tempnam()'s placeholder — only its unique name is used
        $path .= '.xlsx';

        try {
            file_put_contents($path, $content);

            return IOFactory::load($path);
        } finally {
            @unlink($path);
        }
    }

    private function unitAdmin(string $locationCode): User
    {
        Location::query()->firstOrCreate(['code' => $locationCode], ['name' => "Lokasi {$locationCode}", 'is_active' => true]);

        return User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => $locationCode]);
    }

    private function assetIn(string $locationCode, string $source): Asset
    {
        Location::query()->firstOrCreate(['code' => $locationCode], ['name' => "Lokasi {$locationCode}", 'is_active' => true]);

        return Asset::factory()->create(['location_code' => $locationCode, 'asset_source' => $source])->fresh();
    }
}
