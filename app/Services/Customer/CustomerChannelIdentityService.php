<?php

namespace App\Services\Customer;

use App\Models\Customer;
use App\Models\CustomerChannelIdentity;
use Illuminate\Database\UniqueConstraintViolationException;

final class CustomerChannelIdentityService
{
    public function ensureIdentity(
        int $workspaceId,
        int $customerId,
        string $channel,
        string $externalUserId,
        ?string $displayName = null,
        ?string $username = null,
        ?array $metadata = null,
    ): CustomerChannelIdentity {
        $channel = strtolower(trim($channel));
        $externalUserId = trim($externalUserId);

        try {
            return CustomerChannelIdentity::withoutGlobalScopes()->firstOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'channel' => $channel,
                    'external_user_id' => $externalUserId,
                ],
                [
                    'customer_id' => $customerId,
                    'display_name' => $displayName,
                    'username' => $username,
                    'metadata' => $metadata,
                ],
            );
        } catch (UniqueConstraintViolationException) {
            $existing = CustomerChannelIdentity::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('channel', $channel)
                ->where('external_user_id', $externalUserId)
                ->firstOrFail();

            if ((int) $existing->customer_id !== $customerId) {
                return $existing;
            }

            if ($displayName !== null && $existing->display_name !== $displayName) {
                $existing->forceFill(['display_name' => $displayName])->save();
            }

            return $existing;
        }
    }

    public function customerNameIsFallback(?string $name): bool
    {
        $current = trim((string) $name);
        if ($current === '') {
            return true;
        }

        foreach ($this->fallbackNamePrefixes() as $prefix) {
            if (str_starts_with($current, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function applyDisplayNameToCustomer(Customer $customer, ?string $displayName): void
    {
        if ($displayName === null || trim($displayName) === '') {
            return;
        }

        if ($this->customerNameIsFallback($customer->name)) {
            $customer->forceFill(['name' => trim($displayName)])->save();
        }
    }

    /**
     * @return list<string>
     */
    private function fallbackNamePrefixes(): array
    {
        return ['Customer ', 'Instagram User', 'Facebook User', 'WhatsApp User'];
    }
}
