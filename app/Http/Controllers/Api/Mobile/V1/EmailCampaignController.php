<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Exceptions\FeatureNotAvailableException;
use App\Exceptions\UsageLimitExceededException;
use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Resources\Mobile\EmailCampaignResource;
use App\Models\EmailCampaign;
use App\Services\Email\EmailCampaignService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class EmailCampaignController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly EmailCampaignService $emailCampaignService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);

        $validated = $request->validate([
            'email_account_id' => [
                'required',
                'integer',
                Rule::exists('email_accounts', 'id')->where(fn ($q) => $q->where('workspace_id', $workspace->id)),
            ],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'contact_ids' => ['nullable', 'array'],
            'contact_ids.*' => ['integer'],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer'],
            'all_contacts' => ['nullable', 'boolean'],
            'emails' => ['nullable', 'array'],
            'emails.*' => ['email', 'max:255'],
            'confirm_all' => ['nullable', 'boolean'],
        ]);

        try {
            $campaign = $this->emailCampaignService->createAndQueue(
                $workspace,
                $request->user(),
                $validated,
            );
        } catch (FeatureNotAvailableException|UsageLimitExceededException $exception) {
            return $this->fail($exception->getMessage(), 402);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(
            new EmailCampaignResource($campaign),
            message: 'تم جدولة الحملة للإرسال.',
            status: 201,
        );
    }

    public function show(EmailCampaign $campaign): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $campaign = $this->emailCampaignService->findForWorkspace($workspace, $campaign->id);

        return $this->ok(new EmailCampaignResource($campaign));
    }

    public function cancel(Request $request, EmailCampaign $campaign): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $campaign = $this->emailCampaignService->findForWorkspace($workspace, $campaign->id);

        try {
            $campaign = $this->emailCampaignService->cancel($campaign, $request->user());
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new EmailCampaignResource($campaign), message: 'تم إلغاء الحملة.');
    }
}
