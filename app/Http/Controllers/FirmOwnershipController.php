<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcceptFirmOwnershipTransferRequest;
use App\Http\Requests\RequestFirmOwnershipTransferRequest;
use App\Models\FirmOwnershipTransfer;
use App\Models\User;
use App\Services\FirmOwnershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class FirmOwnershipController extends Controller
{
    public function index(Request $request, FirmOwnershipService $service): View
    {
        $scope = $service->scope($request->user());

        return view('account-ownership', [
            'scope' => $scope,
            'transfers' => FirmOwnershipTransfer::query()->where('source_owner_id', $request->user()->id)->latest('id')->limit(10)->get(),
        ]);
    }

    public function store(RequestFirmOwnershipTransferRequest $request, FirmOwnershipService $service): RedirectResponse
    {
        $service->request($request->user(), $request->string('email')->toString(), $request->string('current_password')->toString(), $request->string('confirmation')->toString());

        return to_route('account.ownership.index')->with('status', 'Handover requested. Ask the recipient to sign in and review it under Company books. It expires in seven days.');
    }

    public function show(Request $request, int $transferId, FirmOwnershipService $service): View
    {
        $transfer = FirmOwnershipTransfer::query()->findOrFail($transferId);
        abort_unless($transfer->target_owner_id === $request->user()->id, 403);
        $scope = $transfer->status === 'pending' && $transfer->expires_at->isFuture()
            ? $service->scope(User::query()->findOrFail($transfer->source_owner_id))
            : $transfer->scope_summary;

        return view('account-ownership-review', ['transfer' => $transfer, 'scope' => $scope, 'scopeHash' => $service->scopeHash($scope)]);
    }

    public function accept(AcceptFirmOwnershipTransferRequest $request, int $transferId, FirmOwnershipService $service): RedirectResponse
    {
        $service->accept($request->user(), $transferId, $request->string('current_password')->toString(), $request->string('confirmation')->toString(), $request->string('scope_hash')->toString());
        Auth::setUser($request->user()->fresh());
        $request->session()->forget(['active_company_id', 'active_workspace_key']);
        $request->session()->regenerate();

        return to_route('account.dashboard')->with('status', 'Firm ownership transferred. You now manage all its client books and team access.');
    }

    public function close(Request $request, int $transferId, FirmOwnershipService $service): RedirectResponse
    {
        $service->close($request->user(), $transferId);

        return to_route('account.dashboard')->with('status', 'Handover closed. Ownership has not changed.');
    }
}
