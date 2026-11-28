<?php

declare(strict_types=1);

namespace App\Services\Location;

use App\Models\LocationCaptureSession;
use App\Services\Location\Data\NormalizedLocationPayload;
use InvalidArgumentException;

final class LocationPayloadNormalizer
{
    /**
     * @param  array<string, mixed>  $messageData
     */
    public function fromWhatsApp(
        array $messageData,
        int $conversationId,
        ?int $customerId = null,
        ?int $orderId = null,
    ): ?NormalizedLocationPayload {
        $type = strtolower(trim((string) ($messageData['type'] ?? '')));
        $location = $messageData['location'] ?? null;
        if ($type !== 'location' || ! is_array($location)) {
            return null;
        }

        $coords = $this->extractLatLng($location['latitude'] ?? null, $location['longitude'] ?? null);
        if ($coords === null) {
            return null;
        }

        $name = $this->cleanString($location['name'] ?? null, 180);
        $address = $this->cleanString($location['address'] ?? null, 500);
        $url = $this->cleanString($location['url'] ?? null, 500);
        $accuracy = $this->extractAccuracy($location['accuracy'] ?? null);

        return new NormalizedLocationPayload(
            latitude: $coords[0],
            longitude: $coords[1],
            accuracyMeters: $accuracy,
            source: 'whatsapp_location',
            platform: 'whatsapp',
            conversationId: $conversationId,
            customerId: $customerId,
            orderId: $orderId,
            externalMessageId: $this->cleanString($messageData['id'] ?? null, 191),
            placeName: $name,
            providerAddress: $address,
            safeMetadata: array_filter([
                'provider_location_name' => $name,
                'provider_location_address' => $address,
                'provider_map_url' => $url,
            ], static fn (mixed $v): bool => $v !== null),
        );
    }

