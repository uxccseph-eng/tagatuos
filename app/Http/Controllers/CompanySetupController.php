<?php

namespace App\Http\Controllers;

use App\Models\ChartAccount;
use App\Models\CompanySetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CompanySetupController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if (! $request->session()->get('preview_access')) {
            return to_route('login');
        }

        return view('company-setup', [
            'company' => $request->session()->get('company_setup', []),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $request->session()->get('preview_access')) {
            return to_route('login');
        }

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'preview_ack' => ['accepted'],
            'vat_type' => ['required', Rule::in(['vat', 'non_vat'])],
            'entity_type' => ['required', Rule::in(['corporation', 'individual'])],
            'company_name' => ['required', 'string', 'max:255'],
            'registered_name' => ['nullable', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'tin' => ['required', 'string', 'regex:/^(?:[0-9]{14}|[0-9]{3}-[0-9]{3}-[0-9]{3}-[0-9]{5})$/D'],
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{7,30}$/'],
            'rdo' => ['nullable', 'string', 'regex:/^\d{3}$/'],
            'accounting_period' => ['required', Rule::in(['calendar', 'fiscal'])],
            'fiscal_end_month' => ['required_if:accounting_period,fiscal', 'nullable', 'integer', 'between:1,12'],
            'building' => ['nullable', 'string', 'max:255'],
            'street' => ['nullable', 'string', 'max:255'],
            'barangay' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'zip_code' => ['nullable', 'string', 'regex:/^\d{4}$/'],
            'payroll' => ['required', Rule::in(['yes', 'no'])],
            'supplier_tax' => ['required', Rule::in(['none', 'income', 'vat', 'both'])],
            'customer_tax' => ['required', Rule::in(['yes', 'no'])],
            'ppe' => ['required', Rule::in(['yes', 'no'])],
        ]);

        if ($data['accounting_period'] === 'calendar') {
            $data['fiscal_end_month'] = null;
        }

        $digits = preg_replace('/\D/', '', $data['tin']);
        $data['tin'] = substr($digits, 0, 9);
        $data['branch_code'] = substr($digits, 9, 5);
        if (Schema::hasTable('company_settings')) {
            $settings = CompanySetting::query()->where('lookup_key', ChartAccount::workspaceKey($data))->first();
            if ($settings) {
                $data = array_replace($data, $settings->profile, ['full_name' => $data['full_name'], 'sheet_settings' => $settings->preferences, 'workspace_key' => $settings->workspace_key]);
            }
        }
        $request->session()->put('company_setup', $data);

        return to_route('dashboard')->with('status', 'Company setup saved. Welcome to your local workspace preview.');
    }
}
