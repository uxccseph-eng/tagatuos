<?php

namespace App\Services;

use App\SecRegistrationRules;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class FinancialStatementPackage
{
    public const TEXT_FIELDS = [
        'business_activity' => 'Principal business activity',
        'reporting_framework' => 'Applicable reporting framework',
        'measurement_basis' => 'Basis of measurement',
        'estimates' => 'Estimates and judgments',
        'going_concern_statement' => 'Going concern',
        'cash_policy' => 'Cash and cash equivalents policy',
        'receivables_policy' => 'Receivables policy',
        'ppe_policy' => 'Property, plant and equipment policy',
        'payables_policy' => 'Payables policy',
        'revenue_policy' => 'Revenue recognition policy',
        'expense_policy' => 'Expense recognition policy',
        'income_tax_policy' => 'Income tax policy',
        'events_policy' => 'Events after the reporting period policy',
        'deficiency_tax_assessments' => 'Deficiency tax assessments',
        'tax_cases' => 'Tax cases',
        'subsequent_events' => 'Events after the reporting period',
        'approval_body' => 'Approving body / authorized person',
        'management_statement' => 'Management responsibility statement (optional replacement)',
        'share_rights' => 'Share classes, rights and restrictions',
    ];

    public const TAX_FIELDS = [
        'output_base' => 'Sales base associated with recorded output VAT',
        'output_vat' => 'Output VAT recorded during the year',
        'input_opening' => 'Input VAT, beginning of year',
        'goods_resale' => 'Goods for resale / manufacture / further processing',
        'goods_other' => 'Goods other than for resale or manufacture',
        'capital_amortized' => 'Capital goods subject to amortization',
        'capital_other' => 'Capital goods not subject to amortization',
        'services_cost' => 'Services lodged under cost of goods sold',
        'services_other' => 'Services lodged under other accounts',
        'unclassified' => 'Input VAT awaiting classification',
        'input_adjustments' => 'Claims, credits and other adjustments (signed)',
        'dst' => 'Documentary stamp tax expense recorded',
        'other_taxes' => 'Other local and national taxes recorded',
        'ewt' => 'Expanded withholding tax withheld',
        'compensation' => 'Compensation tax withheld',
        'withholding_vat' => 'Withholding VAT withheld',
    ];

    /** @param array<string, mixed> $profile @return array<string, mixed> */
    public function defaults(array $profile): array
    {
        $profile = SecRegistrationRules::withDisclosureDefaults($profile);

        return [
            'business_activity' => $profile['business_activity'] ?? $profile['line_of_business'] ?? '',
            'reporting_framework' => '',
            'measurement_basis' => 'The financial statements are prepared on the historical cost basis, except where an alternative basis is disclosed in the relevant accounting policy.',
            'estimates' => 'Preparation of the financial statements requires management to make estimates, judgments and assumptions affecting reported assets, liabilities, income and expenses. Actual results may differ from those estimates.',
            'going_concern_statement' => $profile['going_concern_statement'],
            'cash_policy' => 'Cash comprises cash on hand and demand deposits. Cash equivalents are short-term, highly liquid investments readily convertible to known amounts of cash and subject to insignificant risk of changes in value, normally with original maturities of three months or less.',
            'receivables_policy' => 'Trade and other receivables are initially recognized at the transaction price and subsequently measured at amortized cost, less any allowance for impairment. Management reviews collectibility at each reporting date.',
            'ppe_policy' => 'Property, plant and equipment are carried at cost less accumulated depreciation and impairment losses. Depreciation methods, useful lives and residual values are reviewed at each reporting date. Management must disclose the methods and useful lives applicable to the entity.',
            'payables_policy' => 'Trade and other payables represent obligations for goods and services received and unpaid at the reporting date. They are initially recognized at transaction price and subsequently measured at amortized cost.',
            'revenue_policy' => 'Revenue is recognized when the applicable recognition conditions are met and the consideration can be measured reliably. Revenue from services is recognized as services are rendered; revenue from goods follows the transfer of the relevant risks, rewards or control under the applicable reporting framework.',
            'expense_policy' => 'Expenses are recognized in the period to which they relate, on an accrual basis, applying the matching principle where appropriate.',
            'income_tax_policy' => 'Income tax expense includes current tax and deferred tax where applicable under the adopted reporting framework. The amounts recorded are based on the entity-specific tax computation and supporting schedules. This package does not determine tax rates, eligibility or taxable income automatically.',
            'events_policy' => 'Events after the reporting date providing evidence of conditions existing at that date are reflected through adjusting entries. Material non-adjusting events are disclosed with their estimated financial effect where practicable.',
            'deficiency_tax_assessments' => $profile['deficiency_tax_assessments'],
            'tax_cases' => $profile['tax_cases'],
            'subsequent_events' => '', 'approval_body' => '', 'approval_date' => '',
            'management_statement' => '', 'president_name' => $profile['signatory_name'] ?? '',
            'ceo_name' => '', 'cfo_name' => $profile['treasurer_name'] ?? '', 'share_rights' => '',
            'management_confirmed' => false, 'tax_overrides' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, array<string, mixed>>  $matrices
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    public function build(int $companyId, int $year, array $profile, array $matrices, array $inputs): array
    {
        $inputs = array_replace($this->defaults($profile), $inputs);
        foreach (SecRegistrationRules::DISCLOSURE_DEFAULTS as $key => $default) {
            if (trim((string) ($inputs[$key] ?? '')) === '') {
                $inputs[$key] = $default;
            }
        }
        $warnings = [];
        foreach (['business_activity', 'reporting_framework', 'approval_body', 'approval_date', 'subsequent_events', 'president_name', 'ceo_name', 'cfo_name'] as $key) {
            if (trim((string) ($inputs[$key] ?? '')) === '') {
                $warnings[] = 'Complete '.(self::TEXT_FIELDS[$key] ?? str_replace('_', ' ', $key)).' in Package details.';
            }
        }
        if (! ($inputs['management_confirmed'] ?? false)) {
            $warnings[] = 'Management has not confirmed the responsibility statement and disclosures.';
        }
        $tax = [];
        foreach (['current' => $year, 'previous' => $year - 1] as $period => $taxYear) {
            $tax[$period] = $this->taxSchedule($companyId, $taxYear);
            $overrides = $inputs['tax_overrides'][$period] ?? [];
            foreach ($overrides as $key => $value) {
                if (isset(self::TAX_FIELDS[$key]) && $value !== null && $value !== '') {
                    $tax[$period][$key] = round((float) $value, 2);
                    $tax[$period]['overridden'][] = $key;
                }
            }
            $tax[$period]['input_closing'] = round($tax[$period]['input_opening'] + array_sum(array_intersect_key($tax[$period], array_flip(['goods_resale', 'goods_other', 'capital_amortized', 'capital_other', 'services_cost', 'services_other', 'unclassified', 'input_adjustments']))), 2);
            $tax[$period]['input_difference'] = round($tax[$period]['input_closing'] - $tax[$period]['ledger_input_closing'], 2);
            if (abs($tax[$period]['unclassified']) >= 0.005) {
                $warnings[] = $taxYear.': classify input VAT awaiting classification using the annual tax schedule overrides.';
            }
            if (abs($tax[$period]['input_difference']) >= 0.005) {
                $warnings[] = $taxYear.': the input VAT schedule does not reconcile to the ledger.';
            }
        }
        $address = implode(', ', array_filter(array_map(fn (string $key): string => trim((string) ($profile[$key] ?? '')), ['building', 'street', 'barangay', 'city', 'province', 'zip_code'])));
        if ($address === '') {
            $warnings[] = 'The client registered address is missing in Settings.';
        }
        if (($profile['entity_type'] ?? 'corporation') !== 'individual') {
            foreach (['sec_registration_number', 'registration_date', 'authorized_shares', 'issued_outstanding_shares'] as $field) {
                if (! isset($profile[$field]) || $profile[$field] === '') {
                    $warnings[] = 'Complete '.str_replace('_', ' ', $field).' in Settings → SEC Registration.';
                }
            }
        }
        $preparedBy = ['user_id' => auth()->id(), 'name' => auth()->user()?->name];
        $checksum = hash('sha256', json_encode([$companyId, $year, $profile, $matrices, $inputs, $tax, $preparedBy], JSON_THROW_ON_ERROR));

        return ['prepared_by' => $preparedBy, 'version' => 1, 'profile' => $profile, 'address' => $address, 'inputs' => $inputs, 'tax' => $tax, 'warnings' => $warnings, 'checksum' => $checksum];
    }

    /** @return array<string, mixed> */
    private function taxSchedule(int $companyId, int $year): array
    {
        $result = array_fill_keys(array_keys(self::TAX_FIELDS), 0.0);
        $result['overridden'] = [];
        $base = fn (): Builder => DB::table('journal_entry_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->join('chart_accounts as a', 'a.id', '=', 'l.chart_account_id')
            ->where('l.company_id', $companyId)->where('j.company_id', $companyId)->where('a.company_id', $companyId)->where('j.status', 'posted');
        $inputBalances = $base()->where('j.transaction_date', '<=', $year.'-12-31')->whereRaw('LOWER(a.name) LIKE ?', ['%input vat%'])
            ->selectRaw('SUM(CASE WHEN j.transaction_date < ? THEN l.debit - l.credit ELSE 0 END) as opening, SUM(l.debit - l.credit) as closing', [$year.'-01-01'])->first();
        $result['input_opening'] = round((float) $inputBalances->opening, 2);
        $result['ledger_input_closing'] = round((float) $inputBalances->closing, 2);
        $entry = null;
        $rows = [];
        $flush = function () use (&$rows, &$result): void {
            $input = 0.0;
            $output = 0.0;
            $sales = 0.0;
            $categories = [];
            foreach ($rows as $row) {
                $name = strtolower($row->name);
                $net = (float) $row->debit - (float) $row->credit;
                if (str_contains($name, 'input vat')) {
                    $input += (float) $row->debit;
                    $result['input_adjustments'] -= (float) $row->credit;
                } elseif (str_contains($name, 'output vat')) {
                    $output += (float) $row->credit;
                } elseif (in_array(strtolower($row->type), ['income', 'revenue'])) {
                    $sales -= $net;
                } elseif (! $row->is_cash_bank && in_array(strtolower($row->type), ['asset', 'expense']) && $net > 0) {
                    $category = match ($row->relief_category) {
                        'goods' => str_contains($name, 'inventory') ? 'goods_resale' : 'goods_other',
                        'services' => preg_match('/cost of (goods|sales)/i', $row->statement_section) || preg_match('/advertising|handling|marketing|outsource|representation/i', $name) ? 'services_cost' : 'services_other',
                        default => 'unclassified',
                    };
                    $categories[$category] = true;
                }
                if (strtolower($row->type) === 'liability') {
                    if (preg_match('/expanded.*(wht|withholding)|ewt payable/', $name)) {
                        $result['ewt'] += (float) $row->credit;
                    } elseif (preg_match('/(wht|withholding).*compensation/', $name)) {
                        $result['compensation'] += (float) $row->credit;
                    } elseif (preg_match('/withholding vat|wvat/', $name)) {
                        $result['withholding_vat'] += (float) $row->credit;
                    }
                }
                if (strtolower($row->type) === 'expense') {
                    if (preg_match('/documentary stamp|\bdst\b/', $name)) {
                        $result['dst'] += $net;
                    } elseif (preg_match('/local tax|national tax|business tax|taxes and licenses/', $name)) {
                        $result['other_taxes'] += $net;
                    }
                }
            }
            $category = count($categories) === 1 ? array_key_first($categories) : 'unclassified';
            $result[$category] += $input;
            $result['output_vat'] += $output;
            if ($output !== 0.0) {
                $result['output_base'] += $sales;
            }
            $rows = [];
        };
        foreach ($base()->whereBetween('j.transaction_date', [$year.'-01-01', $year.'-12-31'])->select(['j.id as journal_id', 'a.name', 'a.type', 'a.statement_section', 'a.relief_category', 'a.is_cash_bank', 'l.debit', 'l.credit'])->orderBy('j.id')->orderBy('l.id')->cursor() as $row) {
            if ($entry !== $row->journal_id) {
                $flush();
                $entry = $row->journal_id;
            }
            $rows[] = $row;
        }
        $flush();
        foreach (self::TAX_FIELDS as $key => $label) {
            $result[$key] = round($result[$key], 2);
        }

        return $result;
    }
}
