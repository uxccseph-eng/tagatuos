<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinancialReportsController extends Controller
{
    public function transactions(Request $request, string $report): View|RedirectResponse
    {
        if (! $request->session()->get('preview_access')) {
            return to_route('login');
        }
        if (! $request->session()->has('company_setup')) {
            return to_route('company.setup');
        }
        $titles = ['trial-balance' => 'Trial Balance', 'balance-sheet' => 'Balance Sheet', 'income-statement' => 'Income Statement', 'cash-flow' => 'Cash Flow', 'changes-in-equity' => 'Changes in Equity'];
        abort_unless(isset($titles[$report]), 404);
        $data = $request->validate([
            'account' => ['required', 'string', 'max:80'],
            'row' => ['required', 'string', 'max:255'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'metric' => ['nullable', 'in:debit,credit,balance'],
            'return_from' => ['nullable', 'date_format:Y-m-d'],
            'return_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:return_from'],
            'mode' => ['nullable', 'in:standard,comparative,multi-period'],
        ]);

        return view('financial-transactions', [
            'company' => $request->session()->get('company_setup'),
            'report' => $report, 'reportTitle' => $titles[$report], 'selection' => $data,
            'transactionConfig' => ['selection' => $data, 'report' => $report, 'reportTitle' => $titles[$report], 'company' => $request->session()->get('company_setup')],
            'backUrl' => route('reports.show', ['report' => $report, 'from' => $data['return_from'] ?? $data['from'], 'to' => $data['return_to'] ?? $data['to'], 'mode' => $data['mode'] ?? 'standard']),
        ]);
    }

    public function index(Request $request, string $report): View|RedirectResponse
    {
        if (! $request->session()->get('preview_access')) {
            return to_route('login');
        }
        $company = $request->session()->get('company_setup');
        if (! $company) {
            return to_route('company.setup');
        }
        $reportTitles = ['trial-balance' => 'Trial Balance', 'balance-sheet' => 'Balance Sheet', 'income-statement' => 'Income Statement', 'cash-flow' => 'Cash Flow', 'changes-in-equity' => 'Changes in Equity'];
        abort_unless(isset($reportTitles[$report]), 404);

        return view('financial-reports', ['company' => $company, 'report' => $report, 'reportTitle' => $reportTitles[$report], 'reportTitles' => $reportTitles]);
    }
}
