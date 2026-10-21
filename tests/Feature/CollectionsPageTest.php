<?php

namespace Tests\Feature;

use Tests\TestCase;

class CollectionsPageTest extends TestCase
{
    public function test_collections_requires_preview_access_and_company_setup(): void
    {
        $this->get('/sheets/collections')->assertRedirect('/signin');
        $this->withSession(['preview_access' => true])
            ->get('/sheets/collections')->assertRedirect('/company/setup');
    }

    public function test_collections_uses_and_escapes_saved_company_details(): void
    {
        $this->withSession([
            'preview_access' => true,
            'company_setup' => ['company_name' => '<script>alert(1)</script>', 'full_name' => 'Demo Owner', 'tin' => '123-456-789', 'rdo' => '047'],
        ])->get('/sheets/collections')
            ->assertOk()
            ->assertViewIs('collections')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('123-456-789')
            ->assertSee('RDO 047')
            ->assertSee('href="'.route('sheets.sales').'"', false)
            ->assertDontSee('Live BIR Ledger')
            ->assertDontSee('CAS Synced');
    }

    public function test_sales_links_to_collections(): void
    {
        $this->withSession([
            'preview_access' => true,
            'company_setup' => ['company_name' => 'Sample Studio', 'full_name' => 'Demo Owner'],
        ])->get('/sheets/sales')->assertOk()
            ->assertSee('href="'.route('sheets.collections').'"', false);
    }
}
