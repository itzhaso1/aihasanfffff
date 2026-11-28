<?php

declare(strict_types=1);

namespace App\Services\Location;

use App\Jobs\ProcessAIResponse;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerLocation;
use App\Models\LocationCaptureRevision;
use App\Models\LocationCaptureSession;
use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\Inbox\Memory\ConversationFactsMemory;
use App\Services\Conversation\ConversationAiEligibilityService;
use App\Services\Inbox\InboxRealtimePublisher;
use App\Services\Location\Data\GeocodingResult;
use App\Services\Location\Data\NormalizedLocationPayload;
use App\Services\Location\Exceptions\LocationCaptureException;
use App\Support\Observability\CorrelationContext;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

final class LocationCaptureService
{
    public function __construct(
        private readonly LocationPayloadNormalizer $normalizer,
        private readonly ReverseGeocodingService $geocoder,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function supportsNativeLocation(string $channel): bool
    {
        return in_array(strtolower(trim($channel)), ['whatsapp', 'telegram'], true);
    }

    /**
     * Issue a secure, tenant-, conversation-, customer-, and optional order-bound capture session.
     * Raw CSPRNG token is returned ONCE and never stored unhashed in database.
     *
     * @return array{
     *     session: LocationCaptureSession,
     *     capture_url: string,
     *     expires_at: string,
     *     platform: string,
     *     supports_native_location: bool,
     *     prompt_message: string
     * }
     */
    public function createCaptureSession(
        Workspace $workspace,
        Conversation $conversation,
        ?Order $order = null,
        string $purpose = 'delivery',
        ?int $requestedByMessageId = null,
    ): array {
        if ((int) $conversation->workspace_id !== (int) $workspace->id) {
            throw (new ModelNotFoundException)->setModel(Conversation::class, [$conversation->id]);
        }

        if ($order !== null && (int) $order->workspace_id !== (int) $workspace->id) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$order->id]);
        }

        $platform = strtolower(trim((string) ($conversation->channel ?: 'web')));
        $purposeNormalized = in_array($purpose, ['delivery', 'update_delivery'], true) ? $purpose : 'delivery';
        $ttlHours = max(1, min(168, (int) config('location.capture_token_ttl_hours', 24)));
        $expiresAt = now()->addHours($ttlHours);

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);

        $session = $this->workspaceContext->runFor($workspace, function () use (
            $workspace,
            $conversation,
            $order,
            $requestedByMessageId,
            $tokenHash,
            $plainToken,
            $platform,
            $purposeNormalized,
            $expiresAt,
        ): LocationCaptureSession {
            return DB::transaction(function () use (
                $workspace,
                $conversation,
                $order,
                $requestedByMessageId,
                $tokenHash,
                $plainToken,
                $platform,
                $purposeNormalized,
                $expiresAt,
            ): LocationCaptureSession {
                // Revoke older unconfirmed sessions for this conversation so superseded links cannot linger.
                LocationCaptureSession::withoutGlobalScopes()
                    ->where('workspace_id', $workspace->id)
                    ->where('conversation_id', $conversation->id)
                    ->where('status', LocationCaptureSession::STATUS_PENDING)
                    ->update(['status' => LocationCaptureSession::STATUS_REVOKED]);

                return LocationCaptureSession::withoutGlobalScopes()->create([
                    'workspace_id' => $workspace->id,
                    'conversation_id' => $conversation->id,
                    'customer_id' => $conversation->customer_id,
                    'order_id' => $order?->id,
                    'requested_by_message_id' => $requestedByMessageId,
                    'token_hash' => $tokenHash,
                    'token_hint' => substr($plainToken, -6),
                    'platform' => $platform,
                    'purpose' => $purposeNormalized,
                    'status' => LocationCaptureSession::STATUS_PENDING,
                    'attempt_count' => 0,
                    'expires_at' => $expiresAt,
                    'metadata' => [
                        'order_number' => $order?->order_number,
                    ],
                ]);
            });
        });

        $captureUrl = route('location.capture.show', ['token' => $plainToken]);
        $prompt = $this->buildCustomerPrompt($captureUrl, $platform);

        return [
            'session' => $session,
            'capture_url' => $captureUrl,
            'expires_at' => $expiresAt->toIso8601String(),
            'platform' => $platform,
            'supports_native_location' => $this->supportsNativeLocation($platform),
            'prompt_message' => $prompt,
        ];
    }

    public function buildCustomerPrompt(string $captureUrl, string $platform): string
    {
        $channel = strtolower(trim($platform));

        if ($this->supportsNativeLocation($channel)) {
            return "📍 لتحديد موقع التوصيل بدقة، يمكنك اختيار إحدى الطريقتين:\n\n"
                ."1. اضغط على الرابط التالي، وسيتم فتح خريطة لتحديد موقعك الحالي بدقة باستخدام GPS، مع إمكانية تعديل الموقع يدوياً قبل التأكيد:\n"
                .$captureUrl."\n\n"
                .'2. أو يمكنك إرسال موقعك (Live Location أو Current Location) مباشرة هنا في المحادثة.';
        }

        if ($channel === 'facebook_messenger') {
            return "📍 لتحديد موقع التوصيل بدقة، اضغط على الرابط التالي لفتح الخريطة وتحديد موقعك عبر GPS أو تحريك الدبوس يدوياً قبل التأكيد:\n"
                .$captureUrl."\n\n"
                .'وإذا أمكنك إرسال دبوس الموقع من داخل المحادثة مباشرة فسيتم استلامه تلقائياً أيضاً.';
        }

        // Instagram & web/manual channels where live location sharing in DM is unavailable or unreliable.
        return "📍 لتحديد موقع التوصيل بدقة، يرجى فتح الرابط التالي لتحديد موقعك الحالي عبر GPS أو تحريك الدبوس يدوياً ثم الضغط على تأكيد الموقع:\n"
            .$captureUrl;
    }

    public function resolveSessionByPlainToken(string $plainToken, bool $trackOpen = true): LocationCaptureSession
    {
        if (! preg_match('/^[a-f0-9]{64}$/i', $plainToken)) {
            throw LocationCaptureException::notFound();
        }

        $hash = hash('sha256', strtolower($plainToken));
        $session = LocationCaptureSession::withoutGlobalScopes()
            ->where('token_hash', $hash)
            ->first();

        if (! $session) {
            throw LocationCaptureException::notFound();
        }

        return $this->workspaceContext->runForId((int) $session->workspace_id, function () use ($session, $trackOpen): LocationCaptureSession {
            $session->load(['workspace', 'conversation', 'customer', 'order', 'customerLocation']);

            if ($session->status === LocationCaptureSession::STATUS_PENDING && $session->expires_at !== null && now()->greaterThan($session->expires_at)) {
                $session->forceFill(['status' => LocationCaptureSession::STATUS_EXPIRED])->save();
            }

            if ($trackOpen && $session->opened_at === null && ! $session->isExpired()) {
                $session->forceFill(['opened_at' => now()])->save();
            }

            return $session;
        });
    }

    /**
     * Process confirmed web location capture submission atomically.
     *
     * @param  array<string, mixed>  $input
     * @return array{location: CustomerLocation, message: Message, order_linked: ?Order}
     */
    public function submitWebCapture(
        string $plainToken,
        array $input,
        ?string $clientIp = null,
    ): array {
        if (! preg_match('/^[a-f0-9]{64}$/i', $plainToken)) {
            throw LocationCaptureException::notFound();
        }

        $tokenHash = hash('sha256', strtolower($plainToken));
        $sessionStub = LocationCaptureSession::withoutGlobalScopes()
            ->where('token_hash', $tokenHash)
            ->first(['id', 'workspace_id', 'conversation_id']);

        if (! $sessionStub) {
            throw LocationCaptureException::notFound();
        }

        $rateKey = 'location-submit:conv:'.$sessionStub->workspace_id.':'.$sessionStub->conversation_id;
        $maxPerHour = max(1, (int) config('location.max_updates_per_conversation_hour', 10));
        if (RateLimiter::tooManyAttempts($rateKey, $maxPerHour)) {
            throw LocationCaptureException::rateLimited();
        }
        RateLimiter::hit($rateKey, 3600);

        $confirmed = filter_var($input['confirm'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (! $confirmed) {
            throw new InvalidArgumentException('يرجى الضغط على زر تأكيد الموقع لإرسال العنوان.');
        }

        $ipHash = $clientIp !== null && $clientIp !== ''
            ? hash_hmac('sha256', $clientIp, (string) config('app.key', 'hasem-location'))
            : null;

        /** @var array{location: CustomerLocation, session: LocationCaptureSession, superseded: ?CustomerLocation} $created */
        $created = $this->workspaceContext->runForId((int) $sessionStub->workspace_id, function () use ($tokenHash, $input, $ipHash): array {
            return DB::transaction(function () use ($tokenHash, $input, $ipHash): array {
                $session = LocationCaptureSession::withoutGlobalScopes()
                    ->where('token_hash', $tokenHash)
                    ->lockForUpdate()
                    ->first();

                if (! $session) {
                    throw LocationCaptureException::notFound();
                }

                if ($session->isLocked()) {
                    $existing = $session->customer_location_id
                        ? CustomerLocation::withoutGlobalScopes()->where('workspace_id', $session->workspace_id)->find($session->customer_location_id)
                        : null;
                    throw LocationCaptureException::alreadyConfirmed($existing);
                }

                if ($session->status === LocationCaptureSession::STATUS_REVOKED) {
                    throw LocationCaptureException::revoked();
                }

                if ($session->isExpired()) {
                    $session->forceFill(['status' => LocationCaptureSession::STATUS_EXPIRED])->save();
                    throw LocationCaptureException::expired();
                }

                $normalized = $this->normalizer->fromWebSubmission($input, $session);
                $conversation = Conversation::withoutGlobalScopes()
                    ->where('workspace_id', $session->workspace_id)
                    ->find($session->conversation_id);

                if (! $conversation) {
                    throw LocationCaptureException::notFound();
                }

                $priorConfirmed = $this->findActiveConfirmedLocation(
                    (int) $session->workspace_id,
                    (int) $conversation->id,
                    $conversation->customer_id ? (int) $conversation->customer_id : null,
                );

                $location = CustomerLocation::withoutGlobalScopes()->create([
                    'workspace_id' => $session->workspace_id,
                    'customer_id' => $conversation->customer_id ?? $session->customer_id,
                    'conversation_id' => $conversation->id,
                    'order_id' => $session->order_id,
                    'latitude' => $normalized->latitude,
                    'longitude' => $normalized->longitude,
                    'accuracy_meters' => $normalized->accuracyMeters,
                    'source' => $normalized->source,
                    'platform' => $normalized->platform,
                    'verification_status' => CustomerLocation::STATUS_CONFIRMED,
                    'place_name' => $normalized->placeName,
                    'formatted_address' => $normalized->providerAddress,
                    'customer_notes' => $normalized->customerNotes,
                    'geocoding_status' => 'pending',
                    'raw_payload' => $normalized->safeMetadata,
                    'confirmed_at' => now(),
                ]);

                if ($priorConfirmed !== null && (int) $priorConfirmed->id !== (int) $location->id) {
                    $priorConfirmed->forceFill([
                        'superseded_by_id' => $location->id,
                        'verification_status' => CustomerLocation::STATUS_SUPERSEDED,
                    ])->save();

                    $this->recordRevision(
                        location: $priorConfirmed,
                        eventType: 'superseded',
                        actorType: 'customer',
                        sessionId: (int) $session->id,
                        orderId: $session->order_id ? (int) $session->order_id : null,
                        metadata: ['superseded_by_id' => $location->id],
                    );
                }

                $this->recordRevision(
                    location: $location,
                    eventType: 'confirmed',
                    actorType: 'customer',
                    sessionId: (int) $session->id,
                    orderId: $session->order_id ? (int) $session->order_id : null,
                    metadata: ['capture_channel' => 'web_link'],
                );

                $session->forceFill([
                    'customer_location_id' => $location->id,
                    'customer_id' => $conversation->customer_id ?? $session->customer_id,
                    'status' => LocationCaptureSession::STATUS_CONFIRMED,
                    'attempt_count' => (int) $session->attempt_count + 1,
                    'confirmed_at' => now(),
                    'locked_at' => now(),
                    'submitted_ip_hash' => $ipHash,
                ])->save();

                return [
                    'location' => $location,
                    'session' => $session,
                    'superseded' => $priorConfirmed,
                ];
            });
        });

        /** @var CustomerLocation $location */
        $location = $created['location'];
        /** @var LocationCaptureSession $session */
        $session = $created['session'];

        // Perform reverse geocoding outside the row lock; preserve coordinates even on network/provider failure.
        $geo = $this->geocoder->reverse((float) $location->latitude, (float) $location->longitude, (int) $location->id);
        $this->applyGeocodingResult($location, $geo);
        $location = $location->fresh() ?? $location;

        return $this->workspaceContext->runForId((int) $location->workspace_id, function () use ($location, $session): array {
            $conversation = Conversation::withoutGlobalScopes()
                ->where('workspace_id', $location->workspace_id)
                ->findOrFail($location->conversation_id);

            $this->syncCustomerAddress($conversation->customer_id ? (int) $conversation->customer_id : null, $location);
            $linkedOrder = $this->syncTargetOrder($conversation, $location, $session->order_id ? (int) $session->order_id : null);

            $message = $this->persistLocationMessage($conversation, $location, [
                'location_capture_session_id' => $session->id,
                'origin' => 'web_capture_link',
            ]);

            $this->updateConversationLocationState($conversation, $location, $message);

            if ($message->wasRecentlyCreated) {
                app(InboxRealtimePublisher::class)->publishNewInboundMessage(
                    $message,
                    $conversation->fresh() ?? $conversation,
                    [
                        'provider' => $conversation->channel ?: 'web',
                        'event_id' => $message->external_message_id,
                    ],
                );

                if (app(ConversationAiEligibilityService::class)->shouldDispatchAiJob($conversation, $message)) {
                    ProcessAIResponse::dispatchForInbound(
                        (int) $conversation->id,
                        (int) $message->id,
                        (int) $conversation->workspace_id,
                        CorrelationContext::id(),
                    );
                }
            }

            return [
                'location' => $location->fresh() ?? $location,
                'message' => $message,
                'order_linked' => $linkedOrder,
            ];
        });
    }

    /**
     * Ingest normalized native in-chat location from WhatsApp, Telegram, Messenger, or Instagram webhooks.
     */
    public function ingestChannelLocation(
        Conversation $conversation,
        Message $message,
        NormalizedLocationPayload $normalized,
    ): CustomerLocation {
        return $this->workspaceContext->runForId((int) $conversation->workspace_id, function () use ($conversation, $message, $normalized): CustomerLocation {
            $extId = $normalized->externalMessageId ?: ($message->external_message_id ?: null);

            if ($extId !== null) {
                $existing = CustomerLocation::withoutGlobalScopes()
                    ->where('workspace_id', $conversation->workspace_id)
                    ->where('external_message_id', $extId)
                    ->first();

                if ($existing !== null) {
                    $this->enrichMessageWithLocation($message, $existing);

                    return $existing;
                }
            }

            $priorConfirmed = $this->findActiveConfirmedLocation(
                (int) $conversation->workspace_id,
                (int) $conversation->id,
                $conversation->customer_id ? (int) $conversation->customer_id : null,
            );

            $weakThreshold = (int) config('location.weak_accuracy_threshold_meters', 150);
            $isWeakAccuracy = $normalized->accuracyMeters !== null && $normalized->accuracyMeters > $weakThreshold;

            $existingActiveOrder = $this->findAttachableConversationOrder((int) $conversation->workspace_id, (int) $conversation->id, $conversation->customer_id ? (int) $conversation->customer_id : null);
            $hasDifferentConfirmedOrderLocation = false;
            if ($priorConfirmed !== null && $existingActiveOrder !== null && $existingActiveOrder->delivery_location_id !== null) {
                $distance = $this->haversineMeters(
                    (float) $priorConfirmed->latitude,
                    (float) $priorConfirmed->longitude,
                    $normalized->latitude,
                    $normalized->longitude,
                );
                if ($distance > 120.0) {
                    $hasDifferentConfirmedOrderLocation = true;
                }
            }

            $verificationStatus = match (true) {
                $isWeakAccuracy => CustomerLocation::STATUS_NEEDS_ADJUSTMENT,
                $hasDifferentConfirmedOrderLocation => CustomerLocation::STATUS_PENDING_CONFIRMATION,
                default => CustomerLocation::STATUS_CONFIRMED,
            };

            $location = CustomerLocation::withoutGlobalScopes()->create([
                'workspace_id' => $conversation->workspace_id,
                'customer_id' => $conversation->customer_id ?? $normalized->customerId,
                'conversation_id' => $conversation->id,
                'order_id' => $normalized->orderId,
                'latitude' => $normalized->latitude,
                'longitude' => $normalized->longitude,
                'accuracy_meters' => $normalized->accuracyMeters,
                'source' => $normalized->source,
                'platform' => $normalized->platform,
                'verification_status' => $verificationStatus,
                'place_name' => $normalized->placeName,
                'formatted_address' => $normalized->providerAddress,
                'geocoding_status' => 'pending',
                'external_message_id' => $extId,
                'raw_payload' => array_merge($normalized->safeMetadata, array_filter([
                    'replaces_location_id' => $hasDifferentConfirmedOrderLocation && $priorConfirmed ? $priorConfirmed->id : null,
                    'weak_accuracy' => $isWeakAccuracy ?: null,
                ])),
                'confirmed_at' => $verificationStatus === CustomerLocation::STATUS_CONFIRMED ? now() : null,
            ]);

            $geo = $this->geocoder->reverse((float) $location->latitude, (float) $location->longitude, (int) $location->id);
            $this->applyGeocodingResult($location, $geo, fallbackPlace: $normalized->placeName, fallbackAddress: $normalized->providerAddress);
            $location = $location->fresh() ?? $location;

            if ($verificationStatus === CustomerLocation::STATUS_CONFIRMED && $priorConfirmed !== null && (int) $priorConfirmed->id !== (int) $location->id) {
                $priorConfirmed->forceFill([
                    'superseded_by_id' => $location->id,
                    'verification_status' => CustomerLocation::STATUS_SUPERSEDED,
                ])->save();

                $this->recordRevision(
                    location: $priorConfirmed,
                    eventType: 'superseded',
                    actorType: 'webhook',
                    metadata: ['superseded_by_id' => $location->id],
                );
            }

            $this->recordRevision(
                location: $location,
                eventType: $verificationStatus === CustomerLocation::STATUS_CONFIRMED ? 'confirmed' : 'created',
                actorType: 'webhook',
                metadata: [
                    'verification_status' => $verificationStatus,
                    'replaces_location_id' => $hasDifferentConfirmedOrderLocation && $priorConfirmed ? $priorConfirmed->id : null,
                ],
            );

            if ($verificationStatus === CustomerLocation::STATUS_CONFIRMED) {
                $this->syncCustomerAddress($conversation->customer_id ? (int) $conversation->customer_id : null, $location);
                $this->syncTargetOrder($conversation, $location, $normalized->orderId);
            }

            $this->enrichMessageWithLocation($message, $location);
            $this->updateConversationLocationState($conversation, $location, $message);

            return $location;
        });
    }

    public function updateVerificationStatus(
        Workspace $workspace,
        CustomerLocation $location,
        string $status,
        ?User $actor = null,
    ): CustomerLocation {
        if ((int) $location->workspace_id !== (int) $workspace->id) {
            throw (new ModelNotFoundException)->setModel(CustomerLocation::class, [$location->id]);
        }

        if (! in_array($status, [
            CustomerLocation::STATUS_CONFIRMED,
            CustomerLocation::STATUS_NEEDS_ADJUSTMENT,
            CustomerLocation::STATUS_PENDING_CONFIRMATION,
        ], true)) {
            throw new InvalidArgumentException('Unsupported verification status.');
        }

        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $location, $status, $actor): CustomerLocation {
            $updated = DB::transaction(function () use ($workspace, $location, $status, $actor): CustomerLocation {
                $locked = CustomerLocation::withoutGlobalScopes()
                    ->where('workspace_id', $workspace->id)
                    ->whereKey($location->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $updates = [
                    'verification_status' => $status,
                ];
                if ($status === CustomerLocation::STATUS_CONFIRMED) {
                    $updates['confirmed_at'] = $locked->confirmed_at ?? now();

                    if ($locked->conversation_id) {
                        $others = CustomerLocation::withoutGlobalScopes()
                            ->where('workspace_id', $workspace->id)
                            ->where('conversation_id', $locked->conversation_id)
                            ->where('id', '!=', $locked->id)
                            ->where('verification_status', CustomerLocation::STATUS_CONFIRMED)
                            ->get();

                        foreach ($others as $old) {
                            $old->forceFill([
                                'verification_status' => CustomerLocation::STATUS_SUPERSEDED,
                                'superseded_by_id' => $locked->id,
                            ])->save();
                            $this->recordRevision(
                                location: $old,
                                eventType: 'superseded',
                                actorType: $actor ? 'agent' : 'system',
                                actorId: $actor?->id,
                                metadata: ['superseded_by_id' => $locked->id],
                            );
                        }
                    }
                }

                $locked->forceFill($updates)->save();

                $this->recordRevision(
                    location: $locked,
                    eventType: $status === CustomerLocation::STATUS_CONFIRMED ? 'confirmed' : 'marked_needs_adjustment',
                    actorType: $actor ? 'agent' : 'system',
                    actorId: $actor?->id,
                );

                return $locked->fresh() ?? $locked;
            });

            if ($updated->conversation_id) {
                $conversation = Conversation::withoutGlobalScopes()
                    ->where('workspace_id', $workspace->id)
                    ->find($updated->conversation_id);
                if ($conversation) {
                    if ($status === CustomerLocation::STATUS_CONFIRMED) {
                        $this->syncCustomerAddress($conversation->customer_id ? (int) $conversation->customer_id : null, $updated);
                        $this->syncTargetOrder($conversation, $updated, $updated->order_id ? (int) $updated->order_id : null, forceOverride: true);
                    }
                    $this->updateConversationLocationState($conversation, $updated);

                    $linkedMsg = Message::withoutGlobalScopes()
                        ->where('workspace_id', $workspace->id)
                        ->where('conversation_id', $conversation->id)
                        ->where('message_type', 'location')
                        ->where('metadata->location->id', $updated->id)
                        ->latest('id')
                        ->first();
                    if ($linkedMsg) {
                        $this->enrichMessageWithLocation($linkedMsg, $updated);
                        app(InboxRealtimePublisher::class)->publishMessageUpdated($linkedMsg->fresh() ?? $linkedMsg, $conversation);
                    } else {
                        app(InboxRealtimePublisher::class)->publishConversationUpdate($conversation);
                    }
                }
            }

            return $updated;
        });
    }

    /**
     * Attach latest verified (or pending) conversation location when an order is created AFTER location capture.
     */
    public function attachExistingLocationToNewOrder(Order $order, ?Conversation $conversation = null): ?CustomerLocation
    {
        $location = null;

        if ($conversation !== null) {
            $location = CustomerLocation::withoutGlobalScopes()
                ->where('workspace_id', $order->workspace_id)
                ->where('conversation_id', $conversation->id)
                ->where('verification_status', CustomerLocation::STATUS_CONFIRMED)
                ->latest('id')
                ->first();
        }

        if ($location === null && $order->customer_id !== null) {
            $location = CustomerLocation::withoutGlobalScopes()
                ->where('workspace_id', $order->workspace_id)
                ->where('customer_id', $order->customer_id)
                ->where('verification_status', CustomerLocation::STATUS_CONFIRMED)
                ->latest('id')
                ->first();
        }

        if ($location === null) {
            return null;
        }

        $this->linkOrderRecord($order, $location);

        return $location;
    }

    public function latestActiveForConversation(int $workspaceId, int $conversationId, ?int $customerId = null): ?CustomerLocation
    {
        $confirmed = $this->findActiveConfirmedLocation($workspaceId, $conversationId, $customerId);
        if ($confirmed !== null) {
            return $confirmed;
        }

        return CustomerLocation::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('conversation_id', $conversationId)
            ->whereIn('verification_status', [CustomerLocation::STATUS_PENDING_CONFIRMATION, CustomerLocation::STATUS_NEEDS_ADJUSTMENT])
            ->latest('id')
            ->first();
    }

    private function findActiveConfirmedLocation(int $workspaceId, int $conversationId, ?int $customerId = null): ?CustomerLocation
    {
        $byConversation = CustomerLocation::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('conversation_id', $conversationId)
            ->where('verification_status', CustomerLocation::STATUS_CONFIRMED)
            ->latest('id')
            ->first();

        if ($byConversation !== null || $customerId === null) {
            return $byConversation;
        }

        return CustomerLocation::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('customer_id', $customerId)
            ->where('verification_status', CustomerLocation::STATUS_CONFIRMED)
            ->latest('id')
            ->first();
    }

    private function applyGeocodingResult(
        CustomerLocation $location,
        GeocodingResult $geo,
        ?string $fallbackPlace = null,
        ?string $fallbackAddress = null,
    ): void {
        if ($geo->resolved()) {
            $location->forceFill([
                'geocoding_provider' => $geo->provider,
                'geocoding_status' => 'resolved',
                'country_code' => $geo->countryCode ?? $location->country_code,
                'country' => $geo->country ?? $location->country,
                'city' => $geo->city ?? $location->city,
                'district' => $geo->district ?? $location->district,
                'street' => $geo->street ?? $location->street,
                'postal_code' => $geo->postalCode ?? $location->postal_code,
                'place_name' => $location->place_name ?: ($geo->placeName ?? $fallbackPlace),
                'formatted_address' => $geo->formattedAddress ?: ($fallbackAddress ?: $location->formatted_address),
            ])->save();

            return;
        }

        $fallbackFormatted = $location->formatted_address
            ?: ($fallbackAddress ?: ($fallbackPlace ?: null));

        $location->forceFill([
            'geocoding_provider' => $geo->provider,
            'geocoding_status' => $geo->status,
            'formatted_address' => $fallbackFormatted ?: sprintf(
                'إحداثيات (%s, %s)',
                number_format((float) $location->latitude, 6, '.', ''),
                number_format((float) $location->longitude, 6, '.', ''),
            ),
        ])->save();
    }

    private function syncCustomerAddress(?int $customerId, CustomerLocation $location): void
    {
        if ($customerId === null) {
            return;
        }

        $customer = Customer::withoutGlobalScopes()
            ->where('workspace_id', $location->workspace_id)
            ->find($customerId);

        if (! $customer) {
            return;
        }

        $metadata = is_array($customer->metadata) ? $customer->metadata : [];
        $metadata['delivery_location'] = $location->toSummaryArray();

        $updates = [
            'primary_location_id' => $location->id,
            'metadata' => $metadata,
        ];

        if ($location->city) {
            $updates['city'] = mb_substr($location->city, 0, 120);
        }
        if ($location->district) {
            $updates['district'] = mb_substr($location->district, 0, 120);
        }
        if ($location->street) {
            $updates['street'] = mb_substr($location->street, 0, 200);
        }
        if ($location->postal_code) {
            $updates['postal_code'] = mb_substr($location->postal_code, 0, 32);
        }
        if ($location->country_code) {
            $updates['country_code'] = strtoupper(mb_substr($location->country_code, 0, 8));
        }
        if ($location->formatted_address) {
            $updates['address'] = $location->formatted_address;
        }

        $customer->forceFill($updates)->save();
    }

    private function findAttachableConversationOrder(int $workspaceId, int $conversationId, ?int $customerId = null): ?Order
    {
        return Order::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where(function ($q) use ($conversationId, $customerId): void {
                $q->where('conversation_id', $conversationId);
                if ($customerId !== null) {
                    $q->orWhere('customer_id', $customerId);
                }
            })
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->latest('id')
            ->first();
    }

    private function syncTargetOrder(
        Conversation $conversation,
        CustomerLocation $location,
        ?int $explicitOrderId = null,
        bool $forceOverride = false,
    ): ?Order {
        $order = null;
        if ($explicitOrderId !== null) {
            $order = Order::withoutGlobalScopes()
                ->where('workspace_id', $conversation->workspace_id)
                ->find($explicitOrderId);
        }

        if ($order === null) {
            $order = $this->findAttachableConversationOrder(
                (int) $conversation->workspace_id,
                (int) $conversation->id,
                $conversation->customer_id ? (int) $conversation->customer_id : null,
            );
        }

        if ($order === null || in_array((string) $order->status, ['cancelled', 'completed'], true)) {
            return null;
        }

        if (
            ! $forceOverride
            && $order->delivery_location_id !== null
            && (in_array((string) $order->fulfillment_status, ['fulfilled', 'cancelled'], true)
                || in_array((string) $order->shipping_status, ['shipped', 'delivered', 'returned'], true))
        ) {
            return null;
        }

        if (! $forceOverride && $order->delivery_location_id !== null && (int) $order->delivery_location_id === (int) $location->id) {
            $this->linkOrderRecord($order, $location);

            return $order->fresh();
        }

        $this->linkOrderRecord($order, $location);

        return $order->fresh();
    }

    private function linkOrderRecord(Order $order, CustomerLocation $location): void
    {
        $metadata = is_array($order->metadata) ? $order->metadata : [];
        $metadata['delivery_location'] = $location->toSummaryArray();
        if ($location->formatted_address) {
            $metadata['shipping_address'] = $location->formatted_address;
        }
        $metadata['address_updated_at'] = now()->toIso8601String();
        $metadata['last_modified_at'] = $metadata['address_updated_at'];

        $notesLines = preg_split("/\r\n|\n|\r/", (string) ($order->notes ?? '')) ?: [];
        $cleanNotesLines = [];
        foreach ($notesLines as $line) {
            $t = trim($line);
            if ($t === '' || preg_match('/^العنوان\s*:/u', $t) === 1) {
                continue;
            }
            $cleanNotesLines[] = $t;
        }
        if ($location->formatted_address) {
            $cleanNotesLines[] = 'العنوان: '.$location->formatted_address;
        }

        $updates = [
            'delivery_location_id' => $location->id,
            'notes' => $cleanNotesLines !== [] ? implode("\n", $cleanNotesLines) : null,
            'metadata' => $metadata,
        ];

        // Clear address-missing review flags cleanly once confirmed location is linked.
        if (
            $location->verification_status === CustomerLocation::STATUS_CONFIRMED
            && is_string($order->review_reason)
            && (str_contains($order->review_reason, 'العنوان') || str_contains($order->review_reason, 'الموقع'))
        ) {
            $updates['review_reason'] = null;
            $updates['review_required_action'] = null;
        }

        $order->forceFill($updates)->save();

        if ($location->order_id === null || (int) $location->order_id !== (int) $order->id) {
            $location->forceFill(['order_id' => $order->id])->save();
        }

        $this->recordRevision(
            location: $location,
            eventType: 'linked_order',
            actorType: 'system',
            orderId: (int) $order->id,
        );

        app(InboxRealtimePublisher::class)->publishOrderUpdated($order->fresh() ?? $order, null, 'address_updated');
    }

    /**
     * @param  array<string, mixed>  $extraMeta
     */
    private function persistLocationMessage(Conversation $conversation, CustomerLocation $location, array $extraMeta = []): Message
    {
        $extId = 'loc:'.$location->id.':'.($extraMeta['location_capture_session_id'] ?? 'web');
        $existing = Message::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('external_message_id', $extId)
            ->first();
        if ($existing) {
            return $existing;
        }

        $content = $this->formatLocationMessageText($location);
        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'direction' => 'inbound',
            'message_type' => 'location',
            'content' => $content,
            'external_message_id' => $extId,
            'metadata' => array_merge($extraMeta, [
                'channel' => $conversation->channel,
                'location' => $location->toSummaryArray(),
            ]),
        ]);

        $conversation->forceFill($conversation->attributesForInboundCustomerMessage([
            'last_message_at' => $message->created_at ?? now(),
        ]))->save();

        return $message;
    }

    private function enrichMessageWithLocation(Message $message, CustomerLocation $location): void
    {
        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $metadata['location'] = $location->toSummaryArray();

        $message->forceFill([
            'message_type' => 'location',
            'content' => $this->formatLocationMessageText($location),
            'metadata' => $metadata,
        ])->save();
    }

    public function formatLocationMessageText(CustomerLocation $location): string
    {
        $lines = ['📍 تم تحديد الموقع:'];
        if ($location->formatted_address) {
            $lines[] = $location->formatted_address;
        }
        if ($location->city || $location->district) {
            $parts = array_filter([$location->city, $location->district]);
            if ($parts !== [] && ! str_contains((string) $location->formatted_address, (string) reset($parts))) {
                $lines[] = implode(' — ', $parts);
            }
        }
        if ($location->customer_notes) {
            $lines[] = 'ملاحظة: '.$location->customer_notes;
        }
        if ($location->accuracy_meters !== null) {
            $lines[] = 'الدقة: '.$location->accuracy_meters.' م';
        }
        $lines[] = 'الحالة: '.$location->verificationLabel();

        return implode("\n", $lines);
    }

    private function updateConversationLocationState(Conversation $conversation, CustomerLocation $location, ?Message $message = null): void
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $metadata['customer_location'] = $location->toSummaryArray();

        $updates = [
            'metadata' => $metadata,
        ];
        if ($message !== null) {
            $updates['last_message_at'] = $message->created_at ?? now();
        }
        $conversation->forceFill($updates)->save();

        try {
            $facts = app(ConversationFactsMemory::class);
            $current = $facts->get((int) $conversation->workspace_id, (int) $conversation->id);
            $current['address'] = $location->formatted_address ?: ($current['address'] ?? null);
            $current['city'] = $location->city ?: ($current['city'] ?? null);
            $current['district'] = $location->district ?? ($current['district'] ?? null);
            $current['location_captured'] = true;
            $current['location_status'] = $location->verification_status;
            $current['location_id'] = (int) $location->id;
            $current['last_updated_at'] = now()->toIso8601String();
            Cache::put(
                ConversationFactsMemory::key((int) $conversation->workspace_id, (int) $conversation->id),
                $current,
                ConversationFactsMemory::TTL_SECONDS,
            );
            $convMeta = is_array($conversation->metadata) ? $conversation->metadata : [];
            $convMeta['conversation_facts'] = $current;
            $conversation->forceFill(['metadata' => $convMeta])->save();
        } catch (\Throwable $exception) {
            Log::debug('location.facts_sync_skipped', [
                'location_id' => $location->id,
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordRevision(
        CustomerLocation $location,
        string $eventType,
        string $actorType = 'customer',
        ?int $actorId = null,
        ?int $sessionId = null,
        ?int $orderId = null,
        array $metadata = [],
    ): void {
        LocationCaptureRevision::withoutGlobalScopes()->create([
            'workspace_id' => $location->workspace_id,
            'customer_location_id' => $location->id,
            'capture_session_id' => $sessionId,
            'conversation_id' => $location->conversation_id,
            'customer_id' => $location->customer_id,
            'order_id' => $orderId ?? $location->order_id,
            'event_type' => $eventType,
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
            'accuracy_meters' => $location->accuracy_meters,
            'source' => $location->source,
            'platform' => $location->platform,
            'formatted_address' => $location->formatted_address,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'metadata' => $metadata !== [] ? $metadata : null,
            'created_at' => now(),
        ]);
    }

    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLon / 2) * sin($dLon / 2);

        return $earthRadius * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}
