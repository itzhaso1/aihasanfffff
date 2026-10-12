<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\AuthorizesChatCatalog;
use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Requests\Chat\UpdateChatCatalogCommunicationProfileRequest;
use App\Http\Requests\Chat\UpdateChatCatalogSettingsRequest;
use App\Http\Resources\Mobile\ChatCatalogResource;
use App\Models\Commerce\Seller;
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

        $payload = $this->brandingPayload($request->validated(), $request, $workspace->id);

        try {
            $catalog = $this->catalogs->updateSettings(
                $this->catalogs->forWorkspace($workspace),
                $payload,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(
            new ChatCatalogResource($catalog->load('communicationProfile')),
            message: 'تم تحديث إعدادات المنتجات والمخزون.',
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
            message: 'تم تحديث لغة وتواصل المنتجات والمخزون.',
        );
    }

    public function sellers(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogView($request, $workspace);

        $sellers = Seller::query()->orderBy('name')->get(['id', 'name', 'display_name']);

        return $this->ok($sellers->map(fn (Seller $seller): array => [
            'id' => $seller->id,
            'name' => $seller->name,
            'display_name' => $seller->display_name,
        ])->values());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function brandingPayload(array $payload, Request $request, int $workspaceId): array
    {
        unset($payload['logo_file'], $payload['favicon_file'], $payload['banner_file'], $payload['brand_image_file']);
        $directory = 'workspaces/'.$workspaceId.'/chat-catalog/branding';
        foreach ([
            'logo_file' => 'logo_path',
            'favicon_file' => 'favicon_path',
            'banner_file' => 'banner_path',
            'brand_image_file' => 'brand_image_path',
        ] as $fileKey => $pathKey) {
            $file = $request->file($fileKey);
            if ($file) {
                $payload[$pathKey] = $file->store($directory, 'public');
            }
        }
        foreach ([
            'remove_logo' => 'logo_path',
            'remove_favicon' => 'favicon_path',
            'remove_banner' => 'banner_path',
            'remove_brand_image' => 'brand_image_path',
        ] as $flag => $pathKey) {
            if ($request->boolean($flag)) {
                $payload[$pathKey] = null;
            }
            unset($payload[$flag]);
        }

        return $payload;
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

        return $this->ok(new ChatCatalogResource($catalog->load('communicationProfile')), message: 'تم تحديث حالة المنتجات والمخزون.');
    }
}
