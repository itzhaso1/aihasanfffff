<?php

declare(strict_types=1);

namespace App\AI\Tools\Adapters;

use App\AI\Tools\CanonicalTools;
use App\AI\Tools\Contracts\RuntimeTool;
use App\AI\Tools\ToolRegistry;
use App\Services\AI\Inbox\Tools\EvaluateProductSuitabilityTool;
use App\Services\AI\Inbox\Tools\GetProductIntelligenceTool;
use App\Services\AI\Inbox\Tools\SaveCustomerCommerceContextTool;
use App\Services\AI\Inbox\Tools\SearchProductsTool;

final class InboxToolAdapters
{
    public function __construct(
        private readonly SearchProductsTool $searchProducts,
        private readonly GetProductIntelligenceTool $productIntelligence,
        private readonly EvaluateProductSuitabilityTool $evaluateSuitability,
        private readonly SaveCustomerCommerceContextTool $saveCustomerContext,
        private readonly InboxToolContextFactory $contexts = new InboxToolContextFactory,
    ) {}

    /**
     * @return list<RuntimeTool>
     */
    public function all(): array
    {
        return [
            new InboxToolAdapter($this->searchProducts, CanonicalTools::searchProducts(), $this->contexts),
            new InboxToolAdapter($this->evaluateSuitability, CanonicalTools::evaluateProductSuitability(), $this->contexts),
            new InboxToolAdapter($this->productIntelligence, CanonicalTools::getProductIntelligence(), $this->contexts),
            new InboxToolAdapter($this->saveCustomerContext, CanonicalTools::saveCustomerCommerceContext(), $this->contexts),
        ];
    }

    public function registry(): ToolRegistry
    {
        return new ToolRegistry($this->all());
    }
}
