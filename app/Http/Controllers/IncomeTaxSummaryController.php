<?php

namespace App\Http\Controllers;

use App\Http\Requests\IncomeTaxSummaryActionRequest;
use App\Models\CompanySetting;
use App\Services\Tax\IncomeTaxSummaryActions;
use App\Services\Tax\IncomeTaxSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncomeTaxSummaryController extends Controller
{
    public function preview(IncomeTaxSummaryActionRequest $request, IncomeTaxSummaryActions $actions): JsonResponse
    {
        return response()->json($actions->preview($request->attributes->get('active_company'), $request->user(), $request->validated()))->header('Cache-Control', 'no-store, private');
    }

    public function store(IncomeTaxSummaryActionRequest $request, IncomeTaxSummaryActions $actions): JsonResponse
    {
        $request->validate(['preview_hash' => ['required'], 'confirmed' => ['accepted']]);

        return response()->json(['message' => 'Income tax action recorded.', ...$actions->store($request, $request->attributes->get('active_company'), $request->validated())])->header('Cache-Control', 'no-store, private');
    }

    public function sources(Request $request, IncomeTaxSummaryService $service): JsonResponse
    {
        $input = $request->validate(['year' => ['required', 'integer', 'between:2023,2199'], 'quarter' => ['required', 'integer', 'between:1,4'], 'key' => ['required', 'in:sales,other,cost,expense']]);
        $company = $request->attributes->get('active_company');
        $profile = CompanySetting::query()->where('company_id', $company->id)->firstOrFail()->profile;
        $summary = $service->build($company, $profile, (int) $input['year']);
        $rows = array_values(array_filter($summary['columns'][(int) $input['quarter']]['sources'], fn (array $row): bool => $row['bucket'] === $input['key']));
        $rows = array_map(fn (array $row): array => [...$row, 'party' => $row['account'], 'gross' => $row['amount']], $rows);

        return response()->json(['rows' => $rows, 'total' => array_sum(array_column($rows, 'amount'))])->header('Cache-Control', 'no-store, private');
    }
}
