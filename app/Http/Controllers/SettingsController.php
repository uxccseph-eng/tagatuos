<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveSettingsRequest;
use App\Models\ChartAccount;
use App\Models\CompanySetting;
use App\Models\TaxCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(Request $request, string $section = 'company'): View|RedirectResponse
    {
        if (! $request->session()->get('preview_access')) {
            return to_route('login');
        }
        if (! $request->session()->has('company_setup')) {
            return to_route('company.setup');
        }
        $company = $request->session()->get('company_setup');
        $workspace = ChartAccount::workspaceKey($company);
        ChartAccount::seedWorkspace($workspace);

        return view('settings', [
            'company' => $company,
            'section' => $section,
            'accounts' => ChartAccount::query()->where('workspace_key', $workspace)->where('archived', false)->where('is_cash_bank', true)->orderBy('code')->get(),
            'withholdingCodes' => collect(TaxCode::atcCatalog($workspace))->filter(fn (array $code): bool => ! ($code['archived'] ?? false)),
        ]);
    }

    public function update(SaveSettingsRequest $request, string $section): RedirectResponse
    {
        $company = $request->session()->get('company_setup');
        $workspace = ChartAccount::workspaceKey($company);
        $browserKey = $company['browser_key'] ?? hash('sha256', $request->session()->getId().'|'.$company['company_name'].'|'.($company['tin'] ?? ''));
        $values = $request->validated();
        if ($section === 'company') {
            $digits = preg_replace('/\D/', '', $values['tin']);
            $values['tin'] = substr($digits, 0, 9);
            $values['branch_code'] = substr($digits, 9, 5);
        }
        if ($section === 'sheets') {
            foreach (['payroll', 'cwt', 'wtp', 'ppe'] as $key) {
                $values[$key] = (bool) $values[$key];
            }
            $values['payroll_automation'] = (bool) ($values['payroll_automation'] ?? false);
            $values['payroll_frequency'] = $values['payroll_frequency'] ?? 'monthly';
            foreach (['sales_hide_cwt', 'sales_hide_vat_cwt'] as $key) {
                if (array_key_exists($key, $values)) {
                    $values[$key] = (bool) $values[$key];
                }
            }
            $company['sheet_settings'] = array_replace($company['sheet_settings'] ?? [], $values);
        } else {
            $company = array_replace($company, $values);
        }
        if (($company['accounting_period'] ?? 'calendar') === 'calendar') {
            $company['fiscal_end_month'] = null;
        }
        unset($company['workspace_key'], $company['browser_key']);
        $lookup = ChartAccount::workspaceKey($company);
        if ($request->user()) {
            $lookup = $workspace;
        }
        $company['workspace_key'] = $workspace;
        $profile = $company;
        unset($profile['sheet_settings']);

        $activeCompany = $request->attributes->get('active_company');
        DB::transaction(function () use ($workspace, $lookup, $profile, $company, $activeCompany): void {
            $conflict = CompanySetting::query()->where('workspace_key', '!=', $workspace)->where(fn ($query) => $query->where('lookup_key', $lookup)->orWhere('workspace_key', $lookup))->exists();
            if ($lookup !== $workspace) {
                foreach (['chart_accounts', 'parties', 'tax_codes', 'bank_accounts', 'employees', 'ppe_assets', 'depreciation_runs'] as $table) {
                    if (Schema::hasTable($table) && DB::table($table)->where('workspace_key', $lookup)->exists()) {
                        $conflict = true;
                    }
                }
            }
            if ($conflict) {
                throw ValidationException::withMessages(['company_name' => 'This company name and TIN already belong to another workspace.']);
            }
            CompanySetting::query()->updateOrCreate(['workspace_key' => $workspace], [
                'lookup_key' => $lookup, 'profile' => $profile, 'preferences' => $company['sheet_settings'] ?? [],
            ]);
            if ($activeCompany) {
                $activeCompany->update(['name' => $company['company_name']]);
            }
        });
        ChartAccount::syncSalesRelief($company);
        $company['browser_key'] = $browserKey;
        $request->session()->put('company_setup', $company);

        return to_route('settings.section', ['section' => $section])->with('status', 'Settings saved.');
    }
}
