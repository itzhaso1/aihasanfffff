<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Models\Conversation;
use App\Services\Conversation\ConversationResponderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConversationLiveMonitorController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly ConversationResponderService $responderService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Conversation::class);

        $workspace = $this->currentWorkspace();
        $stats = $this->responderService->getLiveMonitoringStats($workspace);

        return view('workspace.communication.live-monitor', [
            'workspace' => $workspace,
            'stats' => $stats,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Conversation::class);

        $workspace = $this->currentWorkspace();
        $stats = $this->responderService->getLiveMonitoringStats($workspace);

        return response()->json($stats);
    }
}
