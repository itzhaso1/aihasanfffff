<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Resources\Mobile\EmailContactGroupResource;
use App\Models\EmailContactGroup;
use App\Services\Email\EmailContactGroupService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailContactGroupController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly EmailContactGroupService $emailContactGroupService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function index(): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $groups = $this->emailContactGroupService->list($workspace);

        return $this->ok(EmailContactGroupResource::collection($groups));
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $group = $this->emailContactGroupService->create($workspace, $validated);

        return $this->ok(
            new EmailContactGroupResource($group->loadCount('contacts')),
            message: 'تم إنشاء المجموعة بنجاح.',
            status: 201,
        );
    }

    public function update(Request $request, EmailContactGroup $group): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $group = $this->emailContactGroupService->findForWorkspace($workspace, $group->id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $group = $this->emailContactGroupService->update($group, $validated);

        return $this->ok(new EmailContactGroupResource($group), message: 'تم تحديث المجموعة.');
    }

    public function destroy(EmailContactGroup $group): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $group = $this->emailContactGroupService->findForWorkspace($workspace, $group->id);
        $this->emailContactGroupService->delete($group);

        return $this->ok(null, message: 'تم حذف المجموعة.');
    }

    public function syncMembers(Request $request, EmailContactGroup $group): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $group = $this->emailContactGroupService->findForWorkspace($workspace, $group->id);

        $validated = $request->validate([
            'contact_ids' => ['required', 'array'],
            'contact_ids.*' => ['integer'],
        ]);

        $group = $this->emailContactGroupService->syncMembers($group, $validated['contact_ids']);

        return $this->ok(new EmailContactGroupResource($group), message: 'تم تحديث أعضاء المجموعة.');
    }
}
