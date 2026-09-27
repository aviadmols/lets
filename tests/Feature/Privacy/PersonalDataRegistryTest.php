<?php

namespace Tests\Feature\Privacy;

use App\Domain\Privacy\PersonalDataRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * No personal-data column ships without a redaction decision.
 *
 * Walks the migrated schema: every column whose name looks personal must be
 * ERASED by the redaction jobs or EXEMPT with a written reason in
 * PersonalDataRegistry. Adding a `customer_email` to a new table and forgetting
 * the erasure path fails here, not in a regulator's letter.
 */
final class PersonalDataRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_personal_looking_column_has_a_redaction_decision(): void
    {
        $missing = [];

        foreach (Schema::getTableListing() as $table) {
            $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

            foreach (Schema::getColumnListing($table) as $column) {
                if (PersonalDataRegistry::looksPersonal($column) && ! PersonalDataRegistry::covers($table, $column)) {
                    $missing[] = $table.'.'.$column;
                }
            }
        }

        $this->assertSame([], $missing, 'Personal-data columns with no redaction entry: '.implode(', ', $missing));
    }

    public function test_every_registered_column_exists(): void
    {
        foreach (PersonalDataRegistry::ERASED as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), "Registry names a missing table: {$table}");

            foreach ($columns as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "Registry names a missing column: {$table}.{$column}");
            }
        }
    }
}
