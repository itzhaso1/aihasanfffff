<?php

declare(strict_types=1);

namespace Tests\Unit\Location;

use App\Models\LocationCaptureSession;
use App\Services\Location\Data\NormalizedLocationPayload;
use App\Services\Location\LocationPayloadNormalizer;
use App\Services\Location\ReverseGeocodingService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class LocationPayloadNormalizerAndReverseGeocoderTest extends TestCase
{
    public function test_normalized_payload_validates_coordinate_bounds(): void
    {
        $valid = new NormalizedLocationPayload(
            latitude: 24.7136123,
            longitude: 46.6753456,
            accuracyMeters: 15,
            source: 'gps',
            platform: 'whatsapp',
            conversationId: 10,
            customerId: 20,
            orderId: 30,
        );

        $this->assertSame([
            'latitude' => 24.7136123,
            'longitude' => 46.6753456,
            'accuracy' => 15,
            'source' => 'gps',
            'platform' => 'whatsapp',
            'conversation_id' => 10,
            'customer_id' => 20,
            'order_id' => 30,
        ], $valid->toArray());
        $this->assertStringContainsString('24.7136123,46.6753456', $valid->googleMapsUrl());

        $this->expectException(InvalidArgumentException::class);
        new NormalizedLocationPayload(
            latitude: 95.0,
            longitude: 46.6,
            accuracyMeters: 10,
            source: 'gps',
            platform: 'whatsapp',
            conversationId: 10,
        );
    }

    public function test_normalized_payload_rejects_null_island_coordinates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NormalizedLocationPayload::assertValidCoordinates(0.0, 0.0);
    }

    public function test_normalizes_whatsapp_telegram_messenger_instagram_and_web_payloads(): void
    {
        $normalizer = new LocationPayloadNormalizer;

        $wa = $normalizer->fromWhatsApp([
            'id' => 'wamid.loc1',
            'type' => 'location',
            'location' => [
                'latitude' => 24.774265,
                'longitude' => 46.738586,
                'name' => 'حي العليا',
                'address' => 'طريق الملك فهد، الرياض',
            ],
        ], conversationId: 1, customerId: 2);

        $this->assertNotNull($wa);
        $this->assertSame('whatsapp_location', $wa->source);
        $this->assertSame('whatsapp', $wa->platform);
        $this->assertSame(24.774265, $wa->latitude);

        $tgVenue = $normalizer->fromTelegram([
            'message_id' => 'tg-1',
            'venue' => [
                'location' => ['latitude' => 21.4858, 'longitude' => 39.1925, 'horizontal_accuracy' => 18.4],
                'title' => 'برج جدة',
                'address' => 'أبحر، جدة',
            ],
        ], conversationId: 3);

        $this->assertNotNull($tgVenue);
        $this->assertSame('telegram_venue', $tgVenue->source);
        $this->assertSame(18, $tgVenue->accuracyMeters);
        $this->assertSame('برج جدة', $tgVenue->placeName);

        $fb = $normalizer->fromMetaAttachments('facebook_messenger', [[
            'type' => 'location',
            'payload' => ['coordinates' => ['lat' => 24.71, 'long' => 46.67]],
        ]], conversationId: 4);

        $this->assertNotNull($fb);
        $this->assertSame('facebook_location', $fb->source);
        $this->assertSame('facebook_messenger', $fb->platform);

        $ig = $normalizer->fromMetaAttachments('instagram', [[
            'type' => 'location',
            'payload' => ['coordinates' => ['latitude' => 25.2048, 'longitude' => 55.2708]],
        ]], conversationId: 5);

        $this->assertNotNull($ig);
        $this->assertSame('instagram_location', $ig->source);
        $this->assertSame('instagram', $ig->platform);

        $session = new LocationCaptureSession([
            'conversation_id' => 6,
            'customer_id' => 7,
            'order_id' => 8,
            'platform' => 'instagram',
        ]);
        $web = $normalizer->fromWebSubmission([
            'latitude' => 24.7136,
            'longitude' => 46.6753,
            'accuracy_meters' => 12,
            'source' => 'gps_adjusted',
            'customer_notes' => 'الدور الثاني شقة 4',
        ], $session);

        $this->assertSame('gps_adjusted', $web->source);
        $this->assertSame('instagram', $web->platform);
        $this->assertSame('الدور الثاني شقة 4', $web->customerNotes);
    }

    public function test_reverse_geocoding_extracts_address_and_fails_gracefully_on_network_error(): void
    {
        config([
            'location.geocoding.enabled' => true,
            'location.geocoding.driver' => 'nominatim',
            'location.geocoding.nominatim_base_url' => 'https://nominatim.openstreetmap.org',
        ]);

        Http::fake([
            'nominatim.openstreetmap.org/reverse*' => Http::response([
                'name' => 'مركز الملك عبدالله',
                'display_name' => 'حي العقيق، الرياض، السعودية',
                'address' => [
                    'country_code' => 'sa',
                    'country' => 'المملكة العربية السعودية',
                    'city' => 'الرياض',
                    'suburb' => 'حي العقيق',
                    'road' => 'طريق الثمامة',
                    'postcode' => '13515',
                ],
            ], 200),
        ]);

        $geocoder = new ReverseGeocodingService;
        $ok = $geocoder->reverse(24.7654, 46.6401, 1);

        $this->assertTrue($ok->resolved());
        $this->assertSame('الرياض', $ok->city);
        $this->assertSame('حي العقيق', $ok->district);
        $this->assertSame('طريق الثمامة', $ok->street);
        $this->assertSame('SA', $ok->countryCode);
        $this->assertSame('الرياض، حي العقيق، طريق الثمامة', $ok->formattedAddress);

        Http::fake(fn () => throw new ConnectionException('Timeout'));
        $failed = $geocoder->reverse(24.7654, 46.6401, 2);

        $this->assertFalse($failed->resolved());
        $this->assertSame('failed', $failed->status);
    }
}
