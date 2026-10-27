<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BooksController extends Controller
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

        $book = $request->route('book') ?? 'general-journal';
        $titles = ['general-journal' => 'General Journal', 'general-ledger' => 'General Ledger', 'subsidiary-ledger' => 'Subsidiary Ledger', 'subsidiary-sales' => 'Subsidiary Sales', 'subsidiary-purchase' => 'Subsidiary Purchase', 'cash-disbursements' => 'Cash Disbursements', 'cash-receipts' => 'Cash Receipts', 'cash-analysis' => 'Cash Analysis', 'settlement-trace' => 'Settlement Trace'];
        abort_unless(isset($titles[$book]), 404);

        $bookRoutes = [];
        $bookRoutes['lapsing'] = route('lapsing');
        foreach (['sales', 'collections', 'cwt', 'purchases', 'payments', 'wtp', 'journal', 'payroll'] as $sheet) {
            $bookRoutes[$sheet] = route('sheets.'.$sheet);
        }

        return view('books', ['company' => $company, 'book' => $book, 'bookTitle' => $titles[$book], 'bookTitles' => $titles, 'bookRoutes' => $bookRoutes]);
    }
}
