<?php

declare(strict_types=1);

namespace App\AI\Tools\Adapters;

use App\AI\Tools\CanonicalTools;
use App\AI\Tools\Contracts\RuntimeTool;
use App\AI\Tools\ToolRegistry;
use App\Services\AI\Inbox\Tools\CancelBookingTool;
use App\Services\AI\Inbox\Tools\CancelCustomerOrderTool;
use App\Services\AI\Inbox\Tools\CancelOrderTool;
use App\Services\AI\Inbox\Tools\CheckBookingAvailabilityTool;
use App\Services\AI\Inbox\Tools\CheckProductAvailabilityTool;
use App\Services\AI\Inbox\Tools\CompareProductsTool;
use App\Services\AI\Inbox\Tools\CreateBookingTool;
use App\Services\AI\Inbox\Tools\CreateCustomerOrderTool;
use App\Services\AI\Inbox\Tools\EvaluateProductSuitabilityTool;
use App\Services\AI\Inbox\Tools\GetActiveOffersTool;
use App\Services\AI\Inbox\Tools\GetBookingOptionsTool;
use App\Services\AI\Inbox\Tools\GetCurrentPriceTool;
use App\Services\AI\Inbox\Tools\GetCustomerBookingsTool;
use App\Services\AI\Inbox\Tools\GetCustomerOrdersTool;
use App\Services\AI\Inbox\Tools\GetOrderDetailsTool;
use App\Services\AI\Inbox\Tools\GetOrderStatusTool;
use App\Services\AI\Inbox\Tools\GetProductDetailsTool;
use App\Services\AI\Inbox\Tools\GetProductIntelligenceTool;
use App\Services\AI\Inbox\Tools\GetProductsTool;
use App\Services\AI\Inbox\Tools\GetProductVariantsTool;
use App\Services\AI\Inbox\Tools\GetShippingInformationTool;
use App\Services\AI\Inbox\Tools\GetStoreInformationTool;
use App\Services\AI\Inbox\Tools\RequestLocationCaptureTool;
use App\Services\AI\Inbox\Tools\RescheduleBookingTool;
use App\Services\AI\Inbox\Tools\SaveCustomerCommerceContextTool;
use App\Services\AI\Inbox\Tools\SearchProductsTool;
use App\Services\AI\Inbox\Tools\SendProductImageTool;
use App\Services\AI\Inbox\Tools\TransferToHumanAgentTool;
use App\Services\AI\Inbox\Tools\UpdateBookingTool;
use App\Services\AI\Inbox\Tools\UpdateCustomerOrderTool;
use App\Services\AI\Inbox\Tools\UpdateOrderAddressTool;

final class InboxToolAdapters
{
    public function __construct(
        private readonly SearchProductsTool $searchProducts,
        private readonly GetProductsTool $getProducts,
        private readonly GetProductDetailsTool $productDetails,
        private readonly GetProductVariantsTool $productVariants,
        private readonly CheckProductAvailabilityTool $checkAvailability,
        private readonly GetCurrentPriceTool $currentPrice,
        private readonly GetActiveOffersTool $activeOffers,
        private readonly CompareProductsTool $compareProducts,
        private readonly GetShippingInformationTool $shippingInfo,
        private readonly GetStoreInformationTool $storeInfo,
        private readonly GetOrderStatusTool $orderStatus,
        private readonly GetCustomerOrdersTool $customerOrders,
        private readonly GetOrderDetailsTool $orderDetails,
        private readonly TransferToHumanAgentTool $transferToHuman,
        private readonly GetProductIntelligenceTool $productIntelligence,
        private readonly EvaluateProductSuitabilityTool $evaluateSuitability,
        private readonly SaveCustomerCommerceContextTool $saveCustomerContext,
        private readonly CreateCustomerOrderTool $createCustomerOrder,
        private readonly UpdateCustomerOrderTool $updateCustomerOrder,
        private readonly UpdateOrderAddressTool $updateOrderAddress,
        private readonly CancelCustomerOrderTool $cancelCustomerOrder,
        private readonly CancelOrderTool $cancelOrder,
        private readonly SendProductImageTool $sendProductImage,
        private readonly RequestLocationCaptureTool $requestLocationCapture,
        private readonly GetBookingOptionsTool $getBookingOptions,
        private readonly CheckBookingAvailabilityTool $checkBookingAvailability,
        private readonly GetCustomerBookingsTool $getCustomerBookings,
        private readonly CreateBookingTool $createBooking,
        private readonly RescheduleBookingTool $rescheduleBooking,
        private readonly UpdateBookingTool $updateBooking,
        private readonly CancelBookingTool $cancelBooking,
        private readonly InboxToolContextFactory $contexts = new InboxToolContextFactory,
    ) {}

