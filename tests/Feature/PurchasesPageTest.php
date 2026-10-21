<?php

namespace Tests\Feature;

use Tests\TestCase;

class PurchasesPageTest extends TestCase
{
    public function test_purchases_requires_preview_access_and_company_setup(): void
    {
        $this->get('/sheets/purchases')->assertRedirect('/signin');
        $this->withSession(['preview_access' => true])->get('/sheets/purchases')->assertRedirect('/company/setup');
    }

    public function test_purchases_renders_bill_popups_and_escapes_company_details(): void
    {
        $this->withSession([
            'preview_access' => true,
            'company_setup' => ['company_name' => '<script>alert(1)</script>', 'full_name' => 'Demo Owner'],
        ])->get('/sheets/purchases')->assertOk()->assertViewIs('purchases')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('purchase-entry')->assertSee('purchase-void-actions')
            ->assertSee('purchases.js')->assertDontSee('CAS READY');
    }

    public function test_all_existing_sheets_link_to_purchases(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Sample Studio', 'full_name' => 'Demo Owner']]);

        foreach (['/sheets/sales', '/sheets/collections', '/sheets/cwt'] as $url) {
            $this->get($url)->assertOk()->assertSee('href="'.route('sheets.purchases').'"', false);
        }
    }
}
