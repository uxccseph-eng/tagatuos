<?php

namespace App\Http\Controllers;

use App\Services\ClientDatabase;
use App\Services\CompanyBookService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountDashboardController extends Controller
{
    public function index(Request $request, CompanyBookService $books): View
    {
        $companies = $books->accessibleBooks($request->user())->paginate(12);

        return view('account-dashboard', [
            'companies' => $companies,
            'configuredWorkspaces' => $companies->getCollection()->filter(fn ($company): bool => app(ClientDatabase::class)->configured($company))->map->workspaceKey()->all(),
            'canCreate' => $request->user()->type === 'firm'
                || ($request->user()->type === 'individual' && $companies->total() === 0),
        ]);
    }
}
