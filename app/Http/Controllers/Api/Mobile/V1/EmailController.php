<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Exceptions\FeatureNotAvailableException;
use App\Exceptions\UsageLimitExceededException;
use App\Http\Controllers\Api\Mobile\Concerns\AuthorizesMobileEmail;
use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Resources\Mobile\EmailMessageResource;
use App\Models\EmailAccount;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Services\Mobile\MobileEmailService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;

class EmailController extends MobileController
{
    use AuthorizesMobileEmail;
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly MobileEmailService $mobileEmailService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function inbox(Request $request): JsonResponse
    {
        $this->authorizeEmailUse($request);

        return $this->listFolder($request, 'inbox');
    }

    public function sent(Request $request): JsonResponse
    {
        $this->authorizeEmailUse($request);

        return $this->listFolder($request, 'sent');
    }

    public function drafts(Request $request): JsonResponse
    {
        $this->authorizeEmailUse($request);

        return $this->listFolder($request, 'drafts');
    }

    public function show(Request $request, EmailMessage $emailMessage): JsonResponse
    {
        $this->authorizeEmailUse($request);
        $message = $this->mobileEmailService->show($emailMessage);

        return $this->ok(new EmailMessageResource($message, detailed: true));
    }

    public function send(Request $request): JsonResponse
    {
        $this->authorizeEmailUse($request);
        $workspace = $this->requireWorkspace($this->workspaceContext);

        $validated = $request->validate([
            'email_account_id' => [
                'required',
                'integer',
                Rule::exists('email_accounts', 'id')->where(fn ($query) => $query->where('workspace_id', $workspace->id)),
            ],
            'to' => ['required', 'string', 'max:4000'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'reply_to_message_id' => [
                'nullable',
                'integer',
                Rule::exists('email_messages', 'id')->where(fn ($query) => $query->where('workspace_id', $workspace->id)),
            ],
        ]);

        try {
            $message = $this->mobileEmailService->send($workspace, $request->user(), $validated);
        } catch (FeatureNotAvailableException|UsageLimitExceededException $exception) {
            return $this->fail($exception->getMessage(), 402);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new EmailMessageResource($message, detailed: true), message: 'تم إرسال البريد بنجاح.', status: 201);
    }

    public function read(Request $request, EmailMessage $emailMessage): JsonResponse
    {
        $this->authorizeEmailUse($request);
        $message = $this->mobileEmailService->markRead($emailMessage);

        return $this->ok(new EmailMessageResource($message));
    }

    public function star(Request $request, EmailMessage $emailMessage): JsonResponse
    {
        $this->authorizeEmailUse($request);
        $message = $this->mobileEmailService->toggleStar($emailMessage);

        return $this->ok(new EmailMessageResource($message));
    }

    public function downloadAttachment(Request $request, EmailAttachment $emailAttachment)
    {
        $this->authorizeEmailUse($request);
        $workspace = $this->requireWorkspace($this->workspaceContext);
        abort_unless((int) $emailAttachment->workspace_id === (int) $workspace->id, 404);
        try {
            $path = $emailAttachment->assertSafeStoragePath();
        } catch (\RuntimeException) {
            abort(404);
        }
        $disk = Storage::disk($emailAttachment->storageDisk());
        abort_unless($disk->exists($path), 404);

        return $disk->download($path, basename($path));
    }

    public function accounts(Request $request): JsonResponse
    {
        $this->authorizeEmailUse($request);
        $this->requireWorkspace($this->workspaceContext);

        $accounts = EmailAccount::query()
            ->orderBy('name')
            ->get()
            ->map(fn (EmailAccount $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'email' => $account->email,
                'brand_color' => $account->brand_color,
                'logo_url' => $account->logo_path
                    ? Storage::disk('public')->url($account->logo_path)
                    : null,
            ])
            ->values();

        return $this->ok($accounts);
    }

    private function listFolder(Request $request, string $folder): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $perPage = max(1, min(50, (int) $request->input('per_page', 20)));

        $filters = [
            'search' => $request->input('search'),
            'per_page' => $perPage,
            'email_account_id' => $request->input('email_account_id'),
        ];

        $paginator = match ($folder) {
            'inbox' => $this->mobileEmailService->inbox($workspace, $filters),
            'sent' => $this->mobileEmailService->sent($workspace, $filters),
            'drafts' => $this->mobileEmailService->drafts($workspace, $filters),
            default => $this->mobileEmailService->inbox($workspace, $filters),
        };

        return $this->ok(
            EmailMessageResource::collection($paginator->items()),
            $this->cursorMeta(
                $paginator->nextCursor()?->encode(),
                $paginator->previousCursor()?->encode(),
                $perPage,
            ),
        );
    }
}
