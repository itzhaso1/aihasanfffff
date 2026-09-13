<?php

namespace App\Jobs\Stories;

use App\Services\Stories\StoryService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExpireStoriesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(StoryService $storyService): void
    {
        app(WorkspaceContext::class)->runBypassingTenantScope(
            WorkspaceContext::BYPASS_STORIES_EXPIRE_SCHEDULER,
            fn () => $storyService->expireOldStories(),
        );
    }
}
