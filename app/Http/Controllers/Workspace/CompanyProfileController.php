<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Http\Requests\Workspace\UpdateCompanyProfileRequest;
use App\Services\Workspace\WorkspaceCompanyProfileService;
use App\Support\Authorization\WorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CompanyProfileController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly WorkspaceCompanyProfileService $companyProfiles,
        private readonly WorkspaceAccess $workspaceAccess,
    ) {}

    public function update(UpdateCompanyProfileRequest $request): RedirectResponse
    {
        $this->authorizeCompanyProfile($request);
        $this->companyProfiles->update($this->currentWorkspace(), $request->validated());

        return redirect()
            ->route('workspace.ai-settings.edit', ['tab' => 'company'])
            ->with('success', 'تم حفظ نبذة الشركة.');
    }

    private function authorizeCompanyProfile(Request $request): void
    {
        $user = $request->user();
        $workspace = $this->currentWorkspace();
        abort_unless(
            $user && $this->workspaceAccess->canManageAiSettings($user, $workspace),
            403
        );
    }
}
