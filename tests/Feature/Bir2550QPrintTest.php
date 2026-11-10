<?php

namespace Tests\Feature;

use App\Services\Tax\Bir2550QPrintData;
use App\Services\Tax\BirExporterFactory;
use Tests\TestCase;

class Bir2550QPrintTest extends TestCase
{
    private function snapshot(): array
    {
        $profile = ['tin' => '123456789', 'branch_code' => '00001', 'company_name' => 'DEMO VAT PRINT CORPORATION', 'street' => '12 TEST STREET', 'city' => 'MAKATI CITY', 'zip_code' => '1209', 'rdo' => '033', 'contact_number' => '09171234567', 'contact_email' => 'demo@example.test', 'taxpayer_size' => 'small', 'entity_type' => 'corporation', 'vat_type' => 'vat'];
        $period = ['from' => '2026-10-01', 'to' => '2026-12-31', 'year' => 2026, 'quarter' => 4];
        $calculation = ['tax' => 500000, 'credits' => 20000, 'additional_tax' => 0, 'surcharge' => 10000, 'interest' => 5000, 'compromise' => 0, 'payable' => 495000, 'credit_balance' => 0];
        $vat = ['vatableSales' => 10000000, 'outputVatSales' => 1200000, 'zeroRatedSales' => 0, 'exemptSales' => 0, 'domesticPurchase' => 5000000, 'domesticInputTax' => 600000, 'servicesPurchase' => 0, 'serviceInputTax' => 0, 'importPurchase' => 0, 'importInputTax' => 0, 'domesticPurchaseNoTax' => 0, 'vatExemptImports' => 0, 'non_creditable' => 0];
        $xml = app(BirExporterFactory::class)->make('2550Q')->prepare(['taxpayer' => $profile, 'period' => $period, 'calculation' => $calculation, 'vat' => $vat, 'options' => ['vat_reviewed' => true, 'carryover_input' => 100000]]);

        return ['taxpayer' => $profile, 'period' => $period, 'calculation' => $calculation, 'bir_xml' => $xml, 'amended' => false];
    }

    private function record(): object
    {
        return (object) ['id' => 1, 'period_from' => '2026-10-01', 'period_to' => '2026-12-31'];
    }

    public function test_print_uses_frozen_xml_profile_and_reconciles_all_vat_amounts(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['taxpayer']['company_name'] = 'CHANGED PROFILE NAME';
        $data = app(Bir2550QPrintData::class)->build($this->record(), $snapshot);
        $this->assertTrue($data['mapped']);
        $this->assertSame('DEMO VAT PRINT CORPORATION', $data['name']);
        $this->assertSame(495000, $data['amounts'][26]);
        $this->assertSame(10000000, $data['amounts']['31A']);
        $this->assertSame(600000, $data['amounts']['44B']);
        $this->assertSame('00001', $data['branch']);
        $this->assertSame(4, $data['quarter']);
        $this->assertSame('122026', $data['year_end']);
        $html = view('tax-return-2550q-print', ['record' => $this->record(), 'printData' => $data])->render();
        $this->assertSame(2, substr_count($html, 'class="bir-paper"'));
        $this->assertStringContainsString('@page{size:612pt 1008pt', $html);
        foreach ($data['amounts'] as $item => $amount) {
            $this->assertStringContainsString('data-bir-amount="'.$item.'" data-cents="'.$amount.'"', $html);
        }
        $this->assertStringNotContainsString('CHANGED PROFILE NAME', $html);
    }

    public function test_negative_excess_input_and_excess_credits_keep_their_signs(): void
    {
        $snapshot = $this->snapshot();
        $fields = &$snapshot['bir_xml']['fields'];
        $p = 'frm2550qv2024:';
        foreach (['inputTaxCarried' => '20,000.00', 'total43' => '20,000.00', 'totalAvailInputTax' => '26,000.00', 'totalAllowInputTax' => '26,000.00', 'netVatPayable' => '-14,000.00', 'excessInputTax' => '-14,000.00', 'excessCredits' => '-14,200.00', 'totalPayable' => '-14,050.00'] as $key => $value) {
            $fields[$p.$key] = $value;
        }
        $snapshot['calculation']['tax'] = -1400000;
        $snapshot['calculation']['payable'] = 0;
        $snapshot['calculation']['credit_balance'] = 1405000;
        $data = app(Bir2550QPrintData::class)->build($this->record(), $snapshot);
        $this->assertTrue($data['mapped']);
        $this->assertSame(-1400000, $data['amounts'][15]);
        $this->assertSame(-1405000, $data['amounts'][26]);
    }

    public function test_unmapped_or_damaged_preparations_fall_back_to_the_complete_worksheet(): void
    {
        foreach (['no_xml', 'wrong_schema', 'missing_amount', 'wrong_total', 'amended', 'unsupported_schedule', 'long_description', 'long_profile', 'wrong_period'] as $case) {
            $snapshot = $this->snapshot();
            match ($case) {
                'no_xml' => $snapshot['bir_xml'] = [],
                'wrong_schema' => $snapshot['bir_xml']['schema'] = 'unknown',
                'missing_amount' => $snapshot['bir_xml']['fields']['frm2550qv2024:domesticInputTax'] = '',
                'wrong_total' => $snapshot['bir_xml']['fields']['frm2550qv2024:totalPayable'] = '1.00',
                'amended' => $snapshot['amended'] = true,
                'unsupported_schedule' => $snapshot['bir_xml']['fields']['txtNameWithHoldingAgent30'] = 'UNMAPPED AGENT',
                'long_description' => $snapshot['bir_xml']['fields']['frm2550qv2024:addSpecifyNo19'] = str_repeat('A', 47),
                'long_profile' => $snapshot['bir_xml']['fields']['frm2550qv2024:taxpayerName'] = str_repeat('A', 81),
                'wrong_period' => $snapshot['bir_xml']['fields']['frm2550qv2024:RtnPeriodFromNo4'] = '1/01/2026',
            };
            $this->assertFalse(app(Bir2550QPrintData::class)->build($this->record(), $snapshot)['mapped'], $case);
        }
    }
}