    /**
     * @return list<RuntimeTool>
     */
    public function all(): array
    {
        return [
            new InboxToolAdapter($this->searchProducts, CanonicalTools::searchProducts(), $this->contexts),
            new InboxToolAdapter($this->getProducts, CanonicalTools::getProducts(), $this->contexts),
            new InboxToolAdapter($this->productDetails, CanonicalTools::getProductDetails(), $this->contexts),
            new InboxToolAdapter($this->productVariants, CanonicalTools::getProductVariants(), $this->contexts),
            new InboxToolAdapter($this->checkAvailability, CanonicalTools::checkProductAvailability(), $this->contexts),
            new InboxToolAdapter($this->currentPrice, CanonicalTools::getCurrentPrice(), $this->contexts),
            new InboxToolAdapter($this->activeOffers, CanonicalTools::getActiveOffers(), $this->contexts),
            new InboxToolAdapter($this->compareProducts, CanonicalTools::compareProducts(), $this->contexts),
            new InboxToolAdapter($this->shippingInfo, CanonicalTools::getShippingInformation(), $this->contexts),
            new InboxToolAdapter($this->storeInfo, CanonicalTools::getStoreInformation(), $this->contexts),
            new InboxToolAdapter($this->orderStatus, CanonicalTools::getOrderStatus(), $this->contexts),
            new InboxToolAdapter($this->customerOrders, CanonicalTools::getCustomerOrders(), $this->contexts),
            new InboxToolAdapter($this->orderDetails, CanonicalTools::getOrderDetails(), $this->contexts),
            new InboxToolAdapter($this->transferToHuman, CanonicalTools::transferToHumanAgent(), $this->contexts),
            new InboxToolAdapter($this->evaluateSuitability, CanonicalTools::evaluateProductSuitability(), $this->contexts),
            new InboxToolAdapter($this->productIntelligence, CanonicalTools::getProductIntelligence(), $this->contexts),
            new InboxToolAdapter($this->saveCustomerContext, CanonicalTools::saveCustomerCommerceContext(), $this->contexts),
            new InboxToolAdapter($this->createCustomerOrder, CanonicalTools::createCustomerOrder(), $this->contexts),
            new InboxToolAdapter($this->updateCustomerOrder, CanonicalTools::updateCustomerOrder(), $this->contexts),
            new InboxToolAdapter($this->updateOrderAddress, CanonicalTools::updateOrderAddress(), $this->contexts),
            new InboxToolAdapter($this->cancelCustomerOrder, CanonicalTools::cancelCustomerOrder(), $this->contexts),
            new InboxToolAdapter($this->cancelOrder, CanonicalTools::cancelOrder(), $this->contexts),
            new InboxToolAdapter($this->sendProductImage, CanonicalTools::sendProductImage(), $this->contexts),
            new InboxToolAdapter($this->requestLocationCapture, CanonicalTools::requestLocationCapture(), $this->contexts),
            new InboxToolAdapter($this->getBookingOptions, CanonicalTools::getBookingOptions(), $this->contexts),
            new InboxToolAdapter($this->checkBookingAvailability, CanonicalTools::checkBookingAvailability(), $this->contexts),
            new InboxToolAdapter($this->getCustomerBookings, CanonicalTools::getCustomerBookings(), $this->contexts),
            new InboxToolAdapter($this->createBooking, CanonicalTools::createBooking(), $this->contexts),
            new InboxToolAdapter($this->rescheduleBooking, CanonicalTools::rescheduleBooking(), $this->contexts),
            new InboxToolAdapter($this->updateBooking, CanonicalTools::updateBooking(), $this->contexts),
            new InboxToolAdapter($this->cancelBooking, CanonicalTools::cancelBooking(), $this->contexts),
        ];
    }

    public function registry(): ToolRegistry
    {
        return new ToolRegistry($this->all());
    }
}
