<?php

namespace App\Http\Controllers;

use App\Http\Requests\HeartbeatPresenceRequest;
use App\Services\WorkspacePresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspacePresenceController extends Controller
{
    public function index(Request $request, WorkspacePresence $presence): JsonResponse
    {
        return response()->json(['members' => $presence->members($request->user())])->header('Cache-Control', 'private, no-store');
    }

    public function heartbeat(HeartbeatPresenceRequest $request, WorkspacePresence $presence): JsonResponse
    {
        $presence->heartbeat($request->user(), $request->validated());

        return $this->index($request, $presence);
    }
}
