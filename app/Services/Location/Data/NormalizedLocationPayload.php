<?php

declare(strict_types=1);

namespace App\Services\Location\Data;

use InvalidArgumentException;

final class NormalizedLocationPayload
{
    /**
     * @param  array<string, mixed>  $safeMetadata
     */
    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly ?int $accuracyMeters,
        public readonly string $source,
        public readonly string $platform,
        public readonly int $conversationId,
        public readonly ?int $customerId = null,
        public readonly ?int $orderId = null,
        public readonly ?string $externalMessageId = null,
        public readonly ?string $placeName = null,
        public readonly ?string $providerAddress = null,
        public readonly ?string $customerNotes = null,
        public readonly ?string $verificationStatus = null,
        public readonly array $safeMetadata = [],
    ) {
        self::assertValidCoordinates($latitude, $longitude);

        if ($accuracyMeters !== null && ($accuracyMeters < 0 || $accuracyMeters > 100000)) {
            throw new InvalidArgumentException('Location accuracy is out of supported bounds.');
        }

        if ($conversationId <= 0) {
            throw new InvalidArgumentException('A valid conversation_id is required.');
        }
    }

    public static function assertValidCoordinates(float $latitude, float $longitude): void
    {
        if (! is_finite($latitude) || ! is_finite($longitude)) {
            throw new InvalidArgumentException('Coordinates must be finite numbers.');
        }

        if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
            throw new InvalidArgumentException('Coordinates are outside valid geographic bounds.');
        }

        if (abs($latitude) < 0.00001 && abs($longitude) < 0.00001) {
            throw new InvalidArgumentException('Null Island (0, 0) coordinates are rejected.');
        }
    }

    public function googleMapsUrl(): string
    {
        return sprintf(
            'https://www.google.com/maps?q=%s,%s',
            number_format($this->latitude, 7, '.', ''),
            number_format($this->longitude, 7, '.', ''),
        );
    }

    /**
     * @return array{
     *     latitude: float,
     *     longitude: float,
     *     accuracy: int|null,
     *     source: string,
     *     platform: string,
     *     conversation_id: int,
     *     customer_id: int|null,
     *     order_id: int|null
     * }
     */
    public function toArray(): array
    {
        return [
            'latitude' => round($this->latitude, 7),
            'longitude' => round($this->longitude, 7),
            'accuracy' => $this->accuracyMeters,
            'source' => $this->source,
            'platform' => $this->platform,
            'conversation_id' => $this->conversationId,
            'customer_id' => $this->customerId,
            'order_id' => $this->orderId,
        ];
    }
}
