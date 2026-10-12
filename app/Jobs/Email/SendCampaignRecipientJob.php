<?php

namespace App\Jobs\Email;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Services\Email\EmailCampaignService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendCampaignRecipientJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public readonly int $recipientId,
        public readonly ?int $workspaceId = null,
    ) {}

    public function handle(EmailCampaignService $emailCampaignService): void
    {
        $recipientQuery = EmailCampaignRecipient::withoutGlobalScopes()->whereKey($this->recipientId);
        if ($this->workspaceId !== null) {
            $recipientQuery->where('workspace_id', $this->workspaceId);
        }
        $recipient = $recipientQuery->first();

        if (! $recipient) {
            return;
        }

        $campaignQuery = EmailCampaign::withoutGlobalScopes()
            ->whereKey($recipient->email_campaign_id);
        if ($this->workspaceId !== null) {
            $campaignQuery->where('workspace_id', $this->workspaceId);
        }

        $campaign = $campaignQuery->first();
        if (! $campaign?->workspace_id) {
            return;
        }

        app(WorkspaceContext::class)->runForId((int) $campaign->workspace_id, function () use ($emailCampaignService, $recipient, $campaign): void {
            try {
                $emailCampaignService->sendRecipient($recipient);
            } catch (Throwable $exception) {
                Log::warning('campaign-recipient-send-failed', [
                    'recipient_id' => $this->recipientId,
                    'campaign_id' => $recipient->email_campaign_id,
                    'error' => $exception->getMessage(),
                ]);
                // Failure already persisted by EmailCampaignService::sendRecipient.
            }

            $emailCampaignService->refreshCampaignStatus($campaign);
        });
    }
}
