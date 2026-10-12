<?php

namespace App\Http\Controllers\Workspace\Commerce;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Http\Requests\Commerce\UpdateCommerceTaxSettingRequest;
use App\Services\Commerce\CommerceWorkspaceProfileService;
use Illuminate\Http\RedirectResponse;

class CommerceTaxSettingController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly CommerceWorkspaceProfileService $profiles,
    ) {}

    public function update(UpdateCommerceTaxSettingRequest $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $this->authorize('update', $workspace);
        $this->profiles->updateDefaultTaxRate(
            $workspace,
            (string) $request->validated('default_tax_rate'),
        );

        return back()->with('success', 'تم حفظ نسبة ضريبة تجارة الشات.');
    }
}
