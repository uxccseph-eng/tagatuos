<?php

namespace App\Http\Controllers;

use App\Models\FirmOwnershipTransfer;
use App\Services\ClientDatabase;
use App\Services\CompanyBookService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountDashboardController extends Controller
{
    public function index(Request $request, CompanyBookService $books): View
    {
        $companies = $books->accessibleBooks($request->user())->paginate(12);

        $creationFirms = $books->creationFirms($request->user());

        return view('account-dashboard', [
            'incomingTransfers' => FirmOwnershipTransfer::query()->where('target_owner_id', $request->user()->id)->where('status', 'pending')->where('expires_at', '>', now())->latest('id')->get(),
            'creationFirms' => $creationFirms,
            'setupRetryIds' => $companies->getCollection()->filter(fn ($company): bool => $books->canRetrySetup($request->user(), $company))->modelKeys(),
            'companies' => $companies,
            'configuredWorkspaces' => $companies->getCollection()->filter(fn ($company): bool => app(ClientDatabase::class)->configured($company))->map->workspaceKey()->all(),
            'canCreate' => $creationFirms->isNotEmpty() || $request->user()->type === 'firm'
                || ($request->user()->type === 'individual' && $companies->total() === 0),
        ]);
    }
}
