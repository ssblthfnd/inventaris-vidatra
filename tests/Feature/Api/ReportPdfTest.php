<?php

namespace Tests\Feature\Api;

use App\Enums\AssetCondition;
use App\Enums\AssetSource;
use App\Enums\UserRole;
use App\Http\Requests\Api\AssetIndexRequest;
use App\Models\Location;
use App\Models\Room;
use App\Models\User;
use App\Services\Report\ReportPdfService;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * `GET /api/reports/inventory/pdf` — the Laporan page's "Unduh PDF": same gate,
 * same filter validation and same aggregates as `GET /api/reports/inventory`,
 * rendered by dompdf with the filters written as a caption.
 *
 * The PDF bytes are compressed, so what the page says is checked on the view data
 * captured while the endpoint renders it (and on that view rendered as HTML).
 */
class ReportPdfTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    private const URL = '/api/reports/inventory/pdf';

    /** @var array<string, mixed>|null */
    private ?array $viewData = null;

    protected function setUp(): void
    {
        parent::setUp();

        View::composer('reports.inventory-pdf', function ($view): void {
            $this->viewData = $view->getData();
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     *  02 "Lokasi 02": 2 assets in "Ruang Guru" (2020, baik, BOSDA), 1 written off there (2021, rusak berat),
     *                  1 roomless (2021, kondisi NULL); source of the last two = DB default "belum diberi keterangan"
     *  03 "Lokasi 03": 1 asset in "Ruang TU" (2022, kondisi NULL, Yayasan)
     */
    private function seedReport(): void
    {
        [$l2] = $this->scope('02', '01', '001');
        $l3 = Location::factory()->create(['code' => '03', 'name' => 'Lokasi 03']);
        $guru = Room::factory()->forLocation($l2)->create(['name' => 'Ruang Guru']);
        $tu = Room::factory()->forLocation($l3)->create(['name' => 'Ruang TU']);

        $this->existingAsset('001', 2020, ['room_id' => $guru->id, 'condition' => AssetCondition::Baik, 'asset_source' => AssetSource::Bosda], '02', '01', '001');
        $this->existingAsset('002', 2020, ['room_id' => $guru->id, 'condition' => AssetCondition::Baik, 'asset_source' => AssetSource::Bosda], '02', '01', '001');
        $this->existingAsset('003', 2021, ['room_id' => $guru->id, 'condition' => AssetCondition::RusakBerat, 'is_written_off' => true, 'written_off_on' => '2024-01-01'], '02', '01', '001');
        $this->existingAsset('004', 2021, ['room_id' => null, 'condition' => null], '02', '01', '001');
        $this->existingAsset('001', 2022, ['room_id' => $tu->id, 'condition' => null, 'asset_source' => AssetSource::Yayasan], '03', '01', '001');
    }

    private function html(): string
    {
        $this->assertNotNull($this->viewData, 'The PDF view was not rendered.');

        return view('reports.inventory-pdf', $this->viewData)->render();
    }

    /* ------------------------------------------------------------------ authorization */

    public function test_guest_gets_401(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
    }

    public function test_viewer_is_forbidden(): void
    {
        Sanctum::actingAs($this->viewer());
        $this->getJson(self::URL)->assertStatus(403);
    }

    public function test_inactive_operator_is_forbidden(): void
    {
        Sanctum::actingAs($this->operator(active: false));
        $this->getJson(self::URL)->assertStatus(403);
    }

    /* ------------------------------------------------------------------ the download */

    public function test_returns_a_pdf_download_named_with_the_wib_print_time(): void
    {
        $this->seedReport();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21 02:30:00', 'UTC'));
        Sanctum::actingAs($this->operator());

        $response = $this->get(self::URL)->assertOk();

        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(
            'attachment; filename=laporan-inventaris-20261021-0930.pdf',
            $response->headers->get('Content-Disposition'),
        );
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertSame('21 Oktober 2026, 09.30 WIB', $this->viewData['printedAt']);
    }

    public function test_every_role_with_the_report_ability_can_download(): void
    {
        $this->seedReport();

        foreach ([UserRole::Operator, UserRole::Admin, UserRole::SuperAdmin] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->get(self::URL)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        }
    }

    public function test_invalid_filters_are_rejected_like_the_report_endpoint(): void
    {
        $this->seedReport();
        Sanctum::actingAs($this->operator());

        foreach ([
            'condition' => '?condition[]=hancur',
            'location_code' => '?location_code[]=ZZ',
            'asset_year' => '?asset_year[]=1900',
            'asset_source' => '?asset_source[]=hibah',
            'category_code' => '?category_code[]=01&category_code[]=01',
            'is_written_off' => '?is_written_off[]=maybe',
        ] as $field => $query) {
            $this->getJson(self::URL.$query)->assertStatus(422)->assertJsonValidationErrors($field);
        }
    }

    public function test_pdf_numbers_match_the_report_endpoint_for_the_same_filters(): void
    {
        $this->seedReport();
        Sanctum::actingAs($this->operator());
        $query = '?location_code[]=02&is_written_off[]=0';

        $report = $this->getJson('/api/reports/inventory'.$query)->assertOk()->json('data');
        $this->get(self::URL.$query)->assertOk();

        $this->assertSame([
            'Total Aset' => $report['summary']['total_assets'],
            'Aset Aktif' => $report['summary']['active_assets'],
            'Aset Ditulis Off' => $report['summary']['written_off_assets'],
            'Lokasi Terdaftar' => 2,
            'Kategori Terdaftar' => 1,
        ], $this->viewData['kpis']);
        $this->assertSame(3, $this->viewData['kpis']['Total Aset']);
        $this->assertSame($report['by_room'], $this->viewData['rooms']);
        $this->assertSame(
            ['Ruang Guru@Lokasi 02=2', 'Tanpa Ruangan@-=1'],
            array_map(fn ($r) => "{$r['name']}@".($r['location_name'] ?? '-')."={$r['asset_count']}", $this->viewData['rooms']),
        );
    }

    public function test_rooms_table_lists_most_assets_first_then_name_with_the_roomless_bucket_last(): void
    {
        [$l2] = $this->scope('02', '01', '001');
        $l3 = Location::factory()->create(['code' => '03', 'name' => 'Lokasi 03']);
        $aula = Room::factory()->forLocation($l2)->create(['name' => 'Aula']);
        $perpus = Room::factory()->forLocation($l2)->create(['name' => 'Perpustakaan']);
        $kelas = Room::factory()->forLocation($l3)->create(['name' => 'Kelas']);
        $seq = 0;
        foreach ([[$aula, '02', 1], [$perpus, '02', 2], [$kelas, '03', 2], [null, '02', 3]] as [$room, $loc, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $this->existingAsset(sprintf('%03d', ++$seq), 2020, ['room_id' => $room?->id], $loc, '01', '001');
            }
        }
        Sanctum::actingAs($this->operator());

        $this->get(self::URL)->assertOk();

        $this->assertSame(
            ['Kelas=2', 'Perpustakaan=2', 'Aula=1', 'Tanpa Ruangan=3'],
            array_map(fn ($r) => "{$r['name']}={$r['asset_count']}", $this->viewData['rooms']),
        );
        $this->assertMatchesRegularExpression('/Kelas.*Perpustakaan.*Aula.*Tanpa Ruangan/s', $this->html());
    }

    public function test_charts_list_every_bucket_with_shares_and_bars_sorted_largest_first(): void
    {
        $this->seedReport();
        Sanctum::actingAs($this->operator());

        $this->get(self::URL)->assertOk();

        $this->assertSame(
            ['BOSDA:2:40%', 'BOSP:0:0%', 'BOSTK:0:0%', 'Yayasan:1:20%', 'Lainnya:0:0%', 'Belum diberi keterangan:2:40%'],
            array_map(fn ($i) => "{$i['label']}:{$i['value']}:{$i['percent']}", $this->viewData['sources']),
        );
        $this->assertSame(
            ['Baik:2:40%', 'Kurang Baik:0:0%', 'Rusak Berat:1:20%', 'Tidak diketahui:2:40%'],
            array_map(fn ($i) => "{$i['label']}:{$i['value']}:{$i['percent']}", $this->viewData['conditions']),
        );
        $this->assertSame(['Lokasi 02:4', 'Lokasi 03:1'], array_map(fn ($b) => "{$b['label']}:{$b['value']}", $this->viewData['locations']));
    }

    /* ------------------------------------------------------------------ filter caption */

    public function test_caption_without_filters(): void
    {
        $this->seedReport();
        Sanctum::actingAs($this->operator());

        $this->get(self::URL)->assertOk();

        $this->assertSame(['used' => [], 'unused' => ['Lokasi', 'Kategori', 'Kondisi', 'Status', 'Tahun', 'Sumber']], $this->viewData['caption']);
        $text = preg_replace('/\s+/', ' ', strip_tags($this->html()));
        $this->assertStringContainsString('Data dalam laporan ini berdasarkan filter: tanpa filter — semua data.', $text);
        $this->assertStringNotContainsString('Filter lain tidak dipakai', $text);
    }

    public function test_caption_names_used_filters_and_lists_the_rest(): void
    {
        $this->seedReport();
        Sanctum::actingAs($this->operator());

        $this->get(self::URL.'?location_code[]=02&is_written_off[]=0')->assertOk();

        $text = preg_replace('/\s+/', ' ', strip_tags($this->html()));
        $this->assertStringContainsString(
            'Data dalam laporan ini berdasarkan filter: Lokasi: Lokasi 02 · Status: Aktif. '
            .'Filter lain tidak dipakai (Kategori, Kondisi, Tahun, Sumber: semua).',
            $text,
        );
    }

    public function test_caption_lists_every_value_of_multi_value_and_legacy_filters(): void
    {
        $this->seedReport();
        $guru = Room::query()->where('name', 'Ruang Guru')->firstOrFail();
        Sanctum::actingAs($this->operator());

        $this->get(self::URL.'?'.http_build_query([
            'condition' => ['unknown', 'baik'],
            'is_written_off' => ['1', '0'],
            'asset_year' => ['2021', '2020'],
            'asset_source' => ['yayasan', 'bosda'],
            'subcategory_code' => ['01.001'],
            'room_id' => [(string) $guru->id, 'none'],
            'q' => 'meja',
        ]))->assertOk();

        $this->assertSame([
            'used' => [
                'Kondisi: Baik, Tidak diketahui',
                'Status: Aktif, Ditulis off',
                'Tahun: 2020, 2021',
                'Sumber: BOSDA, Yayasan',
                'Subkategori: Subkategori 01-001',
                'Ruangan: Ruang Guru (Lokasi 02), Tanpa Ruangan',
                'Pencarian: "meja"',
            ],
            'unused' => ['Lokasi', 'Kategori'],
        ], $this->viewData['caption']);
    }

    public function test_unit_admin_pdf_is_scoped_and_says_so(): void
    {
        $this->seedReport();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => '03']));

        $this->get(self::URL)->assertOk();

        $this->assertSame(['Lokasi: Lokasi 03 (sesuai hak akses)'], $this->viewData['caption']['used']);
        $this->assertSame(1, $this->viewData['kpis']['Total Aset']);
        $this->assertSame(['Ruang TU'], array_column($this->viewData['rooms'], 'name'));
    }

    /* ------------------------------------------------------------------ rendering constraints */

    public function test_pdf_is_light_only_and_loads_nothing_remote(): void
    {
        $this->seedReport();
        Sanctum::actingAs($this->operator());
        $this->get(self::URL)->assertOk();

        $html = $this->html();
        foreach (['var(--', 'data-theme', 'prefers-color-scheme', '<script', '<link', 'src=', 'url(', '@import'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
    }

    public function test_dompdf_runs_without_php_javascript_or_remote_access(): void
    {
        $this->seedReport();
        // keep the PDF object the endpoint renders, to inspect the dompdf options it ran with
        $spy = new class extends ReportPdfService
        {
            public ?PdfDocument $last = null;

            public function render(array $report, AssetIndexRequest $request, CarbonImmutable $printedAt): PdfDocument
            {
                return $this->last = parent::render($report, $request, $printedAt);
            }
        };
        $this->app->instance(ReportPdfService::class, $spy);
        Sanctum::actingAs($this->operator());

        $this->get(self::URL)->assertOk();

        $options = $spy->last->getDomPDF()->getOptions();

        $this->assertFalse($options->getIsRemoteEnabled());
        $this->assertFalse($options->getIsPhpEnabled());
        $this->assertFalse($options->getIsJavascriptEnabled());
        $this->assertSame('DejaVu Sans', $options->getDefaultFont());
    }

    public function test_query_count_does_not_grow_with_the_number_of_assets(): void
    {
        $this->seedReport();
        Sanctum::actingAs($this->operator());

        $count = function (): int {
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            $this->get(self::URL)->assertOk();

            return $n;
        };

        $before = $count();
        $guru = Room::query()->where('name', 'Ruang Guru')->firstOrFail();
        foreach (range(10, 40) as $seq) {
            $this->existingAsset(sprintf('%03d', $seq), 2020, ['room_id' => $guru->id], '02', '01', '001');
        }

        $this->assertSame($before, $count());
    }
}
