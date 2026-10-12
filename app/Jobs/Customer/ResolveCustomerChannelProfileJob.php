<?php

namespace App\Jobs\Customer;

use App\Models\CustomerChannelIdentity;
use App\Services\Customer\CustomerChannelIdentityService;
use App\Services\Meta\Facebook\FacebookProfileResolver;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Best-effort provider profile resolution (display name). Non-blocking to inbound ingestion.
 *
 * Uses the media queue (config `media.queue`) alongside inbound media jobs; production must
 * run a worker that processes that queue (e.g. hasem-worker-media).
 */
class ResolveCustomerChannelProfileJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct(
        public readonly int $identityId,
        public readonly int $workspaceId,
    ) {
        $this->onQueue((string) config('media.queue', 'media'));
    }

    public function handle(CustomerChannelIdentityService $identityService): void
    {
        app(WorkspaceContext::class)->runForId($this->workspaceId, function () use ($identityService): void {
            $identity = CustomerChannelIdentity::withoutGlobalScopes()
                ->whereKey($this->identityId)
                ->where('workspace_id', $this->workspaceId)
                ->first();

            if (! $identity) {
                return;
            }

            if ($identity->channel !== 'facebook_messenger') {
                return;
            }

            try {
                $profile = app(FacebookProfileResolver::class)->resolveForIdentity($identity);
            } catch (\Throwable $exception) {
                Log::info('customer.profile.resolve_failed', [
                    'workspace_id' => $this->workspaceId,
                    'identity_id' => $identity->id,
                    'channel' => $identity->channel,
                    'reason' => $exception->getMessage(),
                ]);

                return;
            }

            if (! is_array($profile)) {
                Log::info('customer.profile.no_profile', [
                    'workspace_id' => $this->workspaceId,
                    'identity_id' => $identity->id,
                    'channel' => $identity->channel,
                ]);

                return;
            }

            $displayName = is_string($profile['display_name'] ?? null) ? trim($profile['display_name']) : '';
            if ($displayName !== '') {
                $identity->forceFill(['display_name' => $displayName])->save();
                $customer = $identity->customer()->first();
                if ($customer) {
                    $identityService->applyDisplayNameToCustomer($customer, $displayName);
                }
            }
        });
    }
}
