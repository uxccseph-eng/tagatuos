<?php

namespace App\Http\Controllers;

use App\Http\Requests\GenerateFinancialStatementRequest;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\FinancialStatement;
use App\Services\FinancialStatementCalculator;
use App\Services\FinancialStatementPackage;
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

        $profile = $request->session()->get('company_setup');
        $packageInputs = array_replace(app(FinancialStatementPackage::class)->defaults($profile), $statement?->package['inputs'] ?? []);
        if ($statement && ($statement->ledger_revision !== $ledger?->revision || ($statement->package['profile'] ?? []) !== CompanySetting::query()->first()?->profile)) {
            $packageInputs['management_confirmed'] = false;
        }

        return view('financial-statement', [
            'company' => $request->session()->get('company_setup'), 'ledger' => $ledger, 'statement' => $statement, 'year' => $year,
            'packageInputs' => $packageInputs,
            'report' => 'financial-statement', 'reportTitle' => 'Financial Statement',
            'reportTitles' => ['trial-balance' => 'Trial Balance', 'balance-sheet' => 'Balance Sheet', 'income-statement' => 'Income Statement', 'cash-flow' => 'Cash Flow', 'changes-in-equity' => 'Changes in Equity', 'financial-statement' => 'Financial Statement'],
        ]);
    }

    /** The source audit-pack ID is the saved CompanyLedger ID in this application. */
    public function generate(GenerateFinancialStatementRequest $request, int $auditPackId, FinancialStatementCalculator $calculator): JsonResponse
    {
        $data = $request->validated();
        $company = $request->attributes->get('active_company');
        abort_unless($company && (string) $request->header('X-Company-ID') === (string) $company->id, 409, 'Reopen the intended company book before generating.');
        $statement = $calculator->generate($company, $auditPackId, (int) $data['reporting_year'], (int) $request->header('X-Ledger-Revision'), $data['package_inputs'] ?? []);

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

        abort_unless($statement->package !== null, 409, 'Generate this statement again to include the complete financial statement package.');

        $pdf = Pdf::loadView('financial-statement-pdf', compact('statement'))->setPaper('a4');
        $pdf->render();
        $pdf->getDomPDF()->getCanvas()->page_text(255, 820, 'Page {PAGE_NUM} of {PAGE_COUNT}', $pdf->getDomPDF()->getFontMetrics()->getFont('Times-Roman'), 8, [0.4, 0.4, 0.4]);

        return $pdf->download('financial-statements-'.$statement->reporting_year.'.pdf');
    }
}
