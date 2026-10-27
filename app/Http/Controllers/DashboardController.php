<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $dashboardRoutes = ['documents' => route('documents.entry'), 'work' => route('my-work'), 'books' => route('books'), 'masters' => route('masters.accounts'), 'reports' => route('reports')];
        foreach (['sales', 'collections', 'purchases', 'payments', 'journal', 'cwt', 'wtp'] as $sheet) {
            $dashboardRoutes[$sheet] = route('sheets.'.$sheet);
        }

        return view('dashboard', ['company' => $company, 'dashboardRoutes' => $dashboardRoutes]);
    }
}
