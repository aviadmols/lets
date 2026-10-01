<?php

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The indexes the data map promises exist, with the column order the
 * movement log and the active-book union read them in.
 */
final class AnalyticsIndexesTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const EXPECTED = [
        'activity_events' => ['activity_events_shop_kind_created_idx', ['shop_id', 'kind', 'created_at']],
        'installment_plans' => ['plans_shop_kind_status_idx', ['shop_id', 'plan_kind', 'status']],
    ];

    public function test_the_analytics_indexes_exist(): void
    {
        foreach (self::EXPECTED as $table => [$name, $columns]) {
            $found = collect(Schema::getIndexes($table))->firstWhere('name', $name);
            $this->assertNotNull($found, "{$table} is missing {$name}.");
            $this->assertSame($columns, $found['columns']);
        }
    }
}
