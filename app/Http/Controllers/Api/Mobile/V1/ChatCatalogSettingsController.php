<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\AuthorizesChatCatalog;
use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Requests\Chat\UpdateChatCatalogCommunicationProfileRequest;
use App\Http\Requests\Chat\UpdateChatCatalogSettingsRequest;
use App\Http\Resources\Mobile\ChatCatalogResource;
use App\Services\Chat\ChatCatalogRootService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ChatCatalogSettingsController extends MobileController
{
    use AuthorizesChatCatalog;
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly ChatCatalogRootService $catalogs,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogView($request, $workspace);

        return $this->ok(new ChatCatalogResource(
            $this->catalogs->forWorkspace($workspace)->load('communicationProfile'),
        ));
    }

    public function update(UpdateChatCatalogSettingsRequest $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogSettingsManage($request, $workspace);

        try {
            $catalog = $this->catalogs->updateSettings(
                $this->catalogs->forWorkspace($workspace),
                $request->validated(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(
            new ChatCatalogResource($catalog->load('communicationProfile')),
            message: 'تم تحديث إعدادات الكتالوج.',
        );
    }

    public function updateCommunication(UpdateChatCatalogCommunicationProfileRequest $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogSettingsManage($request, $workspace);

        $catalog = $this->catalogs->forWorkspace($workspace);
        $this->catalogs->updateCommunicationProfile($catalog, $request->validated());

        return $this->ok(
            new ChatCatalogResource($catalog->fresh(['communicationProfile'])),
            message: 'تم تحديث لغة وتواصل الكتالوج.',
        );
    }

    public function publish(Request $request): JsonResponse
    {
        return $this->transition($request, 'publish');
    }

    public function draft(Request $request): JsonResponse
    {
        return $this->transition($request, 'draft');
    }

    public function pause(Request $request): JsonResponse
    {
        return $this->transition($request, 'pause');
    }

    public function archive(Request $request): JsonResponse
    {
        return $this->transition($request, 'archive');
    }

    private function transition(Request $request, string $action): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogSettingsManage($request, $workspace);
        $catalog = $this->catalogs->forWorkspace($workspace);
        $catalog = match ($action) {
            'publish' => $this->catalogs->publish($catalog),
            'draft' => $this->catalogs->draft($catalog),
            'pause' => $this->catalogs->pause($catalog),
            'archive' => $this->catalogs->archive($catalog),
        };

        return $this->ok(new ChatCatalogResource($catalog->load('communicationProfile')), message: 'تم تحديث حالة الكتالوج.');
    }
}
