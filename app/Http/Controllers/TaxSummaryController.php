<?php

namespace App\Http\Controllers;

use App\Http\Requests\VatSummaryActionRequest;
use App\Models\CompanySetting;
use App\Services\Tax\IncomeTaxSummaryService;
use App\Services\Tax\VatSummaryActions;
use App\Services\Tax\VatSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TaxSummaryController extends Controller
{
    public function index(Request $request, VatSummaryService $service): Response
    {
        $input = $request->validate(['year' => ['nullable', 'integer', 'between:2023,2199'], 'tax' => ['nullable', 'in:vat,income']]);
        $company = $request->attributes->get('active_company');
        $profile = CompanySetting::query()->where('company_id', $company->id)->firstOrFail()->profile;
        if (($input['tax'] ?? 'vat') === 'income') {
            $summary = app(IncomeTaxSummaryService::class)->build($company, $profile, (int) ($input['year'] ?? now('Asia/Manila')->year));

            return response()->view('income-tax-summary', ['company' => $request->session()->get('company_setup'), 'summary' => $summary])->header('Cache-Control', 'no-store, private');
        }
        $summary = $service->build($company, $profile, (int) ($input['year'] ?? now('Asia/Manila')->year));

        return response()->view('tax-summary', ['company' => $request->session()->get('company_setup'), 'summary' => $summary])->header('Cache-Control', 'no-store, private');
    }

    public function sources(Request $request, VatSummaryService $service): JsonResponse
    {
        $input = $request->validate(['year' => ['required', 'integer', 'between:2023,2199'], 'quarter' => ['required', 'integer', 'between:1,4'], 'key' => ['required', 'in:sales,zero,exempt,total_sales,output,purchases,nonvat,input,noncreditable']]);
        $company = $request->attributes->get('active_company');
        $profile = CompanySetting::query()->where('company_id', $company->id)->firstOrFail()->profile;

        return response()->json($service->sources($company, $profile, (int) $input['year'], (int) $input['quarter'], $input['key']))->header('Cache-Control', 'no-store, private');
    }

    public function preview(VatSummaryActionRequest $request, VatSummaryActions $actions): JsonResponse
    {
        return response()->json($actions->preview($request->attributes->get('active_company'), $request->user(), $request->validated()))->header('Cache-Control', 'no-store, private');
    }

    public function store(VatSummaryActionRequest $request, VatSummaryActions $actions): JsonResponse
    {
        $request->validate(['preview_hash' => ['required'], 'confirmed' => ['accepted']]);

        return response()->json(['message' => 'VAT action recorded.', ...$actions->store($request, $request->attributes->get('active_company'), $request->validated())])->header('Cache-Control', 'no-store, private');
    }
}
