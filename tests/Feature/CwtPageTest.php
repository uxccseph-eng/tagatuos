<?php

namespace Tests\Feature;

use Tests\TestCase;

class CwtPageTest extends TestCase
{
    public function test_cwt_requires_preview_access_and_company_setup(): void
    {
        $this->get('/sheets/cwt')->assertRedirect('/signin');
        $this->withSession(['preview_access' => true])->get('/sheets/cwt')->assertRedirect('/company/setup');
    }

    public function test_cwt_escapes_company_details_and_links_to_other_sheets(): void
    {
        $this->withSession([
            'preview_access' => true,
            'company_setup' => ['company_name' => '<script>alert(1)</script>', 'full_name' => 'Demo Owner', 'tin' => '123-456-789'],
        ])->get('/sheets/cwt')->assertOk()->assertViewIs('cwt')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('123-456-789')
            ->assertSee('href="'.route('sheets.sales').'"', false)
            ->assertSee('href="'.route('sheets.collections').'"', false)
            ->assertSee('cwt.js')->assertDontSee('CAS READY')->assertDontSee('SAWT .DAT: BIR Ready');
    }

    public function test_existing_sheets_link_to_cwt(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Sample Studio', 'full_name' => 'Demo Owner']]);

        foreach (['/sheets/sales', '/sheets/collections'] as $url) {
            $this->get($url)->assertOk()->assertSee('href="'.route('sheets.cwt').'"', false);
        }
    }
}
