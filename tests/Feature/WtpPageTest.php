<?php

namespace Tests\Feature;

use Tests\TestCase;

class WtpPageTest extends TestCase
{
    public function test_wtp_requires_preview_access_and_company_setup(): void
    {
        $this->get('/sheets/wtp')->assertRedirect('/signin');
        $this->withSession(['preview_access' => true])->get('/sheets/wtp')->assertRedirect('/company/setup');
    }

    public function test_wtp_renders_bill_popups_and_escapes_company_details(): void
    {
        $this->withSession([
            'preview_access' => true,
            'company_setup' => ['company_name' => '<script>alert(1)</script>', 'full_name' => 'Demo Owner'],
        ])->get('/sheets/wtp')->assertOk()->assertViewIs('wtp')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('wtp-entry')->assertSee('wtp-void-actions')
            ->assertSee('wtp.js')->assertDontSee('CAS READY');
    }

    public function test_all_existing_sheets_link_to_wtp(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Sample Studio', 'full_name' => 'Demo Owner']]);

        foreach (['/sheets/sales', '/sheets/collections', '/sheets/cwt', '/sheets/purchases', '/sheets/payments'] as $url) {
            $this->get($url)->assertOk()->assertSee('href="'.route('sheets.wtp').'"', false);
        }
    }
}
