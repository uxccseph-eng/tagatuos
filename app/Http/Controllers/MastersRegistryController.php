<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveMasterRecordRequest;
use App\MasterCode;
use App\Models\BankAccount;
use App\Models\ChartAccount;
use App\Models\Employee;
use App\Models\Party;
use App\Models\TaxCode;
use App\Tin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MastersRegistryController extends Controller
{
    public function index(Request $request, string $registry): View|RedirectResponse
    {
        if (! $request->session()->get('preview_access')) {
            return to_route('login');
        }
        if (! $request->session()->has('company_setup')) {
            return to_route('company.setup');
        }
        $company = $request->session()->get('company_setup');
        $model = match ($registry) {
            'parties' => Party::class, 'employees' => Employee::class, 'bank-accounts' => BankAccount::class, default => TaxCode::class
        };
        $workspaceKey = ChartAccount::workspaceKey($company);
        if (! in_array($registry, ['bank-accounts', 'atc-codes', 'employees'])) {
            $model::seedWorkspace($workspaceKey);
        }

        $glAccounts = [];
        if ($registry === 'bank-accounts') {
            ChartAccount::seedWorkspace($workspaceKey);
            $glAccounts = ChartAccount::query()->where('workspace_key', $workspaceKey)->where('is_cash_bank', true)->where('archived', false)->orderBy('code')->get();
        }
        $records = $registry === 'atc-codes'
            ? TaxCode::atcCatalog($workspaceKey)
            : $model::query()->where('workspace_key', $workspaceKey)->when($registry === 'bank-accounts', fn (Builder $query): Builder => $query->with('chartAccount'))->orderBy($registry === 'bank-accounts' ? 'name' : 'code')->get();

        if ($registry === 'parties') {
            $records->each(function (Party $party): void {
                $party->tin = Tin::format($party->tin);
            });
        }

        return view('masters-registry', ['company' => $company, 'registry' => $registry, 'records' => $records, 'glAccounts' => $glAccounts]);
    }

    public function store(SaveMasterRecordRequest $request, string $registry): JsonResponse
    {
        $model = match ($registry) {
            'parties' => Party::class, 'employees' => Employee::class, 'bank-accounts' => BankAccount::class, default => TaxCode::class
        };
        $workspaceKey = ChartAccount::workspaceKey($request->session()->get('company_setup'));
        if (! in_array($registry, ['bank-accounts', 'employees'], true)) {
            $model::seedWorkspace($workspaceKey);
        }

        $data = $request->validated();
        for ($attempt = 0; ; $attempt++) {
            try {
                $record = $model::query()->create([...$data, 'workspace_key' => $workspaceKey]);
                break;
            } catch (UniqueConstraintViolationException $exception) {
                if (! $request->attributes->get('generated_code') || $attempt >= 4) {
                    throw $exception;
                }
                $data['code'] = MasterCode::registry($workspaceKey, $registry, $data['classification'] ?? '');
            }
        }

        return response()->json(['record' => $record], 201);
    }

    public function update(SaveMasterRecordRequest $request, string $registry, int $record): JsonResponse
    {
        $item = $this->record($request, $registry, $record);
        $item->update($request->validated());

        return response()->json(['record' => $item->fresh()]);
    }

    public function archive(Request $request, string $registry, int $record): JsonResponse
    {
        $item = $this->record($request, $registry, $record);
        $item->update(['archived' => true]);

        return response()->json(['record' => $item->fresh()]);
    }

    public function restore(Request $request, string $registry, int $record): JsonResponse
    {
        $item = $this->record($request, $registry, $record);
        $item->update(['archived' => false]);

        return response()->json(['record' => $item->fresh()]);
    }

    private function record(Request $request, string $registry, int $record): Model
    {
        abort_unless($request->session()->get('preview_access') && $request->session()->has('company_setup'), 403);
        $model = match ($registry) {
            'parties' => Party::class, 'employees' => Employee::class, 'bank-accounts' => BankAccount::class, default => TaxCode::class
        };

        return $model::query()->where('workspace_key', ChartAccount::workspaceKey($request->session()->get('company_setup')))->findOrFail($record);
    }
}
