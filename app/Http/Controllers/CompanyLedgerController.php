<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCompanyLedgerRequest;
use App\Models\CompanyLedger;
use App\Services\CompanyLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyLedgerController extends Controller
{
    public function show(Request $request, CompanyLedgerService $ledger): JsonResponse
    {
        abort_unless($request->user(), 401);
        if ($request->boolean('revision_only')) {
            return response()->json(['revision' => (int) (CompanyLedger::query()->where('company_id', $request->attributes->get('active_company')->id)->value('revision') ?? 0)])->header('Cache-Control', 'private, no-store');
        }

        return response()->json($ledger->snapshotFor($request->attributes->get('active_company'), $request->attributes->get('company_allowed_tabs', [])))->header('Cache-Control', 'private, no-store');
    }

    public function save(SaveCompanyLedgerRequest $request, CompanyLedgerService $ledger): JsonResponse
    {
        abort_unless((string) $request->header('X-Company-ID') === (string) $request->attributes->get('active_company')->id, 409, 'Reload the intended company book before saving.');

        return response()->json($ledger->save($request->attributes->get('active_company'), $request->user(), $request->validated()))->header('Cache-Control', 'private, no-store');
    }
}
