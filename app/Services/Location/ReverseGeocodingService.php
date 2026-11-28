<?php

declare(strict_types=1);

namespace App\Services\Location;

use App\Services\Location\Data\GeocodingResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ReverseGeocodingService
{
    public function reverse(float $latitude, float $longitude, ?int $locationId = null): GeocodingResult
    {
        if (! (bool) config('location.geocoding.enabled', true)) {
            return GeocodingResult::skipped('disabled');
        }

        $driver = strtolower(trim((string) config('location.geocoding.driver', 'nominatim')));

        return match ($driver) {
            'none', 'null', 'disabled' => GeocodingResult::skipped($driver),
            'google' => $this->reverseWithGoogle($latitude, $longitude, $locationId),
            default => $this->reverseWithNominatim($latitude, $longitude, $locationId),
        };
    }

    /**
     * Forward place/neighborhood search for the web capture UI (rate-limited proxy).
     *
     * @return list<array{display_name:string,latitude:float,longitude:float,city:?string,district:?string}>
     */
    public function searchPlaces(string $query, ?string $countryCode = null): array
    {
        $cleanQuery = trim(strip_tags($query));
        if (mb_strlen($cleanQuery) < 2 || ! (bool) config('location.geocoding.enabled', true)) {
            return [];
        }

        $driver = strtolower(trim((string) config('location.geocoding.driver', 'nominatim')));
        if (in_array($driver, ['none', 'null', 'disabled'], true)) {
            return [];
        }

        $timeout = max(2, min(10, (int) config('location.geocoding.timeout_seconds', 6)));
        $baseUrl = rtrim((string) config('location.geocoding.nominatim_base_url', 'https://nominatim.openstreetmap.org'), '/');
        $userAgent = (string) config('location.geocoding.nominatim_user_agent', 'HASEM/1.0');
        $email = config('location.geocoding.nominatim_email');
        $acceptLang = (string) config('location.geocoding.accept_language', 'ar,en');

        try {
            $params = array_filter([
                'q' => $cleanQuery,
                'format' => 'jsonv2',
                'addressdetails' => 1,
                'limit' => 5,
                'accept-language' => $acceptLang,
                'countrycodes' => is_string($countryCode) && $countryCode !== '' ? strtolower($countryCode) : null,
                'email' => is_string($email) && $email !== '' ? $email : null,
            ], static fn (mixed $v): bool => $v !== null);

            $response = Http::acceptJson()
                ->withHeaders(['User-Agent' => $userAgent])
                ->timeout($timeout)
                ->get($baseUrl.'/search', $params);

            if (! $response->successful()) {
                return [];
            }

            $rows = $response->json();
            if (! is_array($rows)) {
                return [];
            }

            $results = [];
            foreach (array_slice($rows, 0, 5) as $row) {
                if (! is_array($row) || ! is_numeric($row['lat'] ?? null) || ! is_numeric($row['lon'] ?? null)) {
                    continue;
                }
                $addr = is_array($row['address'] ?? null) ? $row['address'] : [];
                $results[] = [
                    'display_name' => mb_substr((string) ($row['display_name'] ?? $cleanQuery), 0, 260),
                    'latitude' => round((float) $row['lat'], 7),
                    'longitude' => round((float) $row['lon'], 7),
                    'city' => $this->firstNonEmpty($addr, ['city', 'town', 'village', 'municipality', 'state']),
                    'district' => $this->firstNonEmpty($addr, ['suburb', 'neighbourhood', 'city_district', 'quarter', 'district']),
                ];
            }

            return $results;
        } catch (Throwable) {
            return [];
        }
    }

    private function reverseWithNominatim(float $latitude, float $longitude, ?int $locationId = null): GeocodingResult
    {
        $timeout = max(2, min(10, (int) config('location.geocoding.timeout_seconds', 6)));
        $baseUrl = rtrim((string) config('location.geocoding.nominatim_base_url', 'https://nominatim.openstreetmap.org'), '/');
        $userAgent = (string) config('location.geocoding.nominatim_user_agent', 'HASEM/1.0');
        $email = config('location.geocoding.nominatim_email');
        $acceptLang = (string) config('location.geocoding.accept_language', 'ar,en');

        try {
            $params = array_filter([
                'lat' => number_format($latitude, 7, '.', ''),
                'lon' => number_format($longitude, 7, '.', ''),
                'format' => 'jsonv2',
                'addressdetails' => 1,
                'zoom' => 18,
                'accept-language' => $acceptLang,
                'email' => is_string($email) && $email !== '' ? $email : null,
            ], static fn (mixed $v): bool => $v !== null);

            $response = Http::acceptJson()
                ->withHeaders(['User-Agent' => $userAgent])
                ->timeout($timeout)
                ->get($baseUrl.'/reverse', $params);

            if (! $response->successful()) {
                Log::info('location.reverse_geocode.http_failed', [
                    'provider' => 'nominatim',
                    'location_id' => $locationId,
                    'http_status' => $response->status(),
                ]);

                return GeocodingResult::failed('nominatim');
            }

            $data = $response->json();
            if (! is_array($data) || isset($data['error'])) {
                return GeocodingResult::failed('nominatim');
            }

            $addr = is_array($data['address'] ?? null) ? $data['address'] : [];
            $city = $this->firstNonEmpty($addr, ['city', 'town', 'village', 'municipality', 'state_district', 'state']);
            $district = $this->firstNonEmpty($addr, ['suburb', 'neighbourhood', 'city_district', 'quarter', 'district', 'residential']);
            $street = $this->firstNonEmpty($addr, ['road', 'pedestrian', 'footway', 'street', 'path']);
            $house = $this->firstNonEmpty($addr, ['house_number', 'building']);
            if ($street !== null && $house !== null) {
                $street = $street.' '.$house;
            }

            $country = $this->firstNonEmpty($addr, ['country']);
            $countryCode = $this->firstNonEmpty($addr, ['country_code']);
            $postalCode = $this->firstNonEmpty($addr, ['postcode']);
            $placeName = $this->cleanText($data['name'] ?? null, 180);
            $displayName = $this->cleanText($data['display_name'] ?? null, 500);

            $formattedParts = array_values(array_filter(array_unique([
                $city,
                $district,
                $street,
            ])));
            $formatted = $formattedParts !== []
                ? implode('، ', $formattedParts)
                : $displayName;

            if ($formatted === null && $city === null && $district === null && $street === null) {
                return GeocodingResult::failed('nominatim');
            }

            return new GeocodingResult(
                status: 'resolved',
                provider: 'nominatim',
                countryCode: $countryCode !== null ? strtoupper($countryCode) : null,
                country: $country,
                city: $city,
                district: $district,
                street: $street,
                postalCode: $postalCode,
                placeName: $placeName,
                formattedAddress: $formatted,
            );
        } catch (Throwable $exception) {
            Log::info('location.reverse_geocode.exception', [
                'provider' => 'nominatim',
                'location_id' => $locationId,
                'reason' => mb_substr($exception->getMessage(), 0, 120),
            ]);

            return GeocodingResult::failed('nominatim');
        }
    }

    private function reverseWithGoogle(float $latitude, float $longitude, ?int $locationId = null): GeocodingResult
    {
        $apiKey = (string) config('location.geocoding.google_maps_api_key', '');
        if ($apiKey === '') {
            return $this->reverseWithNominatim($latitude, $longitude, $locationId);
        }

        $timeout = max(2, min(10, (int) config('location.geocoding.timeout_seconds', 6)));
        $language = explode(',', (string) config('location.geocoding.accept_language', 'ar'))[0] ?: 'ar';

        try {
            $response = Http::acceptJson()
                ->timeout($timeout)
                ->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'latlng' => number_format($latitude, 7, '.', '').','.number_format($longitude, 7, '.', ''),
                    'language' => trim($language),
                    'key' => $apiKey,
                ]);

            if (! $response->successful()) {
                return GeocodingResult::failed('google');
            }

            $data = $response->json();
            if (! is_array($data) || ($data['status'] ?? null) !== 'OK' || empty($data['results'][0])) {
                return GeocodingResult::failed('google');
            }

            $top = $data['results'][0];
            $components = is_array($top['address_components'] ?? null) ? $top['address_components'] : [];
            $city = $this->googleComponent($components, ['locality', 'administrative_area_level_2', 'administrative_area_level_1']);
            $district = $this->googleComponent($components, ['sublocality_level_1', 'sublocality', 'neighborhood']);
            $street = $this->googleComponent($components, ['route']);
            $country = $this->googleComponent($components, ['country']);
            $countryCode = $this->googleComponent($components, ['country'], shortName: true);
            $postalCode = $this->googleComponent($components, ['postal_code']);
            $formatted = $this->cleanText($top['formatted_address'] ?? null, 500);

            return new GeocodingResult(
                status: 'resolved',
                provider: 'google',
                countryCode: $countryCode !== null ? strtoupper($countryCode) : null,
                country: $country,
                city: $city,
                district: $district,
                street: $street,
                postalCode: $postalCode,
                formattedAddress: $formatted,
            );
        } catch (Throwable $exception) {
            Log::info('location.reverse_geocode.exception', [
                'provider' => 'google',
                'location_id' => $locationId,
                'reason' => mb_substr($exception->getMessage(), 0, 120),
            ]);

            return GeocodingResult::failed('google');
        }
    }

    /**
     * @param  array<string, mixed>  $address
     * @param  list<string>  $keys
     */
    private function firstNonEmpty(array $address, array $keys): ?string
    {
        foreach ($keys as $key) {
            $clean = $this->cleanText($address[$key] ?? null, 180);
            if ($clean !== null) {
                return $clean;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @param  list<string>  $types
     */
    private function googleComponent(array $components, array $types, bool $shortName = false): ?string
    {
        foreach ($components as $component) {
            if (! is_array($component) || ! is_array($component['types'] ?? null)) {
                continue;
            }
            if (array_intersect($types, $component['types']) !== []) {
                $value = $shortName ? ($component['short_name'] ?? null) : ($component['long_name'] ?? null);

                return $this->cleanText($value, 180);
            }
        }

        return null;
    }

    private function cleanText(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = trim($value);

        return $clean !== '' ? mb_substr($clean, 0, $max) : null;
    }
}
