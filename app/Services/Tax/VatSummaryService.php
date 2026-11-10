<?php

namespace App\Services\Tax;

use App\Models\Company;
use App\Models\CompanyLedger;
use Illuminate\Support\Facades\DB;

class VatSummaryService
{
    public function __construct(private BirVatLedgerMapper $mapper, private TaxReturnCatalog $catalog, private TaxReturnWorkspace $workspace) {}

    /** @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public function build(Company $company, array $profile, int $year): array
    {
        $ledger = CompanyLedger::query()->where('company_id', $company->id)->first();
        $state = $ledger?->state ?? [];
        $quarters = [];
        foreach (range(1, 4) as $quarter) {
            $period = $this->catalog->period('2550Q', $year, $quarter, $profile);
            $mapped = $this->mapper->map($state['sales'] ?? [], $state['purchases'] ?? [], $period);
            $values = $mapped['values'];
            $record = $this->workspace->preparations($company)->where('form', '2550Q')->where('period_from', $period['from'])->where('period_to', $period['to'])->latest('id')->first();
            $snapshot = $record ? json_decode($record->snapshot, true, 512, JSON_THROW_ON_ERROR) : [];
            $carryover = $this->fieldAmount($snapshot['bir_xml']['fields'] ?? [], 'inputTaxCarried');
            $base = $values['outputVatSales'] - $values['domesticInputTax'] - $values['serviceInputTax'] - $values['importInputTax'] + $values['non_creditable'];
            $credits = $record ? ($snapshot['calculation']['credits'] ?? null) : null;
            $penalties = $record ? array_sum(array_intersect_key($snapshot['calculation'] ?? [], array_flip(['surcharge', 'interest', 'compromise']))) : null;
            $additional = $snapshot['calculation']['additional_tax'] ?? 0;
            $net = $carryover !== null && $mapped['issues'] === [] ? $base - $carryover : null;
            $balance = $net !== null && is_int($credits) ? $net + $additional - $credits : null;
            $amounts = [
                'sales' => $values['vatableSales'], 'zero' => $values['zeroRatedSales'], 'exempt' => $values['exemptSales'],
                'total_sales' => $values['vatableSales'] + $values['zeroRatedSales'] + $values['exemptSales'],
                'output' => $values['outputVatSales'], 'purchases' => $values['domesticPurchase'] + $values['servicesPurchase'] + $values['importPurchase'],
                'nonvat' => $values['domesticPurchaseNoTax'] + $values['vatExemptImports'],
                'input' => $values['domesticInputTax'] + $values['serviceInputTax'] + $values['importInputTax'],
                'noncreditable' => $values['non_creditable'], 'base' => $base, 'carryover' => $carryover,
                'net' => $net, 'additional' => $record ? $additional : null, 'credits' => is_int($credits) ? $credits : null, 'balance' => $balance,
                'penalties' => $penalties, 'payable' => $balance !== null ? max(0, $balance + $penalties) : null,
                'excess_input' => $net !== null ? max(0, -$net) : null,
            ];
            $filing = DB::table('tax_return_filing_records')->where('company_id', $company->id)->where('form', '2550Q')->where('period_from', $period['from'])->where('period_to', $period['to'])->first();
            $savedValues = $snapshot['xml_vat_ledger']['values'] ?? null;
            $matches = is_array($savedValues) && $savedValues === $values && ($snapshot['xml_vat_ledger']['sources'] ?? []) === $mapped['sources'] && ($snapshot['xml_vat_ledger']['issues'] ?? []) === $mapped['issues'];
            $quarters[$quarter] = [
                'period' => $period, 'amounts' => $amounts, 'sources' => $mapped['sources'], 'issues' => $mapped['issues'],
                'record' => $record, 'filed' => $record?->status === 'filed' || (! ($record?->amended ?? false) && $filing?->filed_on !== null),
                'filing_reference' => $record?->filing_reference ?: $filing?->reference,
                'reconciliation' => ! $record ? 'Not generated' : ($savedValues === null ? 'Worksheet only — review mapping' : ($matches ? 'Matches saved sources' : 'Sources changed — review return')),
                'saved_payable' => $snapshot['calculation']['payable'] ?? null,
            ];
        }
        $annual = [];
        foreach (array_keys($quarters[1]['amounts']) as $key) {
            $numbers = array_column(array_column($quarters, 'amounts'), $key);
            $annual[$key] = in_array($key, ['carryover', 'net', 'balance', 'excess_input'], true) || in_array(null, $numbers, true) ? null : array_sum($numbers);
        }

        return ['quarters' => $quarters, 'annual' => $annual, 'year' => $year, 'revision' => $ledger?->revision ?? 0, 'initialized' => $ledger !== null, 'applicable' => $quarters[1]['period']['applicable']];
    }

    /** @param array<string, mixed> $fields */
    private function fieldAmount(array $fields, string $key): ?int
    {
        $value = str_replace(',', '', (string) ($fields['frm2550qv2024:'.$key] ?? ''));
        if (! preg_match('/^([0-9]{1,12})\.([0-9]{2})$/D', $value, $parts)) {
            return null;
        }

        return (int) $parts[1] * 100 + (int) $parts[2];
    }
}
