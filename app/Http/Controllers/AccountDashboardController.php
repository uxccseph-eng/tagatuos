<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Services\CompanyBookService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountDashboardController extends Controller
{
    public function index(Request $request, CompanyBookService $books): View
    {
        $companies = $books->accessibleBooks($request->user())->paginate(12);
        $workspaceKeys = $companies->getCollection()->map->workspaceKey()->all();

        return view('account-dashboard', [
            'companies' => $companies,
            'configuredWorkspaces' => CompanySetting::query()->whereIn('workspace_key', $workspaceKeys)->pluck('workspace_key')->all(),
            'canCreate' => $request->user()->type === 'firm'
                || ($request->user()->type === 'individual' && $companies->total() === 0),
        ]);
    }
}
