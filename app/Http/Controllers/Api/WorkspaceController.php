<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Feature\FeatureAccessService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FeatureAccessService $featureAccessService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()?->workspaces()->get(),
        ]);
    }

    public function current(Request $request): JsonResponse
    {
        $workspace = $this->workspaceContext->workspace();

        if (! $workspace) {
            return response()->json(['message' => 'Workspace not resolved.'], 422);
        }

        $user = $request->user();
        $features = collect(config("workspace.features_by_type.{$workspace->type}", []))
            ->filter(fn (string $feature): bool => $this->featureAccessService->hasFeature($user, $workspace, $feature))
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'workspace' => $workspace,
                'features' => $features,
            ],
        ]);
    }
}
