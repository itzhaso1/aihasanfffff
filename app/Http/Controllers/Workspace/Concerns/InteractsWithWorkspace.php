<?php

namespace App\Http\Controllers\Workspace\Concerns;

use App\Models\Workspace;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\Request;

trait InteractsWithWorkspace
{
    protected function currentWorkspace(): Workspace
    {
        $workspace = app(WorkspaceContext::class)->workspace();
        abort_unless($workspace, 422, 'Workspace is not resolved.');

        return $workspace;
    }

    protected function assertSameWorkspace(int|string|null $workspaceId): void
    {
        abort_unless((int) $workspaceId === (int) $this->currentWorkspace()->id, 404);
    }

    protected function parseJsonField(Request $request, string $field, array $fallback = []): array
    {
        $raw = trim((string) $request->input($field, ''));
        if ($raw === '') {
            return $fallback;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : $fallback;
    }
}
