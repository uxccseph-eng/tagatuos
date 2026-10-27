<?php

namespace Tests\Feature;

use App\Models\TaxCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoReceiptTaxTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_na_becomes_no_receipt_without_changing_its_identity_or_other_workspaces(): void
    {
        $legacy = TaxCode::factory()->create(['workspace_key' => 'receipt-test', 'code' => 'NA', 'name' => 'Not applicable', 'kind' => 'Exempt', 'rate' => 0]);
        $other = TaxCode::factory()->create(['workspace_key' => 'other-book', 'code' => 'NA', 'name' => 'Not applicable', 'kind' => 'Exempt', 'rate' => 0]);
        TaxCode::seedWorkspace('receipt-test');
        $this->assertSame('NR', $legacy->fresh()->code);
        $this->assertSame('No Receipt', $legacy->fresh()->name);
        $this->assertSame('NA', $other->fresh()->code);
        TaxCode::seedWorkspace('receipt-test');
        $this->assertSame(1, TaxCode::query()->where('workspace_key', 'receipt-test')->where('code', 'NR')->count());
        $this->assertSame(0, TaxCode::query()->where('workspace_key', 'receipt-test')->where('code', 'NA')->count());
    }

    public function test_existing_nr_and_custom_na_are_not_overwritten(): void
    {
        $nr = TaxCode::factory()->create(['workspace_key' => 'collision', 'code' => 'NR', 'name' => 'Existing NR', 'kind' => 'Exempt', 'rate' => 0]);
        $legacy = TaxCode::factory()->create(['workspace_key' => 'collision', 'code' => 'NA', 'name' => 'Not applicable', 'kind' => 'Exempt', 'rate' => 0]);
        $custom = TaxCode::factory()->create(['workspace_key' => 'custom', 'code' => 'NA', 'name' => 'Custom tax treatment', 'kind' => 'VAT', 'rate' => 8]);
        TaxCode::seedWorkspace('collision');
        TaxCode::seedWorkspace('custom');
        $this->assertTrue($legacy->fresh()->archived);
        $this->assertSame('Existing NR', $nr->fresh()->name);
        $this->assertSame('Custom tax treatment', $custom->fresh()->name);
        $this->assertSame('8.0000', $custom->fresh()->rate);
        $this->assertDatabaseHas('tax_codes', ['workspace_key' => 'custom', 'code' => 'NR', 'name' => 'No Receipt']);
    }
}
