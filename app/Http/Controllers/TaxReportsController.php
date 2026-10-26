<?php

namespace App\Http\Controllers;

use App\Models\ChartAccount;
use App\Models\Employee;
use App\ReliefPurchasesWorkbook;
use App\ReliefSalesWorkbook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TaxReportsController extends Controller
{
    public const SCHEDULES = [
        'relief-sales' => ['RELIEF Sales', 'point_of_sale'],
        'relief-purchases' => ['RELIEF Purchases', 'shopping_bag'],
        'relief-importation' => ['RELIEF Importation', 'flight_land'],
        'sawt' => ['SAWT', 'table_view'],
        'qap-ewt' => ['QAP EWT', 'assignment'],
        'qap-fwt' => ['QAP FWT', 'fact_check'],
        'alphalist-non-mwe' => ['Alphalist Non-MWE', 'group'],
        'alphalist-mwe' => ['Alphalist MWE', 'badge'],
        '2307-monitor' => ['2307 Monitor', 'inventory_2'],
        'sc-pwd' => ['SC/PWD', 'accessible'],
    ];

    public function index(Request $request, string $schedule = 'relief-sales'): View|RedirectResponse
    {
        if (! $request->session()->get('preview_access')) {
            return to_route('login');
        }
        if (! $request->session()->has('company_setup')) {
            return to_route('company.setup');
        }
        abort_unless(isset(self::SCHEDULES[$schedule]), 404);

        $company = $request->session()->get('company_setup');
        $employees = $schedule === 'alphalist-non-mwe' && Schema::hasTable('employees')
            ? Employee::query()->where('workspace_key', ChartAccount::workspaceKey($company))->orderBy('name')->get(['id', 'code', 'name', 'tin'])
            : [];

        return view('tax-reports', ['employees' => $employees, 'company' => $request->session()->get('company_setup'), 'schedule' => $schedule, 'schedules' => self::SCHEDULES, 'scheduleTitle' => self::SCHEDULES[$schedule][0]]);
    }

    public function exportSales(Request $request, ReliefSalesWorkbook $workbook): BinaryFileResponse
    {
        abort_unless($request->session()->get('preview_access') && $request->session()->has('company_setup'), 403);
        $rules = [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'rows' => ['present', 'array', 'list', 'max:5000'],
            'rows.*' => ['required', 'array', 'list', 'size:11'],
        ];
        foreach (range(0, 10) as $column) {
            $rules['rows.*.'.$column] = in_array($column, [3, 4, 5, 6, 8, 9, 10], true)
                ? ['required', 'numeric', 'between:-999999999999,999999999999']
                : ['nullable', 'string', 'max:2000'];
        }
        $data = $request->validate($rules);
        $rows = array_map(fn (array $row): array => array_map(fn (mixed $value): mixed => $value ?? '', $row), $data['rows']);
        $path = $workbook->create($request->session()->get('company_setup'), $data['from'], $data['to'], $rows);

        return response()->download($path, 'relief-sales-'.$data['from'].'-to-'.$data['to'].'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend(true);
    }

    public function exportPurchases(Request $request, ReliefPurchasesWorkbook $workbook): BinaryFileResponse
    {
        abort_unless($request->session()->get('preview_access') && $request->session()->has('company_setup'), 403);
        $rules = [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'rows' => ['present', 'array', 'list', 'max:5000'],
            'rows.*' => ['required', 'array', 'list', 'size:14'],
            'rows.*.0' => ['required', 'date_format:Y-m'],
        ];
        foreach (range(1, 13) as $column) {
            $rules['rows.*.'.$column] = $column >= 5
                ? ['required', 'numeric', 'between:-999999999999,999999999999']
                : ['nullable', 'string', 'max:2000'];
        }
        $data = $request->validate($rules);
        $rows = array_map(fn (array $row): array => array_map(fn (mixed $value): mixed => $value ?? '', $row), $data['rows']);
        $path = $workbook->create($request->session()->get('company_setup'), $data['from'], $data['to'], $rows);

        return response()->download($path, 'relief-purchases-'.$data['from'].'-to-'.$data['to'].'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend(true);
    }
}
