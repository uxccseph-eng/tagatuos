<?php

namespace App\Http\Requests;

use App\MasterCode;
use App\Models\ChartAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveChartAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->session()->get('preview_access') && $this->session()->has('company_setup');
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('type') === 'Income' && $this->session()->get('company_setup.vat_type') === 'vat') {
            $this->merge(['relief' => true, 'relief_category' => 'vatable_sales']);
        } elseif ($this->input('relief_category') === 'vatable_sales') {
            $this->merge(['relief' => false, 'relief_category' => null]);
        }
        if ($this->has('ewt') && ! $this->boolean('ewt')) {
            $this->merge(['ewt_atc_code' => null]);
        } elseif (is_string($this->input('ewt_atc_code'))) {
            $this->merge(['ewt_atc_code' => strtoupper(trim($this->input('ewt_atc_code')))]);
        }
        if ($this->routeIs('masters.accounts.store') && $this->session()->get('preview_access') && $this->session()->has('company_setup') && trim((string) $this->input('code')) === '') {
            $this->merge(['code' => MasterCode::account(ChartAccount::workspaceKey($this->session()->get('company_setup')), (string) $this->input('type'))]);
            $this->attributes->set('generated_code', true);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $workspaceKey = ChartAccount::workspaceKey($this->session()->get('company_setup'));
        $account = $this->route('account') ? ChartAccount::query()->where('workspace_key', $workspaceKey)->findOrFail($this->route('account')) : null;

        return [
            'code' => ['required', 'regex:/^\d{4}$/', $account ? Rule::in([$account->code]) : Rule::unique('chart_accounts')->where('workspace_key', $workspaceKey), Rule::when(! $account, [Rule::notIn(array_column(ChartAccount::defaults(), 'code'))])],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'allow_subsidiaries' => ['sometimes', 'boolean'],
            'cash_flow_section' => ['nullable', Rule::in(['Cash', 'Non-Cash', 'Operating', 'Investing', 'Financing'])],
            'cash_flow_subclass' => ['nullable', Rule::in(['current_asset', 'non_current_asset', 'accum_depr', 'current_liability', 'non_current_liability', 'equity', 'income', 'expense'])],
            'relief_category' => ['nullable', Rule::in(array_keys(ChartAccount::RELIEF_CATEGORIES))],
            'ewt_atc_code' => ['nullable', 'regex:/^[A-Za-z]{2}[0-9]{3}$/'],
            'type' => ['required', Rule::in(['Asset', 'Liability', 'Equity', 'Income', 'Expense'])],
            'normal_balance' => ['required', Rule::in(['Debit', 'Credit'])],
            'statement_section' => ['required', Rule::in(['Current Assets', 'Non-Current Assets', 'Current Liabilities', 'Non-Current Liabilities', 'Capital', 'Retained Earnings', 'Revenue', 'Cost of Goods Sold', 'General and Administrative Expenses', 'Depreciation and Amortization', 'Income Tax Expense'])],
            'is_cash_bank' => ['required', 'boolean'],
            'is_advance' => ['required', 'boolean'],
            'is_control' => ['required', 'boolean'],
            'subsidiary' => ['nullable', 'required_if:is_control,true', Rule::in(['Customer', 'Supplier', 'Employee', 'Bank', 'PPE', 'Other', 'Non-trade Supplier'])],
            'ppe_role' => ['nullable', Rule::in(['Asset', 'Accumulated depreciation', 'Depreciation expense'])],
            'ppe_category' => ['nullable', 'string', 'max:60'],
            'relief' => ['required', 'boolean'],
            'percentage_tax' => ['required', 'boolean'],
            'ewt' => ['required', 'boolean'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->boolean('is_cash_bank') && $this->input('type') !== 'Asset') {
                $validator->errors()->add('is_cash_bank', 'A cash or bank account must have the Asset type.');
            }
        }];
    }
}
