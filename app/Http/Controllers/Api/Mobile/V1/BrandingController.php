<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Support\Tenancy\WorkspaceContext;
use App\Support\Ui\BrandLogo;
use Illuminate\Http\JsonResponse;

class BrandingController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function show(): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);

        return $this->ok([
            'platform' => [
                'name' => 'حاسم',
                'primary_color' => '#06C2A4',
                'logo_url' => BrandLogo::url($workspace),
            ],
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'type' => $workspace->type,
            ],
        ]);
    }
}
