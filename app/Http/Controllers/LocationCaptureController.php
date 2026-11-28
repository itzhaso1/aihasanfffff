<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Location\Exceptions\LocationCaptureException;
use App\Services\Location\LocationCaptureService;
use App\Services\Location\ReverseGeocodingService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

class LocationCaptureController extends Controller
{
    public function __construct(
        private readonly LocationCaptureService $captureService,
        private readonly ReverseGeocodingService $geocoder,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function show(string $token): Response
    {
        try {
            $session = $this->captureService->resolveSessionByPlainToken($token, trackOpen: true);
        } catch (LocationCaptureException $exception) {
            return response()->view('location.capture', [
                'token' => $token,
                'state' => $exception->errorCode,
                'errorMessage' => $exception->getMessage(),
                'session' => null,
                'existingLocation' => null,
                'configPayload' => null,
            ], $exception->statusCode);
        }

        return $this->workspaceContext->runForId((int) $session->workspace_id, function () use ($session, $token): Response {
            $workspace = $session->workspace;
            $existingLocation = $session->customerLocation;

            if ($session->isLocked()) {
                return response()->view('location.capture', [
                    'token' => $token,
                    'state' => 'locked',
                    'errorMessage' => null,
                    'session' => $session,
                    'workspaceName' => $workspace?->name ?? 'HASEM',
                    'existingLocation' => $existingLocation?->toSummaryArray(),
                    'configPayload' => null,
                ], 200);
            }

            if ($session->status === 'revoked') {
                return response()->view('location.capture', [
                    'token' => $token,
                    'state' => 'session_revoked',
                    'errorMessage' => 'تم إلغاء هذا الرابط لأن رابطاً أحدث تم إصداره في المحادثة.',
                    'session' => null,
                    'existingLocation' => null,
                    'configPayload' => null,
                ], 410);
            }

            if ($session->isExpired()) {
                return response()->view('location.capture', [
                    'token' => $token,
                    'state' => 'session_expired',
                    'errorMessage' => 'انتهت صلاحية رابط تحديد الموقع. يمكنك طلب رابط جديد من المحادثة.',
                    'session' => null,
                    'existingLocation' => null,
                    'configPayload' => null,
                ], 410);
            }

            return response()->view('location.capture', [
                'token' => $token,
                'state' => 'usable',
                'errorMessage' => null,
                'session' => $session,
                'workspaceName' => $workspace?->name ?? 'HASEM',
                'orderNumber' => $session->order?->order_number,
                'existingLocation' => null,
                'configPayload' => [
                    'confirmUrl' => route('location.capture.confirm', ['token' => $token]),
                    'searchUrl' => route('location.capture.search', ['token' => $token]),
                    'reverseUrl' => route('location.capture.reverse', ['token' => $token]),
                    'defaultLat' => (float) config('location.map.default_lat', 24.7136),
                    'defaultLng' => (float) config('location.map.default_lng', 46.6753),
                    'tileUrl' => (string) config('location.map.tile_url', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
                    'weakAccuracyMeters' => (int) config('location.weak_accuracy_threshold_meters', 150),
                ],
            ], 200);
        });
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['nullable', 'integer', 'min:0', 'max:50000'],
            'source' => ['nullable', 'string', 'in:gps,manual_pin,gps_adjusted,search_pin'],
            'confirm' => ['required', 'accepted'],
            'customer_notes' => ['nullable', 'string', 'max:500'],
            'place_name' => ['nullable', 'string', 'max:180'],
            'formatted_address' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $result = $this->captureService->submitWebCapture($token, $validated, $request->ip());
        } catch (LocationCaptureException $exception) {
            return response()->json([
                'success' => false,
                'error_code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'location' => $exception->existingLocation?->toSummaryArray(),
            ], $exception->statusCode);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'error_code' => 'invalid_location',
                'message' => $exception->getMessage(),
            ], 422);
        }

        $location = $result['location'];

        return response()->json([
            'success' => true,
            'message' => 'تم تأكيد موقع التوصيل وإرساله بنجاح.',
            'location' => $location->toSummaryArray(),
            'order_number' => $result['order_linked']?->order_number,
        ]);
    }

    public function search(Request $request, string $token): JsonResponse
    {
        try {
            $session = $this->captureService->resolveSessionByPlainToken($token, trackOpen: false);
        } catch (LocationCaptureException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->statusCode);
        }

        if (! $session->isUsable()) {
            return response()->json([
                'success' => false,
                'message' => 'جلسة تحديد الموقع غير نشطة.',
            ], $session->isLocked() ? 409 : 410);
        }

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $results = $this->geocoder->searchPlaces((string) $validated['q']);

        return response()->json([
            'success' => true,
            'results' => $results,
        ]);
    }

    public function reverse(Request $request, string $token): JsonResponse
    {
        try {
            $session = $this->captureService->resolveSessionByPlainToken($token, trackOpen: false);
        } catch (LocationCaptureException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->statusCode);
        }

        if (! $session->isUsable()) {
            return response()->json([
                'success' => false,
                'message' => 'جلسة تحديد الموقع غير نشطة.',
            ], $session->isLocked() ? 409 : 410);
        }

        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $geo = $this->geocoder->reverse((float) $validated['lat'], (float) $validated['lng']);

        return response()->json([
            'success' => true,
            'geocoding_status' => $geo->status,
            'formatted_address' => $geo->formattedAddress,
            'city' => $geo->city,
            'district' => $geo->district,
            'street' => $geo->street,
            'place_name' => $geo->placeName,
        ]);
    }
}
