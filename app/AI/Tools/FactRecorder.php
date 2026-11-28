<?php

declare(strict_types=1);

namespace App\AI\Tools;

use App\AI\Core\AiRequest;
use App\AI\Core\Decision\ConfirmedAction;
use App\AI\Core\Decision\FactRecord;
use App\Support\Chat\CatalogAvailabilityFact;

final class FactRecorder
{
    private const MAX_PRODUCT_FACTS = 8;

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $payload
     * @return list<FactRecord>
     */
    public function facts(string $toolName, array $arguments, array $payload): array
    {
        return match ($toolName) {
            CanonicalTools::SEARCH_PRODUCTS => $this->searchFacts($payload),
            CanonicalTools::GET_ACTIVE_OFFERS,
            CanonicalTools::COMPARE_PRODUCTS => $this->searchFacts($payload, $toolName),
            CanonicalTools::GET_PRODUCT_DETAILS => $this->singleProductFacts($toolName, $payload['product'] ?? null),
            CanonicalTools::GET_PRODUCT_VARIANTS => $this->variantsFacts($toolName, $payload),
            CanonicalTools::CHECK_PRODUCT_AVAILABILITY => $this->availabilityFacts($toolName, $payload),
            CanonicalTools::GET_CURRENT_PRICE => $this->priceFacts($toolName, $payload),
            CanonicalTools::GET_ORDER_STATUS => $this->orderStatusFacts($toolName, $payload),
            CanonicalTools::GET_PRODUCT_INTELLIGENCE => $this->intelligenceFacts($arguments, $payload),
            CanonicalTools::EVALUATE_PRODUCT_SUITABILITY => $this->evaluationFacts($arguments, $payload),
            CanonicalTools::SAVE_CUSTOMER_COMMERCE_CONTEXT => $this->savedContextFacts($payload),
            CanonicalTools::TRANSFER_TO_HUMAN_AGENT => $this->handoffFacts($payload),
            CanonicalTools::REQUEST_LOCATION_CAPTURE => $this->locationRequestFacts($payload),
            CanonicalTools::GET_BOOKING_OPTIONS => $this->bookingOptionsFacts($payload),
            CanonicalTools::CHECK_BOOKING_AVAILABILITY => $this->bookingAvailabilityFacts($payload),
            CanonicalTools::GET_CUSTOMER_BOOKINGS => $this->customerBookingsFacts($payload),
            CanonicalTools::CREATE_BOOKING,
            CanonicalTools::RESCHEDULE_BOOKING,
            CanonicalTools::UPDATE_BOOKING,
            CanonicalTools::CANCEL_BOOKING => $this->bookingMutationFacts($toolName, $payload),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function confirmedAction(string $toolName, AiRequest $request, array $payload): ?ConfirmedAction
    {
        if ($toolName === CanonicalTools::TRANSFER_TO_HUMAN_AGENT && ($payload['transferred'] ?? false) === true) {
            $reference = $request->conversationId !== null ? (string) $request->conversationId : null;

            return new ConfirmedAction(CanonicalTools::TRANSFER_TO_HUMAN_AGENT, $reference);
        }

        if ($toolName === CanonicalTools::CANCEL_CUSTOMER_ORDER && ($payload['cancelled'] ?? false) === true) {
            $reference = isset($payload['order_number']) && is_string($payload['order_number'])
                ? $payload['order_number']
                : ($request->conversationId !== null ? (string) $request->conversationId : null);

            return new ConfirmedAction(CanonicalTools::CANCEL_CUSTOMER_ORDER, $reference);
        }

        if ($toolName === CanonicalTools::UPDATE_CUSTOMER_ORDER && ($payload['updated'] ?? false) === true) {
            $reference = isset($payload['order_number']) && is_string($payload['order_number'])
                ? $payload['order_number']
                : ($request->conversationId !== null ? (string) $request->conversationId : null);

            return new ConfirmedAction(CanonicalTools::UPDATE_CUSTOMER_ORDER, $reference);
        }

        if ($toolName === CanonicalTools::REQUEST_LOCATION_CAPTURE && ($payload['requested'] ?? false) === true) {
            $reference = isset($payload['capture_url']) && is_string($payload['capture_url'])
                ? $payload['capture_url']
                : ($request->conversationId !== null ? (string) $request->conversationId : null);

            return new ConfirmedAction(CanonicalTools::REQUEST_LOCATION_CAPTURE, $reference);
        }

        if ($toolName === CanonicalTools::CREATE_BOOKING && ($payload['created'] ?? false) === true) {
            $reference = is_string($payload['booking_reference'] ?? null) ? $payload['booking_reference'] : null;

            return new ConfirmedAction(CanonicalTools::CREATE_BOOKING, $reference);
        }

        if ($toolName === CanonicalTools::RESCHEDULE_BOOKING && ($payload['rescheduled'] ?? false) === true) {
            $reference = is_string($payload['booking_reference'] ?? null) ? $payload['booking_reference'] : null;

            return new ConfirmedAction(CanonicalTools::RESCHEDULE_BOOKING, $reference);
        }

        if ($toolName === CanonicalTools::UPDATE_BOOKING && ($payload['updated'] ?? false) === true) {
            $reference = is_string($payload['booking_reference'] ?? null) ? $payload['booking_reference'] : null;

            return new ConfirmedAction(CanonicalTools::UPDATE_BOOKING, $reference);
        }

        if ($toolName === CanonicalTools::CANCEL_BOOKING && ($payload['cancelled'] ?? false) === true) {
            $reference = is_string($payload['booking_reference'] ?? null) ? $payload['booking_reference'] : null;

            return new ConfirmedAction(CanonicalTools::CANCEL_BOOKING, $reference);
        }

        if ($toolName !== CanonicalTools::SAVE_CUSTOMER_COMMERCE_CONTEXT) {
            return null;
        }
        if (! ($payload['saved'] ?? false)) {
            return null;
        }

        $reference = $request->conversationId !== null ? (string) $request->conversationId : null;

        return new ConfirmedAction(CanonicalTools::SAVE_CUSTOMER_COMMERCE_CONTEXT, $reference);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<FactRecord>
     */
    private function searchFacts(array $payload, string $source = CanonicalTools::SEARCH_PRODUCTS): array
    {
        $facts = [
            new FactRecord($source, 'count', (int) ($payload['count'] ?? 0)),
            new FactRecord(
                $source,
                'result_status',
                ($payload['count'] ?? 0) === 0 ? 'empty' : 'available',
            ),
        ];
        $products = is_array($payload['products'] ?? null) ? $payload['products'] : [];
        foreach (array_slice($products, 0, self::MAX_PRODUCT_FACTS) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = $row['catalog_item_id'] ?? $row['item_id'] ?? null;
            $prefix = is_numeric($id) ? 'catalog_item:'.(int) $id : 'product:'.$index;
            foreach (['name', 'price', 'base_price', 'sale_price', 'compare_at_price', 'currency', 'stock', 'sku'] as $field) {
                if (! array_key_exists($field, $row) || ! $this->isScalar($row[$field])) {
                    continue;
                }
                $facts[] = new FactRecord($source, $prefix.'.'.$field, $row[$field]);
            }
            $availability = CatalogAvailabilityFact::token($row['availability'] ?? null);
            if ($availability !== null) {
                $facts[] = new FactRecord($source, $prefix.'.availability', $availability);
            }
            $variants = is_array($row['variants'] ?? null) ? $row['variants'] : [];
            foreach (array_slice($variants, 0, self::MAX_PRODUCT_FACTS) as $variant) {
                if (! is_array($variant) || ! is_numeric($variant['id'] ?? null)) {
                    continue;
                }
                $variantPrefix = $prefix.'.variant:'.(int) $variant['id'];
                foreach (['name', 'price', 'base_price', 'sale_price', 'compare_at_price', 'currency', 'stock', 'sku'] as $field) {
                    if (! array_key_exists($field, $variant) || ! $this->isScalar($variant[$field])) {
                        continue;
                    }
                    $facts[] = new FactRecord($source, $variantPrefix.'.'.$field, $variant[$field]);
                }
                if (array_key_exists('matches_question', $variant)) {
                    $facts[] = new FactRecord(
                        CanonicalTools::SEARCH_PRODUCTS,
                        $variantPrefix.'.matches_question',
                        ($variant['matches_question'] === true || $variant['matches_question'] === 1) ? 1 : 0,
                    );
                }
                $variantAvailability = CatalogAvailabilityFact::token($variant['availability'] ?? null);
                if ($variantAvailability !== null) {
                    $facts[] = new FactRecord(CanonicalTools::SEARCH_PRODUCTS, $variantPrefix.'.availability', $variantAvailability);
                }
            }
            $variantMatchStatus = $row['variant_match_status'] ?? null;
            if (is_string($variantMatchStatus) && $variantMatchStatus !== '') {
                $facts[] = new FactRecord(
                    CanonicalTools::SEARCH_PRODUCTS,
                    $prefix.'.variant_match_status',
                    $variantMatchStatus,
                );
                if ($variantMatchStatus === 'not_found') {
                    $facts[] = new FactRecord(
                        CanonicalTools::SEARCH_PRODUCTS,
                        $prefix.'.requested_variant.status',
                        'not_found',
                    );
                }
                foreach (array_slice($variants, 0, self::MAX_PRODUCT_FACTS) as $variant) {
                    if (! is_array($variant) || ($variant['matches_question'] ?? false) !== true) {
                        continue;
                    }
                    $requestedAvailability = CatalogAvailabilityFact::token($variant['availability'] ?? null);
                    if ($requestedAvailability !== null) {
                        $facts[] = new FactRecord(
                            CanonicalTools::SEARCH_PRODUCTS,
                            $prefix.'.requested_variant.availability',
                            $requestedAvailability,
                        );
                    }
                    if ($this->isScalar($variant['stock'] ?? null)) {
                        $facts[] = new FactRecord(
                            CanonicalTools::SEARCH_PRODUCTS,
                            $prefix.'.requested_variant.stock',
                            $variant['stock'],
                        );
                    }
                }
            }
        }

        return $facts;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $payload
     * @return list<FactRecord>
     */
    private function intelligenceFacts(array $arguments, array $payload): array
    {
        $itemId = (int) ($arguments['item_id'] ?? $payload['item_id'] ?? 0);
        if ($itemId <= 0) {
            return [];
        }

        return [
            new FactRecord(CanonicalTools::GET_PRODUCT_INTELLIGENCE, 'catalog_item:'.$itemId.'.loaded', true),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $payload
     * @return list<FactRecord>
     */
    private function evaluationFacts(array $arguments, array $payload): array
    {
        $itemId = (int) ($payload['item_id'] ?? $arguments['item_id'] ?? 0);
        if ($itemId <= 0) {
            return [];
        }

        $evaluation = is_array($payload['evaluation'] ?? null) ? $payload['evaluation'] : [];
        $status = $evaluation['status'] ?? $evaluation['result'] ?? null;
        $facts = [
            new FactRecord(CanonicalTools::EVALUATE_PRODUCT_SUITABILITY, 'catalog_item:'.$itemId.'.evaluated', true),
        ];
        if ($this->isScalar($status)) {
            $facts[] = new FactRecord(CanonicalTools::EVALUATE_PRODUCT_SUITABILITY, 'catalog_item:'.$itemId.'.status', $status);
        }

        return $facts;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<FactRecord>
     */
    private function savedContextFacts(array $payload): array
    {
        $keys = is_array($payload['keys'] ?? null) ? $payload['keys'] : [];
        $facts = [
            new FactRecord(CanonicalTools::SAVE_CUSTOMER_COMMERCE_CONTEXT, 'saved', (bool) ($payload['saved'] ?? false)),
        ];
        foreach ($keys as $key) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }
            $facts[] = new FactRecord(CanonicalTools::SAVE_CUSTOMER_COMMERCE_CONTEXT, 'key.'.$key, true);
        }

        return $facts;
    }

    private function isScalar(mixed $value): bool
    {
        return is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null;
    }

    /**
     * @return list<FactRecord>
     */
    private function singleProductFacts(string $source, mixed $product): array
    {
        if (! is_array($product)) {
            return [new FactRecord($source, 'result_status', 'empty')];
        }

        return $this->searchFacts([
            'count' => 1,
            'products' => [$product],
        ], $source);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<FactRecord>
     */
    private function variantsFacts(string $source, array $payload): array
    {
        if (! ($payload['found'] ?? false)) {
            return [new FactRecord($source, 'result_status', 'empty')];
        }

        $product = array_filter([
            'catalog_item_id' => $payload['catalog_item_id'] ?? null,
            'name' => $payload['product_name'] ?? null,
            'variants' => $payload['variants'] ?? [],
        ], static fn (mixed $v): bool => $v !== null);

        return $this->searchFacts([
            'count' => 1,
            'products' => [$product],
        ], $source);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<FactRecord>
     */
    private function availabilityFacts(string $source, array $payload): array
    {
        if (! ($payload['found'] ?? false)) {
            return [new FactRecord($source, 'result_status', 'empty')];
        }

        $id = $payload['catalog_item_id'] ?? 0;
        $prefix = is_numeric($id) && (int) $id > 0 ? 'catalog_item:'.(int) $id : 'product:0';
        $facts = [];
        foreach (['product_name' => 'name', 'availability' => 'availability', 'stock' => 'stock'] as $srcKey => $factField) {
            if (isset($payload[$srcKey]) && $this->isScalar($payload[$srcKey])) {
                $facts[] = new FactRecord($source, $prefix.'.'.$factField, $payload[$srcKey]);
            }
        }
        if (isset($payload['variant_id']) && is_numeric($payload['variant_id'])) {
            $vPrefix = $prefix.'.variant:'.(int) $payload['variant_id'];
            foreach (['variant_name' => 'name', 'availability' => 'availability', 'stock' => 'stock'] as $srcKey => $factField) {
                if (isset($payload[$srcKey]) && $this->isScalar($payload[$srcKey])) {
                    $facts[] = new FactRecord($source, $vPrefix.'.'.$factField, $payload[$srcKey]);
                }
            }
        }

        return $facts;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<FactRecord>
     */
    private function priceFacts(string $source, array $payload): array
    {
        if (! ($payload['found'] ?? false)) {
            return [new FactRecord($source, 'result_status', 'empty')];
        }

        $id = $payload['catalog_item_id'] ?? 0;
        $prefix = is_numeric($id) && (int) $id > 0 ? 'catalog_item:'.(int) $id : 'product:0';
        $facts = [];
        if (isset($payload['product_name']) && is_string($payload['product_name'])) {
            $facts[] = new FactRecord($source, $prefix.'.name', $payload['product_name']);
        }
        foreach (['price', 'base_price', 'sale_price', 'compare_at_price', 'currency'] as $field) {
            if (isset($payload[$field]) && $this->isScalar($payload[$field])) {
                $facts[] = new FactRecord($source, $prefix.'.'.$field, $payload[$field]);
            }
        }

        return $facts;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<FactRecord>
     */
    private function orderStatusFacts(string $source, array $payload): array
    {
        $orders = is_array($payload['orders'] ?? null) ? $payload['orders'] : [];
        $facts = [new FactRecord($source, 'count', count($orders))];
        foreach ($orders as $index => $order) {
            if (! is_array($order)) {
                continue;
            }
            $prefix = 'order:'.$index;
            foreach (['order_number', 'status', 'shipping_status', 'total_amount', 'currency'] as $field) {
                if (isset($order[$field]) && $this->isScalar($order[$field])) {
                    $facts[] = new FactRecord($source, $prefix.'.'.$field, $order[$field]);
                }
            }
        }

        return $facts;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<FactRecord>
     */
    private function handoffFacts(array $payload): array
    {
        return [
            new FactRecord(CanonicalTools::TRANSFER_TO_HUMAN_AGENT, 'transferred', (bool) ($payload['transferred'] ?? false)),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<FactRecord>
     */
    private function locationRequestFacts(array $payload): array
    {
        $facts = [
            new FactRecord(CanonicalTools::REQUEST_LOCATION_CAPTURE, 'requested', (bool) ($payload['requested'] ?? false)),
        ];
        if (isset($payload['capture_url']) && is_string($payload['capture_url']) && $payload['capture_url'] !== '') {
            $facts[] = new FactRecord(CanonicalTools::REQUEST_LOCATION_CAPTURE, 'capture_url', $payload['capture_url']);
        }
        if (isset($payload['platform']) && is_string($payload['platform'])) {
            $facts[] = new FactRecord(CanonicalTools::REQUEST_LOCATION_CAPTURE, 'platform', $payload['platform']);
        }

        return $facts;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<FactRecord>
     */
    private function bookingOptionsFacts(array $payload): array
    {
        $services = is_array($payload['services'] ?? null) ? $payload['services'] : [];
        $facts = [
            new FactRecord(CanonicalTools::GET_BOOKING_OPTIONS, 'booking_enabled', (bool) ($payload['booking_enabled'] ?? false)),
            new FactRecord(CanonicalTools::GET_BOOKING_OPTIONS, 'result_status', $services !== [] ? 'available' : 'empty'),
        ];

        foreach (array_slice($services, 0, self::MAX_PRODUCT_FACTS) as $index => $service) {
            if (! is_array($service)) {
                continue;
            }
            $prefix = is_numeric($service['id'] ?? null) ? 'booking_service:'.(int) $service['id'] : 'booking_service:'.$index;
            if (is_string($service['name'] ?? null) && trim($service['name']) !== '') {
                $facts[] = new FactRecord(CanonicalTools::GET_BOOKING_OPTIONS, $prefix.'.name', $service['name']);
            }
            if (isset($service['price']) && is_numeric($service['price'])) {
                $facts[] = new FactRecord(CanonicalTools::GET_BOOKING_OPTIONS, $prefix.'.price', $service['price']);
            }
            if (is_string($service['currency'] ?? null) && $service['currency'] !== '') {
                $facts[] = new FactRecord(CanonicalTools::GET_BOOKING_OPTIONS, $prefix.'.currency', $service['currency']);
            }
        }

        return $facts;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<FactRecord>
     */
    private function bookingAvailabilityFacts(array $payload): array
    {
        $slots = is_array($payload['available_slots'] ?? null) ? $payload['available_slots'] : [];
        $facts = [
            new FactRecord(CanonicalTools::CHECK_BOOKING_AVAILABILITY, 'available_count', count($slots)),
            new FactRecord(CanonicalTools::CHECK_BOOKING_AVAILABILITY, 'result_status', $slots !== [] ? 'available' : 'empty'),
        ];

        foreach (array_slice($slots, 0, self::MAX_PRODUCT_FACTS) as $index => $slot) {
            if (! is_array($slot)) {
                continue;
            }
            $label = trim((string) ($slot['date'] ?? '').' '.(string) ($slot['start_time'] ?? ''));
            if ($label !== '') {
                $facts[] = new FactRecord(CanonicalTools::CHECK_BOOKING_AVAILABILITY, 'booking_slot:'.$index, $label);
            }
        }

        return $facts;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<FactRecord>
     */
    private function customerBookingsFacts(array $payload): array
    {
        $rows = is_array($payload['bookings'] ?? null) ? $payload['bookings'] : [];
        $facts = [new FactRecord(CanonicalTools::GET_CUSTOMER_BOOKINGS, 'count', count($rows))];
        foreach (array_slice($rows, 0, self::MAX_PRODUCT_FACTS) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $prefix = 'customer_booking:'.$index;
            foreach (['booking_reference', 'status', 'booking_date', 'start_time', 'service_name'] as $field) {
                if (isset($row[$field]) && $this->isScalar($row[$field])) {
                    $facts[] = new FactRecord(CanonicalTools::GET_CUSTOMER_BOOKINGS, $prefix.'.'.$field, $row[$field]);
                }
            }
        }

        return $facts;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<FactRecord>
     */
    private function bookingMutationFacts(string $toolName, array $payload): array
    {
        $facts = [];
        if (is_string($payload['booking_reference'] ?? null) && $payload['booking_reference'] !== '') {
            $facts[] = new FactRecord($toolName, 'booking_reference', $payload['booking_reference']);
        }
        $booking = is_array($payload['booking'] ?? null) ? $payload['booking'] : null;
        if ($booking !== null) {
            foreach (['service_name', 'booking_date', 'start_time', 'status', 'price', 'currency'] as $field) {
                if (isset($booking[$field]) && $this->isScalar($booking[$field]) && $booking[$field] !== '') {
                    $facts[] = new FactRecord($toolName, 'booking.'.$field, $booking[$field]);
                }
            }
        }

        return $facts;
    }
}
