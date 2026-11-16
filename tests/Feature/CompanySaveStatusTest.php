<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\User;
use App\Services\CompanyLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompanySaveStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_book_does_not_claim_a_successful_save(): void
    {
        $company = Company::factory()->create();
        $snapshot = app(CompanyLedgerService::class)->read($company);

        $this->assertFalse($snapshot['initialized']);
        $this->assertSame(['revision' => 0, 'company_name' => $company->name, 'saved_at' => null, 'saved_by' => null], $snapshot['save_info']);
    }

    public function test_last_confirmed_author_is_scoped_to_the_book_and_revision(): void
    {
        $this->freezeTime();
        $company = Company::factory()->create();
        $author = User::factory()->create(['name' => 'Saving accountant']);
        $viewer = User::factory()->create(['name' => 'Viewing accountant']);
        CompanyLedger::factory()->create(['company_id' => $company->id, 'revision' => 2]);
        foreach ([1 => $viewer, 2 => $author] as $revision => $user) {
            DB::table('company_ledger_changes')->insert([
                'company_id' => $company->id, 'revision' => $revision,
                'user_id' => $user->id, 'request_id' => (string) Str::uuid(),
                'changes' => '[]', 'payload_hash' => str_repeat('a', 64), 'created_at' => now(),
            ]);
        }
        $this->actingAs($viewer);
        $snapshot = app(CompanyLedgerService::class)->read($company);

        $this->assertSame(2, $snapshot['save_info']['revision']);
        $this->assertSame($author->name, $snapshot['save_info']['saved_by']);
        $this->assertSame(now()->toIso8601String(), $snapshot['save_info']['saved_at']);
        $other = Company::factory()->create();
        CompanyLedger::factory()->create(['company_id' => $other->id, 'revision' => 2]);
        $this->assertNull(app(CompanyLedgerService::class)->read($other)['save_info']['saved_by']);
    }

    public function test_save_response_identifies_the_confirmed_writer_and_revision(): void
    {
        $this->freezeTime();
        $company = Company::factory()->create();
        $writer = User::factory()->firm()->create();
        $company->users()->attach($writer, ['role' => 'owner']);
        ChartAccount::seedWorkspace($company->workspaceKey());
        $snapshot = app(CompanyLedgerService::class)->save($company, $writer, [
            'revision' => 0, 'request_id' => (string) Str::uuid(),
            'state' => CompanyLedgerService::emptyState(), 'payroll' => [],
        ]);

        $this->assertTrue($snapshot['initialized']);
        $this->assertSame($snapshot['revision'], $snapshot['save_info']['revision']);
        $this->assertSame($writer->name, $snapshot['save_info']['saved_by']);
        $this->assertSame(now()->toIso8601String(), $snapshot['save_info']['saved_at']);
    }

    public function test_legacy_save_without_audit_does_not_invent_an_author(): void
    {
        $company = Company::factory()->create();
        $ledger = CompanyLedger::factory()->create(['company_id' => $company->id, 'revision' => 7]);
        $snapshot = app(CompanyLedgerService::class)->read($company);

        $this->assertNull($snapshot['save_info']['saved_by']);
        $this->assertSame($ledger->updated_at->toIso8601String(), $snapshot['save_info']['saved_at']);
    }
}
