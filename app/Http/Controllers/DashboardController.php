<?php

namespace App\Http\Controllers;

use App\Services\Tax\TaxReturnCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
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

        $dashboardRoutes = ['documents' => route('documents.entry'), 'work' => route('my-work'), 'books' => route('books'), 'masters' => route('masters.accounts'), 'reports' => route('reports'), 'taxReports' => route('tax-reports'), 'taxReturns' => route('tax-returns.index'), 'taxSummary' => route('tax-summary.index')];
        foreach (['sales', 'collections', 'purchases', 'payments', 'journal', 'cwt', 'wtp'] as $sheet) {
            $dashboardRoutes[$sheet] = route('sheets.'.$sheet);
        }
        if (isset($company['company_id'])) {
            foreach ($dashboardRoutes as $key => &$url) {
                if (! in_array($key, ['documents', 'work'], true)) {
                    $url .= (str_contains($url, '?') ? '&' : '?').'company_id='.$company['company_id'];
                }
            }
            unset($url);
        }

        return view('dashboard', ['company' => $company, 'dashboardRoutes' => $dashboardRoutes, 'dashboardDeadlines' => $this->deadlines($request, $company)]);
    }

    /** @param array<string, mixed> $profile
     * @return list<array<string, mixed>>
     */
    private function deadlines(Request $request, array $profile): array
    {
        $company = $request->attributes->get('active_company');
        if (! $company || ! in_array('tax-reports', $request->attributes->get('company_allowed_tabs', []), true)) {
            return [];
        }
        $today = CarbonImmutable::now('Asia/Manila');
        $filed = DB::table('tax_return_filing_records')->where('company_id', $company->id)->whereNotNull('filed_on')->get(['form', 'period_from', 'period_to'])->keyBy(fn (object $row): string => $row->form.'|'.$row->period_from.'|'.$row->period_to);
        $catalog = app(TaxReturnCatalog::class);
        $events = [];
        foreach (['1601-C', '0619-E', '1601-EQ', '2550Q', '2551Q', '1701Q', '1702Q'] as $form) {
            $definition = TaxReturnCatalog::forms()[$form];
            foreach ([$today->year - 1, $today->year, $today->year + 1] as $year) {
                foreach (range(1, $definition['frequency'] === 'monthly' ? 12 : 4) as $period) {
                    $dates = $catalog->period($form, $year, $period, $profile);
                    if (! $dates['applicable'] || ! str_starts_with($dates['due'] ?? '', $today->format('Y-m'))) {
                        continue;
                    }
                    $events[] = ['form' => $form, 'label' => $definition['label'], 'due' => $dates['due'], 'filed' => $filed->has($form.'|'.$dates['from'].'|'.$dates['to']), 'url' => route('tax-returns.index', ['company_id' => $company->id, 'frequency' => $definition['frequency'], 'year' => $year, 'period' => $period])];
                }
            }
        }
        usort($events, fn (array $first, array $second): int => strcmp($first['due'], $second['due']) ?: strcmp($first['form'], $second['form']));

        return $events;
    }
}
