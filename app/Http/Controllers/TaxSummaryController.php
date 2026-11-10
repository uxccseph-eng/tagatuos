<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Services\Tax\VatSummaryService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TaxSummaryController extends Controller
{
    public function index(Request $request, VatSummaryService $service): Response
    {
        $input = $request->validate(['year' => ['nullable', 'integer', 'between:2023,2199']]);
        $company = $request->attributes->get('active_company');
        $profile = CompanySetting::query()->where('company_id', $company->id)->firstOrFail()->profile;
        $summary = $service->build($company, $profile, (int) ($input['year'] ?? now('Asia/Manila')->year));

        return response()->view('tax-summary', ['company' => $request->session()->get('company_setup'), 'summary' => $summary])->header('Cache-Control', 'no-store, private');
    }
}
