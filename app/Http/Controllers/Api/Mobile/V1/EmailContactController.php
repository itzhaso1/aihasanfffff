<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Resources\Mobile\EmailContactResource;
use App\Models\EmailContact;
use App\Services\Email\EmailContactService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailContactController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly EmailContactService $emailContactService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $perPage = max(1, min(50, (int) $request->input('per_page', 20)));

        $paginator = $this->emailContactService->list($workspace, [
            'q' => $request->input('q', $request->input('search')),
            'favorite' => $request->has('favorite') ? $request->boolean('favorite') : null,
            'per_page' => $perPage,
        ]);

        return $this->ok(
            EmailContactResource::collection($paginator->items()),
            $this->cursorMeta(
                $paginator->nextCursor()?->encode(),
                $paginator->previousCursor()?->encode(),
                $perPage,
            ),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_favorite' => ['nullable', 'boolean'],
        ]);

        $contact = $this->emailContactService->create($workspace, $validated);

        return $this->ok(
            new EmailContactResource($contact),
            message: 'تمت إضافة جهة الاتصال بنجاح.',
            status: 201,
        );
    }

    public function show(EmailContact $contact): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $contact = $this->emailContactService->findForWorkspace($workspace, $contact->id);

        return $this->ok(new EmailContactResource($contact->load('groups')));
    }

    public function update(Request $request, EmailContact $contact): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $contact = $this->emailContactService->findForWorkspace($workspace, $contact->id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_favorite' => ['nullable', 'boolean'],
        ]);

        $contact = $this->emailContactService->update($contact, $validated);

        return $this->ok(new EmailContactResource($contact), message: 'تم تحديث جهة الاتصال.');
    }

    public function destroy(EmailContact $contact): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $contact = $this->emailContactService->findForWorkspace($workspace, $contact->id);
        $this->emailContactService->delete($contact);

        return $this->ok(null, message: 'تم حذف جهة الاتصال.');
    }

    public function recentRecipients(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $limit = max(1, min(50, (int) $request->input('limit', 20)));
        $recipients = $this->emailContactService->recentRecipients($workspace, $limit);

        return $this->ok($recipients);
    }

    public function favorite(EmailContact $contact): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $contact = $this->emailContactService->findForWorkspace($workspace, $contact->id);
        $contact = $this->emailContactService->toggleFavorite($contact);

        return $this->ok(
            new EmailContactResource($contact),
            message: $contact->is_favorite ? 'تمت الإضافة إلى المفضلة.' : 'تمت الإزالة من المفضلة.',
        );
    }
}