    /**
     * @param  array<string, mixed>  $messageData
     */
    public function fromTelegram(
        array $messageData,
        int $conversationId,
        ?int $customerId = null,
        ?int $orderId = null,
        ?string $externalMessageId = null,
    ): ?NormalizedLocationPayload {
        $venue = is_array($messageData['venue'] ?? null) ? $messageData['venue'] : null;
        $location = $venue !== null && is_array($venue['location'] ?? null)
            ? $venue['location']
            : (is_array($messageData['location'] ?? null) ? $messageData['location'] : null);

        if ($location === null) {
            // Also check normalized attachment descriptor stored from TelegramInboundUpdate.
            $attachments = is_array($messageData['attachments'] ?? null) ? $messageData['attachments'] : [];
            foreach ($attachments as $attachment) {
                if (is_array($attachment) && ($attachment['kind'] ?? null) === 'location') {
                    $location = $attachment;
                    break;
                }
            }
        }

        if ($location === null) {
            return null;
        }

        $coords = $this->extractLatLng($location['latitude'] ?? null, $location['longitude'] ?? null);
        if ($coords === null) {
            return null;
        }

        $accuracy = $this->extractAccuracy($location['horizontal_accuracy'] ?? $location['accuracy'] ?? null);
        $title = $this->cleanString($venue['title'] ?? $location['title'] ?? null, 180);
        $address = $this->cleanString($venue['address'] ?? $location['address'] ?? null, 500);
        $livePeriod = is_numeric($location['live_period'] ?? null) ? (int) $location['live_period'] : null;

        $isVenue = $venue !== null || ($location['source'] ?? null) === 'telegram_venue' || ($title !== null || $address !== null);

        return new NormalizedLocationPayload(
            latitude: $coords[0],
            longitude: $coords[1],
            accuracyMeters: $accuracy,
            source: $isVenue ? 'telegram_venue' : 'telegram_location',
            platform: 'telegram',
            conversationId: $conversationId,
            customerId: $customerId,
            orderId: $orderId,
            externalMessageId: $externalMessageId ?? $this->cleanString($messageData['message_id'] ?? null, 191),
            placeName: $title,
            providerAddress: $address,
            safeMetadata: array_filter([
                'live_period' => $livePeriod,
                'provider_location_name' => $title,
                'provider_location_address' => $address,
            ], static fn (mixed $v): bool => $v !== null),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $attachments
     */
    public function fromMetaAttachments(
        string $platform,
        array $attachments,
        int $conversationId,
        ?int $customerId = null,
        ?int $orderId = null,
        ?string $externalMessageId = null,
    ): ?NormalizedLocationPayload {
        $normalizedPlatform = $platform === 'instagram' ? 'instagram' : 'facebook_messenger';
        $source = $normalizedPlatform === 'instagram' ? 'instagram_location' : 'facebook_location';

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $type = strtolower(trim((string) ($attachment['type'] ?? '')));
            $coords = $this->extractCoordsFromMetaAttachment($attachment);
            if ($coords === null && $type !== 'location') {
                continue;
            }

            if ($coords === null) {
                continue;
            }

            $title = $this->cleanString($attachment['title'] ?? $attachment['name'] ?? null, 180);
            $address = $this->cleanString($attachment['address'] ?? null, 500);

            return new NormalizedLocationPayload(
                latitude: $coords[0],
                longitude: $coords[1],
                accuracyMeters: $this->extractAccuracy($attachment['accuracy'] ?? null),
                source: $source,
                platform: $normalizedPlatform,
                conversationId: $conversationId,
                customerId: $customerId,
                orderId: $orderId,
                externalMessageId: $externalMessageId,
                placeName: $title,
                providerAddress: $address,
                safeMetadata: array_filter([
                    'attachment_type' => $type !== '' ? $type : 'location',
                    'provider_location_name' => $title,
                    'provider_location_address' => $address,
                ], static fn (mixed $v): bool => $v !== null),
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function fromWebSubmission(array $input, LocationCaptureSession $session): NormalizedLocationPayload
    {
        $coords = $this->extractLatLng($input['latitude'] ?? null, $input['longitude'] ?? null);
        if ($coords === null) {
            throw new InvalidArgumentException('Valid latitude and longitude coordinates are required.');
        }

        $source = strtolower(trim((string) ($input['source'] ?? 'manual_pin')));
        if (! in_array($source, ['gps', 'manual_pin', 'gps_adjusted', 'search_pin'], true)) {
            $source = 'manual_pin';
        }

        return new NormalizedLocationPayload(
            latitude: $coords[0],
            longitude: $coords[1],
            accuracyMeters: $this->extractAccuracy($input['accuracy_meters'] ?? $input['accuracy'] ?? null),
            source: $source,
            platform: (string) ($session->platform ?: 'web'),
            conversationId: (int) $session->conversation_id,
            customerId: $session->customer_id !== null ? (int) $session->customer_id : null,
            orderId: $session->order_id !== null ? (int) $session->order_id : null,
            placeName: $this->cleanString($input['place_name'] ?? null, 180),
            providerAddress: $this->cleanString($input['formatted_address'] ?? null, 500),
            customerNotes: $this->cleanString($input['customer_notes'] ?? null, 500),
            verificationStatus: 'confirmed',
            safeMetadata: [
                'capture_channel' => 'web_link',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $attachment
     * @return array{0: float, 1: float}|null
     */
    private function extractCoordsFromMetaAttachment(array $attachment): ?array
    {
        $direct = $this->extractLatLng(
            $attachment['latitude'] ?? $attachment['lat'] ?? data_get($attachment, 'payload.coordinates.lat') ?? data_get($attachment, 'payload.coordinates.latitude'),
            $attachment['longitude'] ?? $attachment['long'] ?? $attachment['lng'] ?? data_get($attachment, 'payload.coordinates.long') ?? data_get($attachment, 'payload.coordinates.lng') ?? data_get($attachment, 'payload.coordinates.longitude'),
        );
        if ($direct !== null) {
            return $direct;
        }

        $url = $attachment['url'] ?? data_get($attachment, 'payload.url');
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $parsed = parse_url($url);
        if (! is_array($parsed)) {
            return null;
        }

        $queryParams = [];
        if (isset($parsed['query']) && is_string($parsed['query'])) {
            parse_str($parsed['query'], $queryParams);
        }

        foreach (['where1', 'q', 'll', 'query', 'center', 'destination'] as $paramKey) {
            $candidate = $queryParams[$paramKey] ?? null;
            if (! is_string($candidate)) {
                continue;
            }
            if (preg_match('/(-?\d{1,2}\.\d{3,})\s*,\s*(-?\d{1,3}\.\d{3,})/', $candidate, $matches) === 1) {
                $coords = $this->extractLatLng($matches[1], $matches[2]);
                if ($coords !== null) {
                    return $coords;
                }
            }
        }

        return null;
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    private function extractLatLng(mixed $lat, mixed $lng): ?array
    {
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        $latitude = (float) $lat;
        $longitude = (float) $lng;

        try {
            NormalizedLocationPayload::assertValidCoordinates($latitude, $longitude);
        } catch (InvalidArgumentException) {
            return null;
        }

        return [$latitude, $longitude];
    }

    private function extractAccuracy(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $rounded = (int) round((float) $value);
        $max = (int) config('location.max_accuracy_meters', 50000);
        if ($rounded < 0 || $rounded > $max) {
            return null;
        }

        return $rounded;
    }

    private function cleanString(mixed $value, int $maxLength): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim(strip_tags((string) $value));
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, $maxLength);
    }
}
