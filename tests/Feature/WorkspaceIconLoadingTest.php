<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceIconLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_and_workspace_pages_preload_the_same_local_icon_font(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => [
            'company_name' => 'Icon Loading QA', 'full_name' => 'Sample Owner', 'tin' => '',
            'accounting_period' => 'Calendar year', 'vat_type' => 'Non-VAT', 'branch_code' => '00000', 'rdo' => '',
        ]]);

        foreach (['/', '/signin', '/signup', '/dashboard', '/company/setup',
            '/sheets/sales', '/sheets/collections', '/sheets/purchases', '/sheets/payments', '/sheets/cwt', '/sheets/wtp', '/sheets/journal', '/sheets/payroll',
            '/masters/chart-of-accounts', '/masters/parties', '/masters/employees', '/masters/ppe-items',
            '/books/general-journal', '/reports/trial-balance', '/tax-reports', '/settings/company', '/lapsing', '/preview/workspace/my-work',
        ] as $path) {
            $this->get($path)->assertOk()
                ->assertSee('fonts/material-symbols-outlined-v385.woff2', false)
                ->assertSee('as="font" type="font/woff2" crossorigin', false)
                ->assertSee('css/workspace-icons.css', false)
                ->assertDontSee('fonts.googleapis.com/css2?family=Material+Symbols', false);
        }
    }

    public function test_icon_font_is_local_and_reserves_a_single_icon_box_while_loading(): void
    {
        $font = file_get_contents(public_path('fonts/material-symbols-outlined-v385.woff2'));
        $this->assertSame('wOF2', substr($font, 0, 4));
        $stylesheet = file_get_contents(public_path('css/workspace-icons.css'));
        $this->assertStringContainsString('font-display: block', $stylesheet);
        $this->assertStringContainsString('font-family: "Workspace Symbols" !important', $stylesheet);
        $this->assertStringContainsString('max-width: 1em', $stylesheet);
        $this->assertStringContainsString('overflow: hidden', $stylesheet);
        $this->assertStringNotContainsString('fonts.googleapis.com', $stylesheet);
        $this->assertStringNotContainsString('fonts.gstatic.com', $stylesheet);
    }
}
