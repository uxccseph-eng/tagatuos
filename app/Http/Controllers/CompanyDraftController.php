<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCompanyDraftRequest;
use App\Services\CompanyDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyDraftController extends Controller
{
    public function index(Request $request, CompanyDraftService $drafts): JsonResponse
    {
        return response()->json(['drafts' => $drafts->listing($request->attributes->get('active_company'), $request->user())])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, CompanyDraftService $drafts, string $draftId): JsonResponse
    {
        return response()->json($drafts->read($request->attributes->get('active_company'), $request->user(), $draftId))->header('Cache-Control', 'private, no-store');
    }

    public function store(SaveCompanyDraftRequest $request, CompanyDraftService $drafts, string $draftId): JsonResponse
    {
        $data = $request->validated();

        return response()->json($drafts->save($request->attributes->get('active_company'), $request->user(), $draftId, $data['version'], $data['payload']))->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, CompanyDraftService $drafts, string $draftId): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:0']]);
        $drafts->delete($request->attributes->get('active_company'), $request->user(), $draftId, $data['version']);

        return response()->json(['deleted' => true])->header('Cache-Control', 'private, no-store');
    }
}
