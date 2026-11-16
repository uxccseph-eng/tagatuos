<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompensationSummaryActionRequest;
use App\Services\Tax\CompensationSummaryActions;
use Illuminate\Http\JsonResponse;

class CompensationSummaryController extends Controller
{
    public function preview(CompensationSummaryActionRequest $request, CompensationSummaryActions $actions): JsonResponse
    {
        return response()->json($actions->preview($request->attributes->get('active_company'), $request->user(), $request->validated()))->header('Cache-Control', 'no-store, private');
    }

    public function store(CompensationSummaryActionRequest $request, CompensationSummaryActions $actions): JsonResponse
    {
        $request->validate(['preview_hash' => ['required'], 'confirmed' => ['accepted']]);

        return response()->json(['message' => 'Compensation payment recorded.', ...$actions->store($request, $request->attributes->get('active_company'), $request->validated())])->header('Cache-Control', 'no-store, private');
    }
}
