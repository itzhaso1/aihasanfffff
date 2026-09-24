<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Requests\Ai\UpdateAiSettingRequest;
use App\Http\Resources\Mobile\AiSettingResource;
use App\Services\AI\AiSettingManagementService;
use App\Services\AI\Inbox\Persona\InboxAgentPersonaCatalog;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Chat\ChatAiRole;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiSettingsController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly AiSettingManagementService $aiSettings,
        private readonly WorkspaceContext $workspaceContext,
        private readonly WorkspaceAccess $workspaceAccess,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $request->user();
        if (! $user || ! $this->workspaceAccess->canManageAiSettings($user, $workspace)) {
            return $this->fail('لا تملك صلاحية إعدادات الذكاء الاصطناعي.', 403);
        }

        $setting = $this->aiSettings->forWorkspace($workspace);

        return $this->ok(
            new AiSettingResource($setting),
            $this->catalogMeta(),
        );
    }

    public function update(UpdateAiSettingRequest $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $setting = $this->aiSettings->update($workspace, $request->validated());

        return $this->ok(
            new AiSettingResource($setting),
            $this->catalogMeta(),
            message: 'تم تحديث إعدادات الذكاء الاصطناعي.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogMeta(): array
    {
        return [
            'ai_roles' => ChatAiRole::all(),
            'persona_catalog' => InboxAgentPersonaCatalog::schemaForApi(),
        ];
    }
}
