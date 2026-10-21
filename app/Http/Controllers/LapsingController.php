<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavePpeAssetRequest;
use App\Models\ChartAccount;
use App\Models\DepreciationRun;
use App\Models\PpeAsset;
use App\PpeDepreciation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LapsingController extends Controller
{
    public function masters(Request $request): View|RedirectResponse
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

        $snapshots = DepreciationRun::query()->where('workspace_key', $workspace)->orderBy('period')->get()->flatMap(fn (DepreciationRun $run): array => $run->snapshot['rows'])->keyBy('id');
        $assets = PpeAsset::query()->where('workspace_key', $workspace)->orderBy('code')->get();
        $assets->each(function (PpeAsset $asset) use ($snapshots): void {
            $snapshot = $snapshots->get($asset->id);
            $asset->setAttribute('basis_locked', $snapshot !== null);
            $asset->setAttribute('posted_net_cents', $asset->cost_cents - ($snapshot['closing_dep'] ?? 0));
            $asset->setAttribute('months_remaining', max(0, $asset->life_months - ($snapshot['months_charged'] ?? 0)));
        });

        return view('ppe-items', ['company' => $company, 'assets' => $assets, 'accounts' => ChartAccount::query()->where('workspace_key', $workspace)->where('archived', false)->whereIn('type', ['Asset', 'Expense'])->orderBy('code')->get()]);
    }

    public function index(Request $request): View|RedirectResponse
    {
        if (! $request->session()->get('preview_access')) {
            return to_route('login');
        }
        if (! $request->session()->has('company_setup')) {
            return to_route('company.setup');
        }

        return view('lapsing', ['company' => $request->session()->get('company_setup'), 'page' => $request->route('page') ?? 'run']);
    }

    public function preview(Request $request, PpeDepreciation $schedule): JsonResponse
    {
        abort_unless($request->session()->get('preview_access') && $request->session()->has('company_setup'), 403);
        $data = $request->validate(['period' => ['required', 'date_format:Y-m', 'regex:/^(19|20|21)\d{2}-\d{2}$/']]);

        return response()->json($schedule->preview(ChartAccount::workspaceKey($request->session()->get('company_setup')), $data['period']))->header('Cache-Control', 'no-store, private');
    }

    public function postRun(Request $request, PpeDepreciation $schedule): JsonResponse
    {
        abort_unless($request->session()->get('preview_access') && $request->session()->has('company_setup'), 403);
        $data = $request->validate(['period' => ['required', 'date_format:Y-m', 'regex:/^(19|20|21)\d{2}-\d{2}$/'], 'preview_hash' => ['required', 'string', 'size:64']]);
        $workspace = ChartAccount::workspaceKey($request->session()->get('company_setup'));
        $created = false;
        try {
            $run = DB::transaction(function () use ($request, $schedule, $workspace, $data, &$created): DepreciationRun {
                ChartAccount::query()->where('workspace_key', $workspace)->orderBy('id')->lockForUpdate()->first();
                PpeAsset::query()->where('workspace_key', $workspace)->orderBy('id')->lockForUpdate()->get();
                $existing = DepreciationRun::query()->where('workspace_key', $workspace)->where('period', $data['period'])->first();
                if ($existing) {
                    return $existing;
                }
                $preview = $schedule->preview($workspace, $data['period']);
                if (! $preview['ready']) {
                    throw ValidationException::withMessages(['run' => 'Resolve Needs attention first. A run must contain a positive depreciation amount.']);
                }
                if (! hash_equals($preview['preview_hash'], $data['preview_hash'])) {
                    throw ValidationException::withMessages(['run' => 'PPE Masters changed. Refresh the run preview before posting.']);
                }
                $lines = [];
                foreach ($preview['rows'] as $row) {
                    if ($row['depreciation'] > 0) {
                        $lines[] = ['account' => $row['expense_account'], 'debit' => $row['depreciation'], 'credit' => 0, 'party' => '', 'remarks' => $row['code'].' · '.$row['name']];
                        $lines[] = ['account' => $row['accumulated_account'], 'debit' => 0, 'credit' => $row['depreciation'], 'party' => '', 'remarks' => $row['code'].' · '.$row['name']];
                    }
                }
                $number = 'DEP-'.$data['period'];
                $created = true;

                return DepreciationRun::query()->create(['workspace_key' => $workspace, 'period' => $data['period'], 'number' => $number, 'snapshot' => ['rows' => $preview['rows']], 'journal' => ['id' => $number, 'number' => $number, 'date' => $preview['to'], 'reference' => $number, 'status' => 'posted', 'lines' => $lines], 'total_cents' => $preview['total_cents'], 'posted_by' => $request->session()->get('company_setup.full_name', 'Workspace user'), 'posted_at' => now()]);
            });
        } catch (UniqueConstraintViolationException) {
            $run = DepreciationRun::query()->where('workspace_key', $workspace)->where('period', $data['period'])->firstOrFail();
            $created = false;
        }

        return response()->json(['run' => $run, 'created' => $created], $created ? 201 : 200);
    }

    public function store(SavePpeAssetRequest $request): JsonResponse
    {
        $workspace = ChartAccount::workspaceKey($request->session()->get('company_setup'));
        $data = $request->validated();
        $generated = empty($data['code']);
        for ($attempt = 0; ; $attempt++) {
            if ($generated) {
                $codes = PpeAsset::query()->where('workspace_key', $workspace)->pluck('code')->all();
                $number = 1;
                do {
                    $data['code'] = 'PPE-'.str_pad((string) $number++, 6, '0', STR_PAD_LEFT);
                } while (in_array($data['code'], $codes, true));
            }
            try {
                $record = PpeAsset::query()->create([...$data, 'workspace_key' => $workspace]);
                break;
            } catch (UniqueConstraintViolationException) {
                if (! $generated || $attempt >= 4) {
                    throw ValidationException::withMessages(['code' => 'This asset code or serial number already exists. Refresh and try again.']);
                }
            }
        }

        return response()->json(['record' => $record], 201);
    }

    public function update(SavePpeAssetRequest $request, int $asset): JsonResponse
    {
        $record = $this->record($request, $asset);
        $data = $request->validated();
        $posted = DepreciationRun::query()->where('workspace_key', $record->workspace_key)->get()->filter(fn (DepreciationRun $run): bool => in_array($record->id, array_column($run->snapshot['rows'], 'id'), true));
        if ($posted->isNotEmpty()) {
            foreach (['acquired_date', 'service_date', 'cost_cents', 'residual_cents', 'life_months', 'asset_account_id', 'accumulated_account_id', 'expense_account_id'] as $field) {
                if ((string) ($data[$field] ?? '') !== (string) ($record->{$field} ?? '')) {
                    throw ValidationException::withMessages([$field => 'Depreciation has been posted for this asset. Its depreciation basis is locked; an adjustment is required.']);
                }
            }
            $last = $posted->max('period');
            if (! empty($data['disposal_date']) && substr($data['disposal_date'], 0, 7) <= $last) {
                throw ValidationException::withMessages(['disposal_date' => 'Disposal must be after the last posted depreciation month.']);
            }
        }
        if (empty($data['code'])) {
            $data['code'] = $record->code;
        }
        $record->update($data);

        return response()->json(['record' => $record->fresh()]);
    }

    public function archive(Request $request, int $asset): JsonResponse
    {
        $record = $this->record($request, $asset);
        $record->update(['archived' => ! $record->archived]);

        return response()->json(['record' => $record->fresh()]);
    }

    private function record(Request $request, int $asset): PpeAsset
    {
        abort_unless($request->session()->get('preview_access') && $request->session()->has('company_setup'), 403);

        return PpeAsset::query()->where('workspace_key', ChartAccount::workspaceKey($request->session()->get('company_setup')))->findOrFail($asset);
    }
}
