<?php

namespace App\Http\Requests;

use App\Models\ChartAccount;
use App\Models\TaxCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->session()->get('preview_access') && $this->session()->has('company_setup');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $text = ['nullable', 'string', 'max:255'];
        $tin = ['nullable', 'string', 'regex:/^(?:[0-9]{9}|[0-9]{14}|[0-9]{3}-[0-9]{3}-[0-9]{3}(?:-[0-9]{5})?)$/D'];

        return match ($this->route('section')) {
            'company' => [
                'company_name' => ['required', 'string', $this->user() ? 'max:200' : 'max:255'],
                'registered_name' => ['required', 'string', 'max:255'],
                'trade_name' => $text,
                'tin' => ['required', 'string', 'regex:/^(?:[0-9]{14}|[0-9]{3}-[0-9]{3}-[0-9]{3}-[0-9]{5})$/D'],
                'entity_type' => ['required', Rule::in(['corporation', 'individual'])],
                'vat_type' => ['required', Rule::in(['vat', 'non_vat'])],
            ],
            'address' => [
                'building' => $text, 'street' => $text, 'barangay' => $text,
                'city' => $text, 'province' => $text,
                'zip_code' => ['nullable', 'regex:/^\d{4}$/'],
            ],
            'bir' => [
                'rdo' => ['nullable', 'regex:/^(\d{3}|\d{2,3}[A-Za-z])$/'],
                'accounting_period' => ['required', Rule::in(['calendar', 'fiscal'])],
                'fiscal_end_month' => ['nullable', 'required_if:accounting_period,fiscal', 'integer', 'between:1,12'],
                'contact_number' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{7,30}$/'],
                'contact_email' => ['nullable', 'email', 'max:255'],
                'signatory_name' => $text, 'signatory_title' => $text, 'signatory_tin' => $tin,
                'agent_category' => ['required', Rule::in(['private', 'government'])],
                'top_withholding_agent' => ['required', 'boolean'],
                'taxpayer_size' => ['required', Rule::in(['micro', 'small', 'medium', 'large'])],
                'registration_date' => ['nullable', 'date_format:Y-m-d'],
                'corporate_atc' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/'],
                'treasurer_name' => $text, 'treasurer_title' => $text, 'treasurer_tin' => $tin,
                'accreditation_number' => $text,
                'accreditation_issued' => ['nullable', 'date_format:Y-m-d'],
                'accreditation_expiry' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:accreditation_issued'],
            ],
            'sheets' => [
                'payroll' => ['required', 'boolean'], 'cwt' => ['required', 'boolean'],
                'wtp' => ['required', 'boolean'], 'ppe' => ['required', 'boolean'],
                'payroll_automation' => ['sometimes', 'boolean'],
                'payroll_frequency' => ['sometimes', Rule::in(['monthly', 'semi_monthly', 'weekly'])],
                'sales_hide_cwt' => ['sometimes', 'boolean'],
                'sales_hide_vat_cwt' => ['sometimes', 'boolean'],
                'sales_default_terms' => ['sometimes', Rule::in(['Cash', 'On Account'])],
                'sales_default_classification' => ['sometimes', Rule::in(['Regular', 'Top Withholding Agent', 'Government', 'ZeroRated', 'PEZA'])],
                'sales_default_bank_code' => ['nullable', 'string', Rule::exists('chart_accounts', 'code')->where(fn ($query) => $query->where('workspace_key', ChartAccount::workspaceKey($this->session()->get('company_setup')))->where('archived', false)->where('is_cash_bank', true))],
                'purchase_default_bank_code' => ['nullable', 'string', Rule::exists('chart_accounts', 'code')->where(fn ($query) => $query->where('workspace_key', ChartAccount::workspaceKey($this->session()->get('company_setup')))->where('archived', false)->where('is_cash_bank', true))],
                'purchase_hide_wvat' => ['sometimes', 'boolean'],
                'purchase_default_terms' => ['sometimes', Rule::in(['Cash', 'On Account'])],
                'purchase_default_ewt_code' => ['nullable', Rule::in($this->withholdingCodes('Income-tax CWT'))],
                'purchase_default_wvat_code' => ['nullable', Rule::in($this->withholdingCodes('VAT Withholding'))],
                'sales_default_cwt_code' => ['nullable', Rule::in($this->withholdingCodes('Income-tax CWT'))],
                'sales_default_vat_cwt_code' => ['nullable', Rule::in($this->withholdingCodes('VAT Withholding'))],
                'default_bank_code' => ['nullable', 'string', Rule::exists('chart_accounts', 'code')->where(fn ($query) => $query->where('workspace_key', ChartAccount::workspaceKey($this->session()->get('company_setup')))->where('archived', false)->where('is_cash_bank', true))],
            ],
            default => [],
        };
    }

    /** @return list<string> */
    private function withholdingCodes(string $category): array
    {
        return collect(TaxCode::atcCatalog(ChartAccount::workspaceKey($this->session()->get('company_setup', []))))
            ->filter(fn (array $code): bool => $code['category'] === $category && ! ($code['archived'] ?? false))->pluck('code')->all();
    }
}
