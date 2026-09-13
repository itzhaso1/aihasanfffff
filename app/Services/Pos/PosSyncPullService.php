<?php

namespace App\Services\Pos;

use App\Models\PosSyncChange;
use App\Models\Workspace;

class PosSyncPullService
{
    /**
     * Incremental pull scoped strictly to the authenticated workspace.
     *
     * @return array{cursor: int, server_cursor: int, has_more: bool, changes: list<array<string, mixed>>}
     */
    public function changes(Workspace $workspace, int $since, int $limit = 200): array
    {
        $since = max(0, $since);
        $limit = max(0, min($limit, 500));

        $serverCursor = (int) (PosSyncChange::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->max('id') ?? 0);

        if ($limit === 0) {
            return [
                'cursor' => $serverCursor,
                'server_cursor' => $serverCursor,
                'has_more' => false,
                'changes' => [],
            ];
        }

        $rows = PosSyncChange::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('id', '>', $since)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        if ($hasMore) {
            $rows = $rows->take($limit)->values();
        }

        $changes = [];
        $cursor = $since;
        foreach ($rows as $row) {
            $cursor = (int) $row->id;
            $changes[] = [
                'version' => (int) $row->id,
                'entity' => $row->entity_type,
                'operation' => $row->operation,
                'id' => $row->entity_id,
                'origin_device_id' => $row->origin_device_id,
                'created_at' => optional($row->created_at)?->toIso8601String(),
                'data' => $row->payload ?? [],
            ];
        }

        if ($changes === []) {
            $cursor = $serverCursor;
        }

        return [
            'cursor' => $cursor,
            'server_cursor' => $serverCursor,
            'has_more' => $hasMore,
            'changes' => $changes,
        ];
    }
}
