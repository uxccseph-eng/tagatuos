<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PurchasesController extends Controller
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

        return view('purchases', ['company' => $company]);
    }
}
