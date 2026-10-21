<?php

namespace App\Http\Controllers;

use App\Models\ChartAccount;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class PayrollController extends Controller
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

        $employees = Schema::hasTable('employees')
            ? Employee::query()->where('workspace_key', ChartAccount::workspaceKey($company))->where('archived', false)->orderBy('name')->get(['id', 'code', 'name'])
            : [];

        return view('payroll', ['company' => $company, 'employees' => $employees]);
    }
}
