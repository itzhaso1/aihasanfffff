<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Services\Mobile\MobileHomeService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnreadController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly MobileHomeService $mobileHomeService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

        $workspace = $this->requireWorkspace($this->workspaceContext);
        $snapshot = $this->mobileHomeService->snapshot($user, $workspace);

        return $this->ok([
            'unread_conversations' => $snapshot['unread_conversations'],
            'unread_email' => $snapshot['unread_email'],
            'unread_notifications' => $snapshot['unread_notifications'],
        ]);
    }
}
