<?php

namespace Tests\Feature\Models;

use App\Models\Asset;
use App\Models\ImportRow;
use App\Models\MutationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MutationLogAndImportRowTest extends TestCase
{
    use RefreshDatabase;

    public function test_mutation_log_never_touches_updated_at(): void
    {
        $asset = Asset::factory()->create();
        $log = MutationLog::factory()->create(['asset_id' => $asset->id, 'notes' => 'first']);

        $createdAt = $log->created_at;

        // an update must not attempt to write a non-existent updated_at column
        $log->update(['notes' => 'corrected']);

        $log->refresh();
        $this->assertSame('corrected', $log->notes);
        $this->assertEquals($createdAt->toDateTimeString(), $log->created_at->toDateTimeString());
        $this->assertNull($log->updated_at);

        $stored = (array) DB::table('mutation_logs')->where('id', $log->id)->first();
        $this->assertArrayNotHasKey('updated_at', $stored);
        $this->assertArrayHasKey('created_at', $stored);
    }

    public function test_import_row_json_columns_round_trip_as_arrays(): void
    {
        $payload = [
            'row_number' => 15,
            'sheet' => '03 ELEKTRONIK',
            'cells' => ['B' => '01', 'C' => '03', 'D' => '001', 'E' => '005A', 'F' => '2012'],
            'parsed' => ['location_code' => '01', 'sequence_no' => '005A', 'is_written_off' => true],
        ];
        $messages = [
            ['code' => 'room_unmapped', 'severity' => 'warning', 'field' => 'room_id', 'message' => 'not matched'],
            ['code' => 'condition_missing', 'severity' => 'warning', 'field' => 'condition', 'message' => 'no mark'],
        ];

        $row = ImportRow::factory()->create([
            'raw_payload' => $payload,
            'validation_messages' => $messages,
        ]);

        $fresh = $row->fresh();
        $this->assertIsArray($fresh->raw_payload);
        $this->assertIsArray($fresh->validation_messages);
        // assertEquals (not assertSame): MySQL JSON does not preserve object key order.
        $this->assertEquals($payload, $fresh->raw_payload);
        $this->assertEquals($messages, $fresh->validation_messages);
        $this->assertSame('005A', $fresh->raw_payload['parsed']['sequence_no']);
        $this->assertTrue($fresh->raw_payload['parsed']['is_written_off']);

        // stored as real JSON in the DB
        $stored = DB::table('import_rows')->where('id', $row->id)->value('raw_payload');
        $this->assertJson($stored);
    }

    public function test_import_row_validation_messages_may_be_null(): void
    {
        $row = ImportRow::factory()->create(['validation_messages' => null]);
        $this->assertNull($row->fresh()->validation_messages);
    }
}
