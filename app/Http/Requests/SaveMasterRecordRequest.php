<?php

namespace App\Http\Requests;

use App\MasterCode;
use App\Models\BankAccount;
use App\Models\ChartAccount;
use App\Models\Employee;
use App\Models\Party;
use App\Models\TaxCode;
use App\Tin;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SaveMasterRecordRequest extends FormRequest
{
    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name_key' => 'employee name', 'tin_key' => 'employee TIN'];
    }

    public function authorize(): bool
    {
        return $this->session()->get('preview_access') && $this->session()->has('company_setup');
    }

    protected function prepareForValidation(): void
    {
        if ($this->routeIs('masters.registry.store') && $this->route('registry') !== 'bank-accounts' && $this->session()->get('preview_access') && $this->session()->has('company_setup') && trim((string) $this->input('code')) === '') {
            $this->merge(['code' => MasterCode::registry(ChartAccount::workspaceKey($this->session()->get('company_setup')), (string) $this->route('registry'), (string) $this->input('classification'))]);
            $this->attributes->set('generated_code', true);
        }
        if (in_array($this->route('registry'), ['parties', 'employees'], true) && is_string($this->input('tin')) && ($this->route('registry') === 'employees' || strlen(preg_replace('/\D/', '', $this->input('tin'))) >= 12)) {
            $this->merge(['tin' => Tin::format($this->input('tin'))]);
        }
        if ($this->route('registry') === 'parties') {
            $saved = $this->route('record') ? Party::query()->where('workspace_key', ChartAccount::workspaceKey($this->session()->get('company_setup')))->findOrFail($this->route('record')) : null;
            $entityType = $this->input('entity_type') ?: ($saved?->entity_type ?? 'corporation');
            $this->merge(['entity_type' => $entityType]);
            $this->merge(['is_petty_cash' => $this->input('is_petty_cash', $saved?->is_petty_cash ?? false)]);
            if ($entityType === 'individual') {
                $names = [];
                foreach (['last_name', 'first_name', 'middle_name'] as $field) {
                    $value = $this->input($field, $saved?->{$field});
                    $names[$field] = is_string($value) ? Str::squish($value) : $value;
                }
                $this->merge($names);
                if (is_string($names['last_name']) && is_string($names['first_name']) && ($names['middle_name'] === null || is_string($names['middle_name']))) {
                    $this->merge(['name' => trim($names['last_name'].', '.$names['first_name'].' '.($names['middle_name'] ?? ''))]);
                }
            } elseif ($entityType === 'corporation') {
                $this->merge(['last_name' => null, 'first_name' => null, 'middle_name' => null]);
            }
        }
        if ($this->route('registry') === 'parties' && $this->hasAny(Party::ADDRESS_FIELDS)) {
            $parts = array_map(fn (string $field): string => is_string($this->input($field)) ? trim($this->input($field)) : '', Party::ADDRESS_FIELDS);
            if (array_filter($parts)) {
                $this->merge(['address' => implode(', ', array_filter($parts, fn (string $part): bool => $part !== ''))]);
            }
        }
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
        if ($this->route('registry') === 'employees') {
            $name = Str::squish((string) $this->input('name'));
            $tin = preg_replace('/\D/', '', (string) $this->input('tin'));
            $this->merge(['name' => $name, 'name_key' => hash('sha256', Str::lower($name)), 'tin_key' => preg_match('/[1-9]/', $tin) ? $tin : null, 'classification' => $this->input('classification') ?: 'Regular']);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->route('registry') === 'employees') {
            $workspaceKey = ChartAccount::workspaceKey($this->session()->get('company_setup'));
            $record = $this->route('record') ? Employee::query()->where('workspace_key', $workspaceKey)->findOrFail($this->route('record')) : null;

            return [
                'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique('employees')->where('workspace_key', $workspaceKey)->ignore($record?->id)],
                'name' => ['required', 'string', 'max:150'],
                'name_key' => ['required', Rule::unique('employees')->where('workspace_key', $workspaceKey)->ignore($record?->id)],
                'tin_key' => ['nullable', Rule::unique('employees')->where('workspace_key', $workspaceKey)->ignore($record?->id)],
                'classification' => ['required', Rule::in(['Regular', 'Probationary', 'Contractual', 'Part-time', 'Project-based', 'Seasonal'])],
                'tin' => ['nullable', 'regex:/^[0-9]{3}-[0-9]{3}-[0-9]{3}-(?:[0-9]{3}|[0-9]{5})$/D'],
                'email' => ['nullable', 'email', 'max:254'], 'phone' => ['nullable', 'string', 'max:40'], 'address' => ['nullable', 'string', 'max:500'],
                'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'hire_date' => ['nullable', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:hire_date'],
                'sss_no' => ['nullable', 'string', 'max:30'], 'philhealth_no' => ['nullable', 'string', 'max:30'], 'pagibig_no' => ['nullable', 'string', 'max:30'],
            ];
        }
        if ($this->route('registry') === 'bank-accounts') {
            $workspaceKey = ChartAccount::workspaceKey($this->session()->get('company_setup'));
            $record = $this->route('record') ? BankAccount::query()->where('workspace_key', $workspaceKey)->findOrFail($this->route('record')) : null;

            return [
                'name' => ['required', 'string', 'max:150', Rule::unique('bank_accounts')->where('workspace_key', $workspaceKey)->ignore($record?->id)],
                'bank' => ['nullable', 'string', 'max:150'], 'account_number' => ['nullable', 'string', 'max:60'],
                'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
                'chart_account_id' => ['required', 'integer', Rule::exists('chart_accounts', 'id')->where(fn (Builder $query): Builder => $query->where('workspace_key', $workspaceKey)->where('is_cash_bank', true)->where(function (Builder $query) use ($record): void {
                    $query->where('archived', false);
                    if ($record) {
                        $query->orWhere('id', $record->chart_account_id);
                    }
                }))],
            ];
        }
        $party = $this->route('registry') === 'parties';
        $model = $party ? Party::class : TaxCode::class;
        $workspaceKey = ChartAccount::workspaceKey($this->session()->get('company_setup'));
        $record = $this->route('record') ? $model::query()->where('workspace_key', $workspaceKey)->findOrFail($this->route('record')) : null;
        $common = ['code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique($party ? 'parties' : 'tax_codes')->where('workspace_key', $workspaceKey)->ignore($record?->id), Rule::when(! $record, [Rule::notIn(array_column($model::defaults(), 'code'))])], 'name' => ['required', 'string', 'max:150']];

        $rules = [...$common, ...($party ? ['classification' => ['required', Rule::in(['Customer', 'Supplier', 'Both'])], 'tin' => ['required', 'regex:/^[0-9]{3}-[0-9]{3}-[0-9]{3}-[0-9]{5}$/D'], 'description' => ['nullable', 'string', 'max:500'], 'email' => ['nullable', 'email', 'max:254'], 'phone' => ['nullable', 'string', 'max:40'], 'address' => ['required', 'string', 'max:500']] : ['kind' => ['required', Rule::in(['VAT', 'Exempt', 'Zero Rated', 'Percentage Tax', 'Withholding'])], 'rate' => ['required', 'numeric', 'between:0,100', 'decimal:0,4'], 'atc' => ['nullable', 'regex:/^[A-Za-z]{2}[0-9]{3}$/']])];
        if ($party) {
            $pettyCash = $this->boolean('is_petty_cash');
            $rules['is_petty_cash'] = ['required', 'boolean'];
            if ($pettyCash) {
                $rules['classification'] = ['required', Rule::in(['Supplier'])];
                $rules['tin'] = ['nullable', 'regex:/^[0-9]{3}-[0-9]{3}-[0-9]{3}-[0-9]{5}$/D'];
                $rules['address'] = ['nullable', 'string', 'max:500'];
            }
            $rules['entity_type'] = ['required', Rule::in(['individual', 'corporation'])];
            foreach (['last_name', 'first_name', 'middle_name'] as $field) {
                $rules[$field] = ['nullable', 'string', 'max:30'];
                if ($field !== 'middle_name' && $this->input('entity_type') === 'individual') {
                    $rules[$field][] = 'required';
                }
            }
            $structured = collect(Party::ADDRESS_FIELDS)->contains(fn (string $field): bool => $this->filled($field));
            foreach (['building', 'street', 'barangay', 'city', 'province'] as $field) {
                $rules[$field] = ['nullable', 'string', 'max:255'];
            }
            $rules['zip_code'] = ['nullable', 'regex:/^[0-9]{4}$/D'];
            if ($structured && ! $pettyCash) {
                $rules['city'][] = 'required';
                $rules['province'][] = 'required';
            }
        }
        if (! $party && $this->input('kind') === 'Withholding' && $this->input('code') === $this->input('atc') && ! $record) {
            $rules['code'][] = Rule::notIn(array_column(json_decode(file_get_contents(database_path('seeders/atc-codes.json')), true, 512, JSON_THROW_ON_ERROR), 'code'));
        }

        return $rules;
    }
}
