<?php

namespace App\Services\Order;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WorkspaceOrderNumberGenerator
{
    public function nextCommerceNumber(int $workspaceId): string
    {
        return $this->nextNumber($workspaceId, 'ORD-', 'next_commerce_sequence');
    }

    public function nextPosNumber(int $workspaceId): string
    {
        return $this->nextNumber($workspaceId, 'POS-', 'next_pos_sequence');
    }

    private function nextNumber(int $workspaceId, string $prefix, string $sequenceColumn): string
    {
        return $this->allocateNumber($workspaceId, $prefix, $sequenceColumn);
    }

    private function allocateNumber(int $workspaceId, string $prefix, string $sequenceColumn): string
    {
        DB::table('workspace_order_sequences')->insertOrIgnore([
            'workspace_id' => $workspaceId,
            'next_commerce_sequence' => 1,
            'next_pos_sequence' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('workspace_order_sequences')
            ->where('workspace_id', $workspaceId)
            ->lockForUpdate()
            ->first();

        if (! $row) {
            throw new RuntimeException('Unable to initialize order sequence for this workspace.');
        }

        $sequence = max(1, (int) ($row->{$sequenceColumn} ?? 1));
        $attempts = 0;

        while ($attempts < 100) {
            $candidate = $prefix.str_pad((string) $sequence, 8, '0', STR_PAD_LEFT);
            $exists = Order::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('order_number', $candidate)
                ->exists();

            if (! $exists) {
                DB::table('workspace_order_sequences')
                    ->where('workspace_id', $workspaceId)
                    ->update([
                        $sequenceColumn => $sequence + 1,
                        'updated_at' => now(),
                    ]);

                return $candidate;
            }

            $sequence++;
            $attempts++;
        }

        throw new RuntimeException('Unable to allocate a unique order number for this workspace.');
    }
}
