<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_log_batch_uuid_is_nullable_and_indexed(): void
    {
        $columns = collect(Schema::getColumns('activity_log'));
        $batchUuid = $columns->firstWhere('name', 'batch_uuid');

        $this->assertNotNull($batchUuid);
        $this->assertTrue($batchUuid['nullable']);

        $indexes = collect(Schema::getIndexes('activity_log'));
        $this->assertTrue($indexes->contains(
            fn (array $index): bool => in_array('batch_uuid', $index['columns'], true),
        ));
    }
}
