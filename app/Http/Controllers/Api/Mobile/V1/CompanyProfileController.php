<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Requests\Workspace\UpdateCompanyProfileRequest;
use App\Http\Resources\Mobile\CompanyProfileResource;
use App\Services\Workspace\WorkspaceCompanyProfileService;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyProfileController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly WorkspaceCompanyProfileService $companyProfiles,
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

        return $this->ok(new CompanyProfileResource($this->companyProfiles->forWorkspace($workspace)));
    }

    public function update(UpdateCompanyProfileRequest $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $profile = $this->companyProfiles->update($workspace, $request->validated());

        return $this->ok(
            new CompanyProfileResource($profile),
            message: 'تم حفظ نبذة الشركة.',
        );
    }
}
