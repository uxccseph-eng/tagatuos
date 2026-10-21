<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportMastersRequest;
use App\MasterImport;
use App\Models\ChartAccount;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MastersImportController extends Controller
{
    public function preview(ImportMastersRequest $request, string $registry, MasterImport $import): JsonResponse
    {
        $rows = $request->validated('records');
        $result = $import->preview($request, $registry, $rows);
        $token = null;
        if ($result['errors'] === []) {
            $token = Str::random(40);
            $request->session()->put('master_import.'.$registry, ['token' => $token, 'workspace' => ChartAccount::workspaceKey($request->session()->get('company_setup')), 'input' => $this->hash($rows), 'normalized' => $this->hash($result['records']), 'expires' => now()->addMinutes(15)->timestamp]);
        } else {
            $request->session()->forget('master_import.'.$registry);
        }

        return response()->json([...$result, 'preview_token' => $token]);
    }

    public function store(ImportMastersRequest $request, string $registry, MasterImport $import): JsonResponse
    {
        $rows = $request->validated('records');
        $preview = $request->session()->get('master_import.'.$registry);
        $workspaceKey = ChartAccount::workspaceKey($request->session()->get('company_setup'));
        if (! $preview || ! hash_equals($preview['token'], (string) $request->input('preview_token')) || $preview['input'] !== $this->hash($rows) || $preview['workspace'] !== $workspaceKey || $preview['expires'] < now()->timestamp) {
            throw ValidationException::withMessages(['preview' => 'Preview this batch again before adding it to Masters.']);
        }
        try {
            $records = Cache::lock('masters-import-'.hash('sha256', $workspaceKey), 120)->block(5, fn (): array => DB::transaction(function () use ($request, $registry, $import, $rows, $preview, $workspaceKey): array {
                ChartAccount::query()->where('workspace_key', $workspaceKey)->orderBy('id')->lockForUpdate()->first();
                $model = MasterImport::model($registry);
                $model::query()->where('workspace_key', $workspaceKey)->orderBy('id')->lockForUpdate()->get();
                $result = $import->preview($request, $registry, $rows);
                if ($result['errors'] !== []) {
                    $errors = [];
                    foreach ($result['errors'] as $error) {
                        $errors['records.'.($error['row'] - 2).'.'.$error['field']][] = $error['message'];
                    }
                    throw ValidationException::withMessages($errors);
                }
                if ($preview['normalized'] !== $this->hash($result['records'])) {
                    throw ValidationException::withMessages(['preview' => 'Masters changed since the preview. Preview again to review the current generated codes.']);
                }

                return array_map(fn (array $row): array => $model::query()->create([...$row, 'workspace_key' => $workspaceKey])->toArray(), $result['records']);
            }));
        } catch (LockTimeoutException|UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['preview' => 'Masters is being updated by another request. Preview again before importing.']);
        }
        $request->session()->forget('master_import.'.$registry);

        return response()->json(['records' => $records, 'count' => count($records)], 201);
    }

    /** @param list<array<string, mixed>> $rows */
    private function hash(array $rows): string
    {
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
