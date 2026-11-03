<?php

namespace App\Http\Controllers;

use App\Services\ClientDeletion;
use App\Services\CompanyBookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DeleteClientController extends Controller
{
    public function show(Request $request, int $companyId, CompanyBookService $books, ClientDeletion $deletion): View
    {
        $company = $books->findAccessibleBook($request->user(), $companyId);
        $deletion->authorize($request->user(), $company);

        return view('delete-client', ['company' => $company]);
    }

    public function destroy(Request $request, int $companyId, CompanyBookService $books, ClientDeletion $deletion): JsonResponse|RedirectResponse
    {
        $company = $books->findAccessibleBook($request->user(), $companyId);
        $deletion->authorize($request->user(), $company);
        $values = $request->validate([
            'confirmation_name' => ['required', 'string', Rule::in([$company->name])],
            'password' => ['required', 'current_password:web'],
        ]);
        $deletion->queue($request->user(), $company->id, $values['confirmation_name']);
        if ((int) $request->session()->get('active_company_id') === $company->id) {
            $request->session()->forget(['active_company_id', 'company_setup', 'preview_access']);
        }
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Client deletion queued.'], 202);
        }

        return to_route('account.dashboard')->with('status', 'Client deletion queued. Its database, private folder, and related records will be permanently removed. Deletion status updates automatically.');
    }
}
