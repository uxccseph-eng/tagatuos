<?php

namespace App\Http\Controllers;

use App\Http\Requests\EwtSummaryActionRequest;
use App\Services\Tax\EwtSummaryActions;
use Illuminate\Http\JsonResponse;

class EwtSummaryController extends Controller
{
    public function preview(EwtSummaryActionRequest $request, EwtSummaryActions $actions): JsonResponse
    {
        return response()->json($actions->preview($request->attributes->get('active_company'), $request->user(), $request->validated()))->header('Cache-Control', 'no-store, private');
    }

    public function store(EwtSummaryActionRequest $request, EwtSummaryActions $actions): JsonResponse
    {
        $request->validate(['preview_hash' => ['required'], 'confirmed' => ['accepted']]);

        return response()->json(['message' => 'EWT payment recorded.', ...$actions->store($request, $request->attributes->get('active_company'), $request->validated())])->header('Cache-Control', 'no-store, private');
    }
}
