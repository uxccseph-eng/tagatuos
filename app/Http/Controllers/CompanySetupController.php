<?php

namespace App\Http\Controllers;

use App\Models\ChartAccount;
use App\Models\CompanySetting;
use App\RevenueDistrictOffices;
use App\SecRegistrationRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'company' => $request->session()->get('company_setup', $request->user() ? [
                'full_name' => $request->user()->name,
                'company_name' => $request->attributes->get('active_company')->name,
            ] : []),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $request->session()->get('preview_access')) {
            return to_route('login');
        }

        if (is_string($request->input('tin')) && preg_match('/^(?:[0-9]{14}|[0-9]{3}-[0-9]{3}-[0-9]{3}-[0-9]{5})$/D', $request->input('tin'))) {
            $legacyTin = preg_replace('/\D/', '', $request->input('tin'));
            $request->merge(['tin' => substr($legacyTin, 0, 9), 'branch_code' => substr($legacyTin, 9, 5)]);
        }

        if (is_string($request->input('rdo')) && trim($request->input('rdo')) !== '') {
            $request->merge(['rdo' => str_pad(strtoupper(trim($request->input('rdo'))), 3, '0', STR_PAD_LEFT)]);
        }

        $data = $request->validate([
            ...SecRegistrationRules::forCompany($request->input('entity_type') === 'individual', $request->filled('authorized_shares')),
            'full_name' => ['required', 'string', 'max:255'],
            'preview_ack' => ['accepted'],
            'vat_type' => ['required', Rule::in(['vat', 'non_vat'])],
            'entity_type' => ['required', Rule::in(['corporation', 'individual'])],
            'company_name' => ['required', 'string', $request->user() ? 'max:200' : 'max:255'],
            'registered_name' => ['nullable', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'tin' => ['required', 'string', 'regex:/^(?:[0-9]{9}|[0-9]{3}-[0-9]{3}-[0-9]{3})$/D'],
            'branch_code' => ['required', 'string', 'regex:/^[0-9]{5}$/D'],
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{7,30}$/'],
            'rdo' => ['nullable', 'string', Rule::in(array_keys(RevenueDistrictOffices::all()))],
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

        if ($request->user()) {
            $company = $request->attributes->get('active_company');
            $workspace = $company->workspaceKey();
            DB::transaction(function () use ($data, $company, $workspace): void {
                $settings = CompanySetting::query()->where('workspace_key', $workspace)->first();
                $profile = array_replace($settings?->profile ?? [], $data, [
                    'workspace_key' => $workspace, 'company_id' => $company->getKey(),
                ]);
                $profile = SecRegistrationRules::withDisclosureDefaults($profile);
                unset($profile['browser_key'], $profile['sheet_settings']);
                CompanySetting::query()->updateOrCreate(['workspace_key' => $workspace], [
                    'lookup_key' => $workspace, 'profile' => $profile, 'preferences' => $settings?->preferences ?? [],
                ]);
                $company->update(['name' => $data['company_name']]);
            });

            return to_route('dashboard')->with('status', 'Company registration saved to your company book.');
        }

        if (Schema::hasTable('company_settings')) {
            $settings = CompanySetting::withoutGlobalScope('company')->whereNull('company_id')->where('lookup_key', ChartAccount::workspaceKey($data))->first();
            if ($settings) {
                $data = array_replace($data, $settings->profile, ['full_name' => $data['full_name'], 'sheet_settings' => $settings->preferences, 'workspace_key' => $settings->workspace_key]);
            }
        }
        $data = SecRegistrationRules::withDisclosureDefaults($data);
        $request->session()->put('company_setup', $data);

        return to_route('dashboard')->with('status', 'Company setup saved. Welcome to your local workspace preview.');
    }
}
