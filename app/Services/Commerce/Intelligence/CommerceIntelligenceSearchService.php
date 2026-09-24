<?php

namespace App\Services\Commerce\Intelligence;

use App\Models\ChatCatalogItem;
use App\Models\Workspace;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CommerceIntelligenceSearchService
{
    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    /**
     * Refines a ChatCatalogItem query builder using intelligence attribute filters.
     *
     * @param  array<string, mixed>  $attributeFilters  e.g. ['size' => 'L', 'color' => 'Blue']
     */
    public function filterCatalogQuery(
        Workspace $workspace,
        Builder $query,
        array $attributeFilters,
    ): Builder {
        if ($attributeFilters === []) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($workspace, $attributeFilters): void {
            foreach ($attributeFilters as $key => $val) {
                if ($val === null || $val === '') {
                    continue;
                }

                $q->whereHas('intelligenceAttributeValues', function (Builder $attrQuery) use ($workspace, $key, $val): void {
                    $attrQuery->where('workspace_id', $workspace->id)
                        ->whereHas('definition', fn ($def) => $def->where('key', $key))
                        ->where(function ($valQuery) use ($val): void {
                            if (is_numeric($val)) {
                                $valQuery->where('value_number', (float) $val)->orWhere('value_string', (string) $val);
                            } elseif (is_bool($val)) {
                                $valQuery->where('value_boolean', $val);
                            } else {
                                $valQuery->where('value_string', (string) $val);
                            }
                        });
                });
            }
        });
    }

    /**
     * Search compatible items for a given customer context reference.
     */
    public function searchCompatibleItems(
        Workspace $workspace,
        array $targetContext,
        int $limit = 20,
    ): Collection {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $limit): Collection {
            return ChatCatalogItem::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->where('publication_status', 'published')
                ->whereHas('intelligenceCompatibilities', function (Builder $compatQuery) use ($workspace): void {
                    $compatQuery->where('workspace_id', $workspace->id)
                        ->where('status', 'active');
                })
                ->limit($limit)
                ->get();
        });
    }
}
