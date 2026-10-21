<?php

namespace App\Http\Requests;

use App\Models\ChartAccount;
use App\Models\PpeAsset;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePpeAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->session()->get('preview_access') && $this->session()->has('company_setup');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        foreach (['cost' => 'cost_cents', 'residual' => 'residual_cents'] as $input => $target) {
            $value = trim((string) ($this->input($input) ?? '0'));
            $cents = null;
            if (preg_match('/^\d{1,9}(?:\.\d{1,2})?$/', $value)) {
                [$whole, $fraction] = array_pad(explode('.', $value), 2, '');
                $cents = ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');
            }
            $this->merge([$target => $cents]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $workspace = ChartAccount::workspaceKey($this->session()->get('company_setup'));
        $record = $this->route('asset') ? PpeAsset::query()->where('workspace_key', $workspace)->findOrFail($this->route('asset')) : null;
        $rules = [
            'code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique('ppe_assets')->where('workspace_key', $workspace)->ignore($record?->id)],
            'capitalization_reference' => ['nullable', 'string', 'max:150'],
            'name' => ['required', 'string', 'max:150'], 'category' => ['required', 'string', 'max:60'],
            'serial_number' => ['nullable', 'string', 'max:80', Rule::unique('ppe_assets')->where('workspace_key', $workspace)->ignore($record?->id)],
            'acquired_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2199-12-31'],
            'service_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:acquired_date', 'before_or_equal:2199-12-31'],
            'disposal_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:service_date', 'before_or_equal:2199-12-31'],
            'cost_cents' => ['required', 'integer', 'between:1,99999999999'],
            'residual_cents' => ['required', 'integer', 'min:0', 'lte:cost_cents'],
            'life_months' => ['required', 'integer', 'between:1,1200'], 'notes' => ['nullable', 'string', 'max:1000'],
        ];
        foreach (['asset_account_id' => 'Asset', 'accumulated_account_id' => 'Asset', 'expense_account_id' => 'Expense'] as $field => $type) {
            $rules[$field] = ['nullable', 'integer', Rule::exists('chart_accounts', 'id')->where('workspace_key', $workspace)->where('type', $type)->where(function (Builder $query) use ($record, $field): void {
                $query->where('archived', false);
                if ($record?->{$field}) {
                    $query->orWhere('id', $record->{$field});
                }
            })];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['cost_cents' => 'cost amount', 'residual_cents' => 'residual value'];
    }
}
