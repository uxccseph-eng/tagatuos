<?php

namespace Tests\Feature;

use Tests\TestCase;

class JournalPageTest extends TestCase
{
    public function test_journal_requires_preview_access_and_company_setup(): void
    {
        $this->get('/sheets/journal')->assertRedirect('/signin');
        $this->withSession(['preview_access' => true])->get('/sheets/journal')->assertRedirect('/company/setup');
    }

    public function test_journal_renders_voucher_popups_and_escapes_company_details(): void
    {
        $this->withSession([
            'preview_access' => true,
            'company_setup' => ['company_name' => '<script>alert(1)</script>', 'full_name' => 'Demo Owner'],
        ])->get('/sheets/journal')->assertOk()->assertViewIs('journal')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('journal-entry')->assertSee('journal-void-actions')
            ->assertSee('journal.js')->assertDontSee('CAS READY');
    }

    public function test_all_existing_sheets_link_to_journal(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Sample Studio', 'full_name' => 'Demo Owner']]);

        foreach (['/sheets/sales', '/sheets/collections', '/sheets/cwt', '/sheets/purchases', '/sheets/payments', '/sheets/wtp'] as $url) {
            $this->get($url)->assertOk()->assertSee('href="'.route('sheets.journal').'"', false);
        }
    }
}
