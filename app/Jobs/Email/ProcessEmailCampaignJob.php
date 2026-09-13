<?php

namespace App\Jobs\Email;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Services\Email\EmailCampaignService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessEmailCampaignJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $campaignId,
        public readonly ?int $workspaceId = null,
    ) {}

    public function handle(EmailCampaignService $emailCampaignService): void
    {
        $query = EmailCampaign::withoutGlobalScopes()->whereKey($this->campaignId);
        if ($this->workspaceId !== null) {
            $query->where('workspace_id', $this->workspaceId);
        }

        $campaign = $query->first();

        if (! $campaign) {
            return;
        }

        if ($campaign->status === EmailCampaign::STATUS_CANCELLED) {
            return;
        }

        if (! in_array($campaign->status, [
            EmailCampaign::STATUS_QUEUED,
            EmailCampaign::STATUS_SENDING,
        ], true)) {
            return;
        }

        app(WorkspaceContext::class)->runForId((int) $campaign->workspace_id, function () use ($campaign, $emailCampaignService): void {
            $campaign->forceFill([
                'status' => EmailCampaign::STATUS_SENDING,
                'started_at' => $campaign->started_at ?? now(),
            ])->save();

            EmailCampaignRecipient::query()
                ->where('email_campaign_id', $campaign->id)
                ->where('status', EmailCampaignRecipient::STATUS_PENDING)
                ->orderBy('id')
                ->chunkById(20, function ($recipients) use ($campaign): void {
                    foreach ($recipients as $recipient) {
                        SendCampaignRecipientJob::dispatch($recipient->id, (int) $campaign->workspace_id);
                    }
                });

            $emailCampaignService->refreshCampaignStatus($campaign);
        });
    }
}
