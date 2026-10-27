<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveChartAccountRequest;
use App\MasterCode;
use App\Models\BankAccount;
use App\Models\ChartAccount;
use App\Models\DepreciationRun;
use App\Models\Party;
use App\Models\TaxCode;
use App\Services\CompanyLedgerService;
use App\Tin;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Js;
use Illuminate\View\View;

class MastersController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! $request->session()->get('preview_access')) {
            return to_route('login');
        }
        $company = $request->session()->get('company_setup');
        if (! $company) {
            return to_route('company.setup');
        }
        $workspaceKey = ChartAccount::workspaceKey($company);
        ChartAccount::seedWorkspace($workspaceKey);
        ChartAccount::syncSalesRelief($request->session()->get('company_setup'));

        return view('masters', ['company' => $company, 'accounts' => ChartAccount::query()->where('workspace_key', $workspaceKey)->orderBy('code')->get(), 'ewtAtcCodes' => array_values(array_filter(TaxCode::atcCatalog($workspaceKey), fn (array $atc): bool => $atc['category'] === 'Income-tax CWT'))]);
    }

    public function reliefCatalog(Request $request): JsonResponse
    {
        abort_unless($request->session()->get('preview_access') && $request->session()->has('company_setup'), 403);

        ChartAccount::syncSalesRelief($request->session()->get('company_setup'));

        return response()->json([
            'categories' => ChartAccount::RELIEF_CATEGORIES,
            'accounts' => ChartAccount::query()->where('workspace_key', ChartAccount::workspaceKey($request->session()->get('company_setup')))->orderBy('code')->get(['code', 'name', 'type', 'archived', 'relief_category']),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function catalog(Request $request): Response
    {
        abort_unless($request->session()->get('preview_access') && $request->session()->has('company_setup'), 403);
        $accounts = ChartAccount::defaults();
        if (Schema::hasTable('chart_accounts')) {
            $workspaceKey = ChartAccount::workspaceKey($request->session()->get('company_setup'));
            ChartAccount::seedWorkspace($workspaceKey);
            ChartAccount::syncSalesRelief($request->session()->get('company_setup'));
            $accounts = ChartAccount::query()->where('workspace_key', $workspaceKey)->orderBy('code')->get()->toArray();
        }

        $masters = ['reliefCategories' => ChartAccount::RELIEF_CATEGORIES, 'parties' => Party::defaults(), 'taxCodes' => TaxCode::defaults(), 'banks' => [], 'atcCodes' => json_decode(file_get_contents(database_path('seeders/atc-codes.json')), true, 512, JSON_THROW_ON_ERROR)];
        foreach (['parties' => Party::class, 'taxCodes' => TaxCode::class] as $key => $model) {
            if (Schema::hasTable((new $model)->getTable())) {
                $workspaceKey = ChartAccount::workspaceKey($request->session()->get('company_setup'));
                $model::seedWorkspace($workspaceKey);
                $masters[$key] = $model::query()->where('workspace_key', $workspaceKey)->orderBy('code')->get()->toArray();
            }
        }
        if (Schema::hasTable('bank_accounts')) {
            $masters['banks'] = BankAccount::query()->where('workspace_key', ChartAccount::workspaceKey($request->session()->get('company_setup')))->orderBy('name')->get(['id', 'name', 'chart_account_id', 'currency', 'archived'])->toArray();
        }

        if (Schema::hasTable('tax_codes')) {
            $masters['atcCodes'] = TaxCode::atcCatalog(ChartAccount::workspaceKey($request->session()->get('company_setup')));
        }
        $masters['parties'] = array_map(function (array $party): array {
            if (! empty($party['tin'])) {
                $party['tin'] = Tin::format($party['tin']);
            }

            return $party;
        }, $masters['parties']);
        $masters['csrfToken'] = csrf_token();
        $settings = $request->session()->get('company_setup.sheet_settings', []);
        $defaultBank = collect($accounts)->first(fn (array $account): bool => $account['code'] === ($settings['default_bank_code'] ?? null) && ($account['is_cash_bank'] ?? false) && ! ($account['archived'] ?? false));
        $masters['preferences'] = ['defaultBank' => $defaultBank ? $defaultBank['code'].' · '.$defaultBank['name'] : null];
        $masters['endpoints'] = ['accounts' => route('masters.accounts.store'), 'parties' => route('masters.registry.store', ['registry' => 'parties']), 'tax' => route('masters.registry.store', ['registry' => 'tax-codes']), 'banks' => route('masters.registry.store', ['registry' => 'bank-accounts'])];

        $canReadJournals = ! $request->user() || array_intersect($request->attributes->get('company_allowed_tabs', []), ['books', 'reports', 'lapsing']) !== [];
        $depreciation = $canReadJournals && Schema::hasTable('depreciation_runs') ? DepreciationRun::query()->where('workspace_key', ChartAccount::workspaceKey($request->session()->get('company_setup')))->orderBy('period')->pluck('journal')->all() : [];

        $company = $request->attributes->get('active_company');
        $database = $company && array_intersect($request->attributes->get('company_allowed_tabs', []), ['sheets', 'books', 'reports', 'tax-reports', 'lapsing']) !== []
            ? [...app(CompanyLedgerService::class)->snapshotFor($company, $request->attributes->get('company_allowed_tabs', [])), 'url' => route('sheets.database.save'), 'csrf' => csrf_token(), 'canWrite' => in_array('sheets', $request->attributes->get('company_allowed_tabs', []), true)]
            : null;

        return response('window.CompanyLedgerDatabase = '.Js::encode($database).';window.WorkspaceAccounts = '.Js::encode($accounts).';window.WorkspaceMasters = '.Js::encode($masters).';window.WorkspaceDepreciationJournals = '.Js::encode($depreciation).';', 200, ['Content-Type' => 'application/javascript', 'Cache-Control' => 'no-store, private']);
    }

    public function store(SaveChartAccountRequest $request): JsonResponse
    {
        $workspaceKey = ChartAccount::workspaceKey($request->session()->get('company_setup'));
        ChartAccount::seedWorkspace($workspaceKey);
        ChartAccount::syncSalesRelief($request->session()->get('company_setup'));
        $data = $request->validated();
        for ($attempt = 0; ; $attempt++) {
            try {
                $account = ChartAccount::query()->create([...$data, 'workspace_key' => $workspaceKey]);
                break;
            } catch (UniqueConstraintViolationException $exception) {
                if (! $request->attributes->get('generated_code') || $attempt >= 4) {
                    throw $exception;
                }
                $data['code'] = MasterCode::account($workspaceKey, $data['type']);
            }
        }

        return response()->json(['account' => $account], 201);
    }

    public function update(SaveChartAccountRequest $request, int $account): JsonResponse
    {
        $record = $this->account($request, $account);
        $record->update($request->validated());

        return response()->json(['account' => $record->fresh()]);
    }

    public function archive(Request $request, int $account): JsonResponse
    {
        $record = $this->account($request, $account);
        $record->update(['archived' => true]);

        return response()->json(['account' => $record->fresh()]);
    }

    public function restore(Request $request, int $account): JsonResponse
    {
        $record = $this->account($request, $account);
        $record->update(['archived' => false]);

        return response()->json(['account' => $record->fresh()]);
    }

    private function account(Request $request, int $account): ChartAccount
    {
        abort_unless($request->session()->get('preview_access') && $request->session()->has('company_setup'), 403);

        return ChartAccount::query()->where('workspace_key', ChartAccount::workspaceKey($request->session()->get('company_setup')))->findOrFail($account);
    }
}
