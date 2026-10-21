<?php

namespace Tests\Feature;

use Tests\TestCase;

class SalesPageTest extends TestCase
{
    public function test_sales_requires_a_preview_session_and_company_setup(): void
    {
        $this->get('/sheets/sales')->assertRedirect('/signin');

        $this->withSession(['preview_access' => true])
            ->get('/sheets/sales')->assertRedirect('/company/setup');
    }

    public function test_sales_page_uses_saved_company_and_escapes_its_details(): void
    {
        $this->withSession([
            'preview_access' => true,
            'company_setup' => [
                'company_name' => '<script>alert(1)</script>',
                'full_name' => 'Demo Owner',
                'tin' => '123-456-789',
                'rdo' => '047',
            ],
        ])->get('/sheets/sales')
            ->assertOk()
            ->assertViewIs('sales')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('123-456-789')
            ->assertSee('RDO 047');
    }

    public function test_sheets_shortcut_redirects_to_sales(): void
    {
        $this->get('/sheets')->assertRedirect('/sheets/sales');
    }

    public function test_dashboard_links_to_the_sales_sheet(): void
    {
        $this->withSession([
            'preview_access' => true,
            'company_setup' => [
                'company_name' => 'Sample Studio',
                'full_name' => 'Demo Owner',
                'accounting_period' => 'calendar',
                'vat_type' => 'non_vat',
            ],
        ])->get('/dashboard')
            ->assertOk()
            ->assertSee('href="'.route('sheets.sales').'"', false);
    }
}
