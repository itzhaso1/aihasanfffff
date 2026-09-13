<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmLead;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LeadService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(Workspace $workspace, array $payload): CrmLead
    {
        return CrmLead::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => trim((string) $payload['name']),
            'company_name' => $payload['company_name'] ?? null,
            'email' => $payload['email'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'source' => $payload['source'] ?? null,
            'status' => $payload['status'] ?? 'new',
            'estimated_value' => $payload['estimated_value'] ?? 0,
            'currency' => $payload['currency'] ?? 'SAR',
            'notes' => $payload['notes'] ?? null,
        ]);
    }

    public function convertToCustomer(CrmLead $lead): Customer
    {
        if ($lead->status === 'converted' && $lead->customer_id) {
            $existing = Customer::withoutGlobalScopes()
                ->where('workspace_id', $lead->workspace_id)
                ->whereKey($lead->customer_id)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($lead): Customer {
            $customer = Customer::withoutGlobalScopes()->create([
                'workspace_id' => $lead->workspace_id,
                'name' => $lead->name,
                'email' => $lead->email,
                'phone' => $lead->phone ?: 'n/a',
                'notes' => $lead->notes,
            ]);

            $lead->update([
                'status' => 'converted',
                'customer_id' => $customer->id,
                'converted_at' => now(),
            ]);

            return $customer;
        });
    }

    public function markLost(CrmLead $lead, ?string $reason = null): CrmLead
    {
        if ($lead->status === 'converted') {
            throw new RuntimeException('Converted leads cannot be marked lost.');
        }

        $lead->update([
            'status' => 'lost',
            'notes' => trim((string) $lead->notes.($reason ? "\nLost: ".$reason : '')),
        ]);

        return $lead->fresh();
    }
}
