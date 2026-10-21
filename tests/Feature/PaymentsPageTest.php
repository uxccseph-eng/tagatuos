<?php

namespace Tests\Feature;

use Tests\TestCase;

class PaymentsPageTest extends TestCase
{
    public function test_payments_requires_preview_access_and_company_setup(): void
    {
        $this->get('/sheets/payments')->assertRedirect('/signin');
        $this->withSession(['preview_access' => true])->get('/sheets/payments')->assertRedirect('/company/setup');
    }

    public function test_payments_renders_bill_popups_and_escapes_company_details(): void
    {
        $this->withSession([
            'preview_access' => true,
            'company_setup' => ['company_name' => '<script>alert(1)</script>', 'full_name' => 'Demo Owner'],
        ])->get('/sheets/payments')->assertOk()->assertViewIs('payments')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('payment-entry')->assertSee('payment-void-actions')
            ->assertSee('payments.js')->assertDontSee('CAS READY');
    }

    public function test_all_existing_sheets_link_to_payments(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Sample Studio', 'full_name' => 'Demo Owner']]);

        foreach (['/sheets/sales', '/sheets/collections', '/sheets/cwt', '/sheets/purchases'] as $url) {
            $this->get($url)->assertOk()->assertSee('href="'.route('sheets.payments').'"', false);
        }
    }
}
