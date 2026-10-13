<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\Chat\ChatCatalogService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Console\Command;

class ReindexChatCatalogSearchTermsCommand extends Command
{
    protected $signature = 'chat-catalog:reindex-search {workspace? : Workspace id; omit to reindex every workspace}';

    protected $description = 'Rebuild Chat Catalog search-term rows from current item names and metadata';

    public function handle(ChatCatalogService $catalog, WorkspaceContext $context): int
    {
        $id = $this->argument('workspace');
        $query = Workspace::query()->orderBy('id');
        if ($id !== null && $id !== '') {
            $query->whereKey((int) $id);
        }

        $total = 0;
        foreach ($query->cursor() as $workspace) {
            $count = $context->runFor($workspace, fn () => $catalog->reindexWorkspace($workspace));
            $total += $count;
            $this->line("workspace {$workspace->id}: {$count} items");
        }

        $this->info("Reindexed {$total} chat catalog items.");

        return self::SUCCESS;
    }
}
