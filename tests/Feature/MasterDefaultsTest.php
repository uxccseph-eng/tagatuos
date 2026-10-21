<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Tin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_blank_account_codes_are_generated_and_manual_codes_are_preserved(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Code QA', 'full_name' => 'Preview']]);
        $payload = ['name' => 'Generated expense', 'type' => 'Expense', 'normal_balance' => 'Debit', 'statement_section' => 'General and Administrative Expenses', 'is_cash_bank' => false, 'is_advance' => false, 'is_control' => false, 'relief' => false, 'percentage_tax' => false, 'ewt' => false];
        $first = $this->postJson('/masters/chart-of-accounts', [...$payload, 'code' => ''])->assertCreated()->json('account.code');
        $second = $this->postJson('/masters/chart-of-accounts', $payload)->assertCreated()->json('account.code');
        $this->assertMatchesRegularExpression('/^[5-9][0-9]{3}$/', $first);
        $this->assertNotSame($first, $second);
        $this->assertNotContains($first, array_column(ChartAccount::defaults(), 'code'));
        $this->postJson('/masters/chart-of-accounts', [...$payload, 'code' => '8998'])->assertCreated()->assertJsonPath('account.code', '8998');
    }

    public function test_party_and_tax_codes_generate_without_replacing_manual_codes_and_tin_uses_five_branch_digits(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Code QA', 'full_name' => 'Preview']]);
        $payload = ['name' => 'Auto customer', 'classification' => 'Customer', 'address' => '10 Sample Street', 'tin' => '000-000-000-00000'];
        $this->postJson('/masters/parties', $payload)->assertCreated()->assertJsonPath('record.code', 'CUS-000001')->assertJsonPath('record.tin', '000-000-000-00000');
        $this->postJson('/masters/parties', [...$payload, 'code' => ' '])->assertCreated()->assertJsonPath('record.code', 'CUS-000002');
        $this->postJson('/masters/parties', [...$payload, 'code' => 'MY-CODE'])->assertCreated()->assertJsonPath('record.code', 'MY-CODE');
        $this->postJson('/masters/parties', [...$payload, 'classification' => 'Supplier'])->assertCreated()->assertJsonPath('record.code', 'SUP-000001');
        $this->postJson('/masters/tax-codes', ['name' => 'Auto tax', 'kind' => 'VAT', 'rate' => 12])->assertCreated()->assertJsonPath('record.code', 'TAX-000001');
    }

    public function test_tin_format_keeps_leading_zeroes_and_rejects_invalid_content(): void
    {
        $this->assertSame('000-000-000-00000', Tin::format('000000000'));
        $this->assertSame('000-000-000-00123', Tin::format('000-000-000-00123'));
        $this->assertSame('000-000-000-01234', Tin::format('00000000001234'));
        $this->assertSame('', Tin::format(null));
        $this->assertSame('invalid', Tin::format('invalid'));
    }
}
