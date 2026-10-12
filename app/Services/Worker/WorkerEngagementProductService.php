<?php

declare(strict_types=1);

namespace App\Services\Worker;

use App\Models\ChatCatalogItem;
use App\Models\User;
use App\Models\Worker\WorkerEngagementProduct;
use Illuminate\Database\Eloquent\Collection;

final class WorkerEngagementProductService
{
    public function __construct(
        private readonly WorkerEngagementService $engagements,
        private readonly WorkerEngagementAuthorization $authorization,
    ) {}

    /**
     * @return Collection<int, ChatCatalogItem>
     */
    public function forWorker(User $worker, int $engagementId): Collection
    {
        $engagement = $this->engagements->forWorkerOrFail($worker, $engagementId);
        $workspace = $engagement->workspace;

        if ($workspace === null) {
            return new Collection;
        }

        $this->authorization->authorizeAssignedProducts(
            $worker,
            $workspace,
            $engagement,
        );

        $assignments = WorkerEngagementProduct::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('worker_engagement_id', $engagement->id)
            ->whereHas('engagement', function ($query) use ($workspace): void {
                $query
                    ->withoutGlobalScopes()
                    ->where('workspace_id', $workspace->id);
            })
            ->whereHas('product', function ($query) use ($workspace): void {
                $query
                    ->withoutGlobalScope('workspace')
                    ->where('workspace_id', $workspace->id)
                    ->where('is_active', true)
                    ->where('is_visible', true)
                    ->where('publication_status', 'published');
            })
            ->with([
                'product' => function ($query): void {
                    $query->withoutGlobalScope('workspace');
                },
                'product.category' => function ($query): void {
                    $query->withoutGlobalScope('workspace');
                },
            ])
            ->orderBy('id')
            ->get();

        return $assignments
            ->map(function (WorkerEngagementProduct $assignment): ?ChatCatalogItem {
                $product = $assignment->product;
                if ($product === null) {
                    return null;
                }

                $product->setRelation('workerEngagementProduct', $assignment);

                return $product;
            })
            ->filter(fn (?ChatCatalogItem $product): bool => $product !== null)
            ->values();
    }
}
