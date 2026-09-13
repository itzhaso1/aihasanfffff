<?php

namespace App\Services\Pos;

use App\Models\DiningTable;
use App\Models\PosCustomerSession;
use App\Models\TableSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class TableGuestSessionService
{
    public const COOKIE_PREFIX = 'pos_guest_';

    public function cookieName(DiningTable $table): string
    {
        return self::COOKIE_PREFIX.$table->id;
    }

    /**
     * Resolve an existing guest session for an open table session, or create both as needed.
     * Refresh / new tabs reuse the same cookie token while it remains active.
     */
    public function bootstrap(DiningTable $table, ?string $incomingToken): PosCustomerSession
    {
        return DB::transaction(function () use ($table, $incomingToken): PosCustomerSession {
            if ($incomingToken) {
                $existing = $this->findActiveGuest($table, $incomingToken);
                if ($existing) {
                    $existing->update(['last_seen_at' => now()]);

                    return $existing->fresh(['tableSession', 'table']);
                }
            }

            $tableSession = $this->ensureOpenTableSession($table);

            return $this->createGuestSession($table, $tableSession);
        });
    }

    /**
     * Validate guest token for placing an order. Never trusts client-supplied table ids.
     *
     * @throws RuntimeException
     */
    public function assertValidForOrder(DiningTable $table, ?string $token): PosCustomerSession
    {
        if (! is_string($token) || trim($token) === '') {
            throw new RuntimeException('انتهت جلسة هذه الطاولة. يرجى بدء جلسة جديدة.');
        }

        $guest = PosCustomerSession::withoutGlobalScopes()
            ->with('tableSession')
            ->where('workspace_id', $table->workspace_id)
            ->where('dining_table_id', $table->id)
            ->where('token', $token)
            ->first();

        if (! $guest || ! $guest->isActive()) {
            throw new RuntimeException('انتهت جلسة هذه الطاولة. يرجى بدء جلسة جديدة.');
        }

        $session = $guest->tableSession;
        if (! $session || $session->status !== 'open') {
            throw new RuntimeException('انتهت جلسة هذه الطاولة. يرجى بدء جلسة جديدة.');
        }

        if ((int) $session->dining_table_id !== (int) $table->id) {
            throw new RuntimeException('جلسة غير صالحة لهذه الطاولة.');
        }

        if ((int) $guest->workspace_id !== (int) $table->workspace_id) {
            throw new RuntimeException('جلسة غير صالحة لهذه الطاولة.');
        }

        $guest->update(['last_seen_at' => now()]);

        return $guest->fresh(['tableSession', 'table']);
    }

    public function revokeForTableSession(TableSession $session): int
    {
        return PosCustomerSession::withoutGlobalScopes()
            ->where('table_session_id', $session->id)
            ->where('status', PosCustomerSession::STATUS_ACTIVE)
            ->update([
                'status' => PosCustomerSession::STATUS_REVOKED,
                'revoked_at' => now(),
            ]);
    }

    public function startFresh(DiningTable $table): PosCustomerSession
    {
        return DB::transaction(function () use ($table): PosCustomerSession {
            $tableSession = $this->ensureOpenTableSession($table);

            return $this->createGuestSession($table, $tableSession);
        });
    }

    private function findActiveGuest(DiningTable $table, string $token): ?PosCustomerSession
    {
        $guest = PosCustomerSession::withoutGlobalScopes()
            ->with('tableSession')
            ->where('workspace_id', $table->workspace_id)
            ->where('dining_table_id', $table->id)
            ->where('token', $token)
            ->where('status', PosCustomerSession::STATUS_ACTIVE)
            ->first();

        if (! $guest) {
            return null;
        }

        $session = $guest->tableSession;
        if (! $session || $session->status !== 'open') {
            return null;
        }

        return $guest;
    }

    private function ensureOpenTableSession(DiningTable $table): TableSession
    {
        $lockedTable = DiningTable::withoutGlobalScopes()
            ->whereKey($table->id)
            ->lockForUpdate()
            ->firstOrFail();

        $session = TableSession::withoutGlobalScopes()
            ->where('dining_table_id', $lockedTable->id)
            ->where('status', 'open')
            ->lockForUpdate()
            ->latest('id')
            ->first();

        if ($session) {
            return $session;
        }

        return TableSession::query()->create([
            'workspace_id' => $lockedTable->workspace_id,
            'dining_table_id' => $lockedTable->id,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    private function createGuestSession(DiningTable $table, TableSession $tableSession): PosCustomerSession
    {
        return PosCustomerSession::query()->create([
            'workspace_id' => $table->workspace_id,
            'dining_table_id' => $table->id,
            'table_session_id' => $tableSession->id,
            'token' => Str::random(64),
            'status' => PosCustomerSession::STATUS_ACTIVE,
            'last_seen_at' => now(),
        ])->fresh(['tableSession', 'table']);
    }
}
