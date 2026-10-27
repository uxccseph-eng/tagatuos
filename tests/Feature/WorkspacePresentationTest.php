<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InboxDocument;
use App\Models\MonthlyClosing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkspacePresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sample_layouts_are_read_only_and_never_load_business_records(): void
    {
        DB::enableQueryLog();
        foreach (['my-work', 'documents', 'document-review', 'closing', 'lark-backup', 'manual-purge'] as $page) {
            $this->get(route('workspace.preview', ['page' => $page]))->assertOk()
                ->assertSee('Presentation preview · Sample data · Read-only')
                ->assertSee('<div inert>', false)->assertSee('Sample Trading Company');
        }
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        DB::disableQueryLog();
        $this->assertDoesNotMatchRegularExpression('/\b(companies|inbox_documents|monthly_closings|company_ledgers|parties)\b/i', $queries);
        $this->assertSame(0, Company::count());
        $this->assertSame(0, InboxDocument::count());
        $this->assertSame(0, MonthlyClosing::count());
        $this->get(route('workspace.preview-document'))->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertSee('FOR PRESENTATION ONLY');
        $this->get(route('workspace.preview', ['page' => 'guided-import']))->assertOk()->assertSee('POSTING DISABLED')->assertSee('No company records are created.')->assertSee('guided-sheet-import.js');
    }

    public function test_presentations_and_sample_document_are_unavailable_in_production(): void
    {
        $this->app->instance('env', 'production');
        foreach (['my-work', 'documents', 'document-review', 'closing', 'lark-backup', 'manual-purge', 'guided-import'] as $page) {
            $this->get(route('workspace.preview', ['page' => $page]))->assertNotFound();
        }
        $this->get(route('workspace.preview-document'))->assertNotFound();
    }
}
