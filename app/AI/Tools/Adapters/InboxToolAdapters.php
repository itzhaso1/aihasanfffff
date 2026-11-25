<?php

declare(strict_types=1);

namespace App\AI\Tools\Adapters;

use App\AI\Tools\CanonicalTools;
use App\AI\Tools\Contracts\RuntimeTool;
use App\AI\Tools\ToolRegistry;
use App\Services\AI\Inbox\Tools\CheckProductAvailabilityTool;
use App\Services\AI\Inbox\Tools\CompareProductsTool;
use App\Services\AI\Inbox\Tools\CreateCustomerOrderTool;
use App\Services\AI\Inbox\Tools\EvaluateProductSuitabilityTool;
use App\Services\AI\Inbox\Tools\GetActiveOffersTool;
use App\Services\AI\Inbox\Tools\GetCurrentPriceTool;
use App\Services\AI\Inbox\Tools\GetOrderStatusTool;
use App\Services\AI\Inbox\Tools\GetProductDetailsTool;
use App\Services\AI\Inbox\Tools\GetProductIntelligenceTool;
use App\Services\AI\Inbox\Tools\GetProductVariantsTool;
use App\Services\AI\Inbox\Tools\GetShippingInformationTool;
use App\Services\AI\Inbox\Tools\GetStoreInformationTool;
use App\Services\AI\Inbox\Tools\SaveCustomerCommerceContextTool;
use App\Services\AI\Inbox\Tools\SearchProductsTool;
use App\Services\AI\Inbox\Tools\SendProductImageTool;
use App\Services\AI\Inbox\Tools\TransferToHumanAgentTool;

final class InboxToolAdapters
{
    public function __construct(
        private readonly SearchProductsTool $searchProducts,
        private readonly GetProductDetailsTool $productDetails,
        private readonly GetProductVariantsTool $productVariants,
        private readonly CheckProductAvailabilityTool $checkAvailability,
        private readonly GetCurrentPriceTool $currentPrice,
        private readonly GetActiveOffersTool $activeOffers,
        private readonly CompareProductsTool $compareProducts,
        private readonly GetShippingInformationTool $shippingInfo,
        private readonly GetStoreInformationTool $storeInfo,
        private readonly GetOrderStatusTool $orderStatus,
        private readonly TransferToHumanAgentTool $transferToHuman,
        private readonly GetProductIntelligenceTool $productIntelligence,
        private readonly EvaluateProductSuitabilityTool $evaluateSuitability,
        private readonly SaveCustomerCommerceContextTool $saveCustomerContext,
        private readonly CreateCustomerOrderTool $createCustomerOrder,
        private readonly SendProductImageTool $sendProductImage,
        private readonly InboxToolContextFactory $contexts = new InboxToolContextFactory,
    ) {}

    /**
     * @return list<RuntimeTool>
     */
    public function all(): array
    {
        return [
            new InboxToolAdapter($this->searchProducts, CanonicalTools::searchProducts(), $this->contexts),
            new InboxToolAdapter($this->productDetails, CanonicalTools::getProductDetails(), $this->contexts),
            new InboxToolAdapter($this->productVariants, CanonicalTools::getProductVariants(), $this->contexts),
            new InboxToolAdapter($this->checkAvailability, CanonicalTools::checkProductAvailability(), $this->contexts),
            new InboxToolAdapter($this->currentPrice, CanonicalTools::getCurrentPrice(), $this->contexts),
            new InboxToolAdapter($this->activeOffers, CanonicalTools::getActiveOffers(), $this->contexts),
            new InboxToolAdapter($this->compareProducts, CanonicalTools::compareProducts(), $this->contexts),
            new InboxToolAdapter($this->shippingInfo, CanonicalTools::getShippingInformation(), $this->contexts),
            new InboxToolAdapter($this->storeInfo, CanonicalTools::getStoreInformation(), $this->contexts),
            new InboxToolAdapter($this->orderStatus, CanonicalTools::getOrderStatus(), $this->contexts),
            new InboxToolAdapter($this->transferToHuman, CanonicalTools::transferToHumanAgent(), $this->contexts),
            new InboxToolAdapter($this->evaluateSuitability, CanonicalTools::evaluateProductSuitability(), $this->contexts),
            new InboxToolAdapter($this->productIntelligence, CanonicalTools::getProductIntelligence(), $this->contexts),
            new InboxToolAdapter($this->saveCustomerContext, CanonicalTools::saveCustomerCommerceContext(), $this->contexts),
            new InboxToolAdapter($this->createCustomerOrder, CanonicalTools::createCustomerOrder(), $this->contexts),
            new InboxToolAdapter($this->sendProductImage, CanonicalTools::sendProductImage(), $this->contexts),
        ];
    }

    public function registry(): ToolRegistry
    {
        return new ToolRegistry($this->all());
    }
}
