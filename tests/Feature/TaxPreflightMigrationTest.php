<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TaxPreflightMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_schema_uses_a_short_index_name(): void
    {
        $this->assertTrue(Schema::hasIndex('tax_preflight_reviews', ['company_id', 'schedule', 'period_from', 'period_to']));
        foreach (Schema::getIndexes('tax_preflight_reviews') as $index) {
            $this->assertLessThanOrEqual(64, strlen($index['name']));
        }
    }

    public function test_retry_repairs_a_missing_index_without_deleting_existing_history(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $id = DB::table('tax_preflight_reviews')->insertGetId([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'schedule' => 'relief-sales',
            'period_from' => '2026-01-01',
            'period_to' => '2026-03-31',
            'snapshot_hash' => str_repeat('a', 64),
            'rules_version' => 'test',
            'action' => 'review',
            'summary' => '{}',
            'created_at' => now(),
        ]);
        Schema::table('tax_preflight_reviews', function (Blueprint $table): void {
            $table->dropIndex('tax_preflight_company_period_idx');
        });

        $migration = require database_path('migrations/2026_10_27_053946_create_tax_preflight_reviews_table.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasIndex('tax_preflight_reviews', 'tax_preflight_company_period_idx'));
        $this->assertDatabaseHas('tax_preflight_reviews', ['id' => $id, 'company_id' => $company->id, 'snapshot_hash' => str_repeat('a', 64)]);
        $this->assertDatabaseCount('tax_preflight_reviews', 1);
    }
}
