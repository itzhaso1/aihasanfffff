<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1\Company;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Requests\Worker\GrantWorkerEngagementCapabilityRequest;
use App\Http\Requests\Worker\RevokeWorkerEngagementCapabilityRequest;
use App\Models\Worker\WorkerEngagement;
use App\Services\Worker\WorkerEngagementCapabilityService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WorkerEngagementCapabilityController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly WorkerEngagementCapabilityService $capabilities,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function index(Request $request, WorkerEngagement $engagement): JsonResponse
    {
        $this->authorizeManagement($request, $engagement);

        return $this->ok($this->payload($engagement));
    }

    public function store(
        GrantWorkerEngagementCapabilityRequest $request,
        WorkerEngagement $engagement,
    ): JsonResponse {
        $permission = $this->capabilities->grant(
            $request->user(),
            $engagement,
            $request->validated('capability_key'),
            $request,
        );

        return $this->ok(
            $this->payload($engagement->refresh()),
            message: $permission->allowed
                ? 'تم منح الصلاحية.'
                : 'تعذر منح الصلاحية.',
        );
    }

    public function destroy(
        RevokeWorkerEngagementCapabilityRequest $request,
        WorkerEngagement $engagement,
        string $capability,
    ): JsonResponse {
        $this->capabilities->revoke(
            $request->user(),
            $engagement,
            $request->validated('capability_key'),
            $request,
        );

        return $this->ok(
            $this->payload($engagement->refresh()),
            message: 'تم سحب الصلاحية.',
        );
    }

    private function authorizeManagement(
        Request $request,
        WorkerEngagement $engagement,
    ): void {
        $this->requireWorkspace($this->workspaceContext);
        Gate::forUser($request->user())->authorize('manageCapabilities', $engagement);
    }

    /**
     * @return array{
     *     engagement_id:int,
     *     status:string,
     *     capabilities:list<array{
     *         capability_key:string,
     *         display_name:string,
     *         description:string,
     *         allowed:bool
     *     }>
     * }
     */
    private function payload(WorkerEngagement $engagement): array
    {
        $data = $this->capabilities->forCompany($engagement);
        $permissions = $data['permissions'];
        $status = $engagement->status;

        return [
            'engagement_id' => (int) $engagement->id,
            'status' => $status instanceof \BackedEnum ? $status->value : (string) $status,
            'capabilities' => $data['capabilities']
                ->map(fn ($capability): array => [
                    'capability_key' => (string) $capability->capability_key,
                    'display_name' => (string) $capability->display_name,
                    'description' => (string) ($capability->description ?? ''),
                    'allowed' => $permissions->get($capability->capability_key)?->allowed === true,
                ])
                ->values()
                ->all(),
        ];
    }
}
