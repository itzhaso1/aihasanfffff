<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerChannelIdentity;
use App\Services\Customer\CustomerChannelIdentityService;
use App\Services\Meta\Instagram\InstagramWebhookService;
use Illuminate\Console\Command;

class BackfillCustomerChannelIdentities extends Command
{
    protected $signature = 'customers:backfill-channel-identities {--workspace=}';

    protected $description = 'Create customer_channel_identities from existing channel-specific customers (no merges).';

    public function handle(CustomerChannelIdentityService $identityService): int
    {
        $workspaceFilter = $this->option('workspace');

        $query = Customer::withoutGlobalScopes();
        if ($workspaceFilter) {
            $query->where('workspace_id', (int) $workspaceFilter);
        }

        $created = 0;

        $query->orderBy('id')->chunkById(200, function ($customers) use ($identityService, &$created): void {
            foreach ($customers as $customer) {
                $created += $this->backfillCustomer($customer, $identityService);
            }
        });

        $this->info("Backfill complete. New identity rows attempted: {$created}");

        return self::SUCCESS;
    }

    private function backfillCustomer(Customer $customer, CustomerChannelIdentityService $identityService): int
    {
        $count = 0;
        $reference = trim((string) ($customer->client_reference ?? ''));

        if (str_starts_with($reference, InstagramWebhookService::CUSTOMER_REFERENCE_PREFIX)) {
            $igsid = substr($reference, strlen(InstagramWebhookService::CUSTOMER_REFERENCE_PREFIX));
            if ($igsid !== '') {
                $identityService->ensureIdentity(
                    (int) $customer->workspace_id,
                    (int) $customer->id,
                    'instagram',
                    $igsid,
                    displayName: $customer->name !== 'Instagram User' ? $customer->name : null,
                );
                $count++;
            }
        }

        $whatsapp = trim((string) ($customer->whatsapp ?? ''));
        if ($whatsapp !== '' && ! str_starts_with($whatsapp, 'ig:')) {
            $identityService->ensureIdentity(
                (int) $customer->workspace_id,
                (int) $customer->id,
                'whatsapp',
                $whatsapp,
                displayName: str_starts_with((string) $customer->name, 'Customer ') ? null : $customer->name,
            );
            $count++;
        }

        $conversation = Conversation::withoutGlobalScopes()
            ->where('workspace_id', $customer->workspace_id)
            ->where('customer_id', $customer->id)
            ->where('channel', 'facebook_messenger')
            ->first();

        if ($conversation) {
            $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
            $fbUserId = trim((string) ($metadata['facebook_user_id'] ?? $conversation->external_id ?? ''));
            if ($fbUserId !== '') {
                $identityService->ensureIdentity(
                    (int) $customer->workspace_id,
                    (int) $customer->id,
                    'facebook_messenger',
                    $fbUserId,
                    displayName: $customer->name !== 'Facebook User' ? $customer->name : null,
                );
                $count++;
            }
        }

        if ($count === 0) {
            return 0;
        }

        return CustomerChannelIdentity::withoutGlobalScopes()
            ->where('workspace_id', $customer->workspace_id)
            ->where('customer_id', $customer->id)
            ->count();
    }
}
