<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SheetNavigationTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, bool> $settings */
    private function preview(array $settings = []): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Navigation test book', 'full_name' => 'Sample Owner', 'sheet_settings' => $settings]]);
    }

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }

    public function test_all_transaction_sheets_share_the_same_navigation_and_active_destination(): void
    {
        $this->preview();
        foreach (['sales', 'collections', 'cwt', 'purchases', 'payments', 'wtp', 'payroll', 'journal'] as $sheet) {
            $response = $this->get(route('sheets.'.$sheet))->assertOk()->assertSee('css/sheet-tabs.css')->assertSee('js/sheet-tabs.js');
            $xpath = $this->document($response->getContent());
            $this->assertSame(1, $xpath->query('//nav[@aria-label="Sheet navigation"]')->length);
            $this->assertSame(9, $xpath->query('//nav[@aria-label="Sheet navigation"]/a')->length);
            $active = $xpath->query('//nav[@aria-label="Sheet navigation"]/a[@aria-current="page"]');
            $this->assertSame(1, $active->length);
            $this->assertSame(route('sheets.'.$sheet), $active->item(0)->getAttribute('href'));
            foreach (['sales', 'collections', 'cwt', 'purchases', 'payments', 'wtp', 'payroll', 'journal'] as $destination) {
                $this->assertSame(1, $xpath->query('//nav[@aria-label="Sheet navigation"]/a[@href="'.route('sheets.'.$destination).'"]')->length);
            }
            $this->assertSame(1, $xpath->query('//script[starts-with(@src,"'.asset('js/sheet-tabs.js').'")]')->length);
        }
    }

    public function test_hidden_optional_sheets_keep_their_company_setting_visibility(): void
    {
        $this->preview(['cwt' => false, 'wtp' => false, 'payroll' => false, 'ppe' => false]);
        $response = $this->get(route('sheets.sales'))->assertOk();
        $xpath = $this->document($response->getContent());
        $this->assertSame(5, $xpath->query('//nav[@aria-label="Sheet navigation"]/a')->length);
        foreach (['cwt', 'wtp', 'payroll'] as $sheet) {
            $this->assertSame(0, $xpath->query('//nav[@aria-label="Sheet navigation"]/a[@href="'.route('sheets.'.$sheet).'"]')->length);
        }
        $this->assertSame(0, $xpath->query('//nav[@aria-label="Sheet navigation"]/a[@href="'.route('lapsing').'"]')->length);
    }

    public function test_count_hooks_and_the_collections_alignment_wrapper_are_preserved(): void
    {
        $this->preview();
        $this->get(route('sheets.sales'))->assertOk()->assertSee('data-tab-count');
        $response = $this->get(route('sheets.collections'))->assertOk()->assertSee('data-collection-tab-count');
        $xpath = $this->document($response->getContent());
        $this->assertSame(1, $xpath->query('//div[@class="sheet-tab-gutter"]/nav[@aria-label="Sheet navigation"]')->length);
        foreach (['purchases', 'payments', 'wtp', 'payroll', 'journal'] as $sheet) {
            $this->get(route('sheets.'.$sheet))->assertOk()->assertSee('id="'.$sheet.'-tab-count"', false);
        }
    }

    public function test_lapsing_keeps_its_two_local_destinations_with_shared_tab_styling(): void
    {
        $this->preview();
        foreach (['lapsing', 'lapsing.attention'] as $route) {
            $response = $this->get(route($route))->assertOk()->assertSee('css/sheet-tabs.css')->assertSee('js/sheet-tabs.js')->assertSee('id="attention-count"', false)->assertDontSee('aria-label="Sheet navigation"', false);
            $xpath = $this->document($response->getContent());
            $this->assertSame(2, $xpath->query('//nav[@aria-label="Lapsing navigation"]/a')->length);
            $active = $xpath->query('//nav[@aria-label="Lapsing navigation"]/a[@aria-current="page"]');
            $this->assertSame(1, $active->length);
            $this->assertSame(route($route), $active->item(0)->getAttribute('href'));
        }
    }
}
