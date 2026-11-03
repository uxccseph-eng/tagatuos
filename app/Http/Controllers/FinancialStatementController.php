<?php

namespace App\Http\Controllers;

use App\Models\CompanyLedger;
use App\Models\FinancialStatement;
use App\Services\FinancialStatementCalculator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class FinancialStatementController extends Controller
{
    public function index(Request $request): View
    {
        $request->validate(['year' => ['nullable', 'integer', 'between:1902,2199']]);
        $year = $request->integer('year', now()->year);
        $ledger = CompanyLedger::query()->first();
        $statement = FinancialStatement::query()->where('reporting_year', $year)->first();

        return view('financial-statement', [
            'company' => $request->session()->get('company_setup'), 'ledger' => $ledger, 'statement' => $statement, 'year' => $year,
            'report' => 'financial-statement', 'reportTitle' => 'Financial Statement',
            'reportTitles' => ['trial-balance' => 'Trial Balance', 'balance-sheet' => 'Balance Sheet', 'income-statement' => 'Income Statement', 'cash-flow' => 'Cash Flow', 'changes-in-equity' => 'Changes in Equity', 'financial-statement' => 'Financial Statement'],
        ]);
    }

    /** The source audit-pack ID is the saved CompanyLedger ID in this application. */
    public function generate(Request $request, int $auditPackId, FinancialStatementCalculator $calculator): JsonResponse
    {
        $data = $request->validate(['reporting_year' => ['required', 'integer', 'between:1902,2199']]);
        $company = $request->attributes->get('active_company');
        abort_unless($company && (string) $request->header('X-Company-ID') === (string) $company->id, 409, 'Reopen the intended company book before generating.');
        $statement = $calculator->generate($company, $auditPackId, (int) $data['reporting_year'], (int) $request->header('X-Ledger-Revision'));

        return response()->json([
            'success' => true, 'message' => 'Financial statements calculated and saved. Review the reconciliation checks before use.',
            'data' => $statement, 'html' => view('financial-statement-document', compact('statement'))->render(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function pdf(Request $request): Response
    {
        $data = $request->validate(['year' => ['required', 'integer', 'between:1902,2199']]);
        $statement = FinancialStatement::query()->where('reporting_year', $data['year'])->firstOrFail();
        abort_unless($statement->ledger_revision === CompanyLedger::query()->whereKey($statement->company_ledger_id)->value('revision'), 409, 'The saved statement is outdated. Generate it again before exporting.');

        return Pdf::loadView('financial-statement-pdf', compact('statement'))->setPaper('a4')->download('financial-statements-'.$statement->reporting_year.'.pdf');
    }
}
