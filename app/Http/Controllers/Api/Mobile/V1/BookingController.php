<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\BookingStatus;
use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Resources\Mobile\BookingResource;
use App\Models\Booking;
use App\Models\BookingBranch;
use App\Models\BookingProvider;
use App\Models\BookingService;
use App\Models\Conversation;
use App\Services\Booking\BookingAvailabilityService;
use App\Services\Booking\BookingManagementService;
use App\Services\Booking\BookingSettingsService;
use App\Services\Booking\Exceptions\BookingException;
use App\Services\Booking\Exceptions\BookingUnavailableException;
use App\Support\Booking\BookingAccess;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BookingController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly BookingManagementService $bookings,
        private readonly BookingAvailabilityService $availability,
        private readonly BookingSettingsService $settingsService,
        private readonly BookingAccess $access,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $request->user();
        if (! $user || ! $this->access->canViewBookings($user, $workspace)) {
            return $this->fail('غير مصرح لك بعرض الحجوزات.', 403);
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 25)));
        $search = $request->string('search')->trim()->toString();
        $status = $request->string('status')->trim()->toString();
        $date = $request->string('date')->trim()->toString();
        $scope = $request->string('scope')->trim()->toString();

        $setting = $this->settingsService->getOrCreate($workspace);
        $todayLocal = now($setting->timezone ?: 'Asia/Riyadh')->format('Y-m-d');

        $paginator = Booking::query()
            ->where('workspace_id', $workspace->id)
            ->with(['branch', 'provider', 'service'])
            ->when($scope === 'today', fn ($q) => $q->where('booking_date', $todayLocal))
            ->when($scope === 'upcoming', fn ($q) => $q->where('booking_date', '>=', $todayLocal)->whereIn('status', BookingStatus::activeValues()))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($date !== '', fn ($q) => $q->where('booking_date', $date))
            ->when($request->filled('service_id'), fn ($q) => $q->where('service_id', $request->integer('service_id')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($search !== '', function ($q) use ($search): void {
                $q->where(function ($inner) use ($search): void {
                    $inner->where('booking_reference', 'like', '%'.$search.'%')
                        ->orWhere('customer_name', 'like', '%'.$search.'%')
                        ->orWhere('customer_phone', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('booking_date')
            ->orderBy('start_time')
            ->cursorPaginate($perPage);

        return $this->ok(
            BookingResource::collection($paginator->items()),
            $this->cursorMeta(
                $paginator->nextCursor()?->encode(),
                $paginator->previousCursor()?->encode(),
                $perPage,
            ),
        );
    }

    public function availability(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $request->user();
        if (! $user || ! $this->access->canViewBookings($user, $workspace)) {
            return $this->fail('غير مصرح.', 403);
        }

        $setting = $this->settingsService->getOrCreate($workspace);
        $date = $request->string('date')->trim()->toString() ?: now($setting->timezone ?: 'Asia/Riyadh')->format('Y-m-d');
        $service = $request->filled('service_id') ? BookingService::query()->where('workspace_id', $workspace->id)->find($request->integer('service_id')) : null;
        $branch = $request->filled('branch_id') ? BookingBranch::query()->where('workspace_id', $workspace->id)->find($request->integer('branch_id')) : null;
        $provider = $request->filled('provider_id') ? BookingProvider::query()->where('workspace_id', $workspace->id)->find($request->integer('provider_id')) : null;

        $slots = $this->availability->availableSlotsForDate(
            workspace: $workspace,
            date: $date,
            service: $service,
            branch: $branch,
            provider: $provider,
            limit: 40,
        );

        return $this->ok([
            'enabled' => (bool) $setting->enabled,
            'date' => $date,
            'slots' => $slots,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $request->user();
        if (! $user || ! $this->access->canManageBookings($user, $workspace)) {
            return $this->fail('غير مصرح لك بإنشاء الحجوزات.', 403);
        }

        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:190'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'service_id' => ['nullable', 'integer'],
            'service_name' => ['nullable', 'string', 'max:190'],
            'branch_id' => ['nullable', 'integer'],
            'provider_id' => ['nullable', 'integer'],
            'conversation_id' => ['nullable', 'integer'],
            'booking_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'party_size' => ['nullable', 'integer', 'min:1', 'max:500'],
            'customer_notes' => ['nullable', 'string', 'max:2000'],
            'custom_fields' => ['nullable', 'array'],
            'override_rules' => ['nullable', 'boolean'],
            'notify_customer' => ['nullable', 'boolean'],
        ]);

        $conversation = ! empty($validated['conversation_id'])
            ? Conversation::query()->where('workspace_id', $workspace->id)->find((int) $validated['conversation_id'])
            : null;

        try {
            $booking = $this->bookings->createBooking(
                workspace: $workspace,
                data: [...$validated, 'source' => 'mobile', 'channel' => $conversation?->channel ?? 'mobile'],
                conversation: $conversation,
                actor: $user,
                allowOverrideRules: $request->boolean('override_rules', true),
                notifyCustomerViaMessage: $request->boolean('notify_customer', true),
            );
        } catch (BookingUnavailableException $e) {
            return $this->fail($e->getMessage(), 422, ['alternatives' => $e->alternatives]);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(new BookingResource($booking), message: 'تم إنشاء الحجز بنجاح.', status: 201);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $request->user();
        if ((int) $booking->workspace_id !== (int) $workspace->id) {
            return $this->fail('العنصر المطلوب غير موجود.', 404);
        }
        if (! $user || ! $this->access->canViewBookings($user, $workspace)) {
            return $this->fail('غير مصرح.', 403);
        }

        return $this->ok(new BookingResource($booking->load(['branch', 'provider', 'service'])));
    }

    public function update(Request $request, Booking $booking): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $request->user();
        if ((int) $booking->workspace_id !== (int) $workspace->id) {
            return $this->fail('العنصر المطلوب غير موجود.', 404);
        }
        if (! $user || ! $this->access->canManageBookings($user, $workspace)) {
            return $this->fail('غير مصرح.', 403);
        }

        $validated = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:190'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'service_id' => ['nullable', 'integer'],
            'service_name' => ['nullable', 'string', 'max:190'],
            'branch_id' => ['nullable', 'integer'],
            'provider_id' => ['nullable', 'integer'],
            'party_size' => ['nullable', 'integer', 'min:1', 'max:500'],
            'customer_notes' => ['nullable', 'string', 'max:2000'],
            'custom_fields' => ['nullable', 'array'],
            'notify_customer' => ['nullable', 'boolean'],
        ]);

        try {
            $updated = $this->bookings->updateBookingDetails(
                workspace: $workspace,
                booking: $booking,
                data: $validated,
                actor: $user,
                allowOverrideRules: true,
                notifyCustomerViaMessage: $request->boolean('notify_customer', true),
            );
        } catch (BookingException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(new BookingResource($updated), message: 'تم تحديث الحجز بنجاح.');
    }

    public function reschedule(Request $request, Booking $booking): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $request->user();
        if ((int) $booking->workspace_id !== (int) $workspace->id) {
            return $this->fail('العنصر المطلوب غير موجود.', 404);
        }
        if (! $user || ! $this->access->canManageBookings($user, $workspace)) {
            return $this->fail('غير مصرح.', 403);
        }

        $validated = $request->validate([
            'booking_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'branch_id' => ['nullable', 'integer'],
            'provider_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:500'],
            'notify_customer' => ['nullable', 'boolean'],
        ]);

        try {
            $updated = $this->bookings->rescheduleBooking(
                workspace: $workspace,
                booking: $booking,
                newDate: (string) $validated['booking_date'],
                newStartTime: (string) $validated['start_time'],
                data: $validated,
                actor: $user,
                allowOverrideRules: true,
                notifyCustomerViaMessage: $request->boolean('notify_customer', true),
            );
        } catch (BookingUnavailableException $e) {
            return $this->fail($e->getMessage(), 422, ['alternatives' => $e->alternatives]);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(new BookingResource($updated), message: 'تم تأجيل وإعادة جدولة الحجز.');
    }

    public function updateStatus(Request $request, Booking $booking): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $request->user();
        if ((int) $booking->workspace_id !== (int) $workspace->id) {
            return $this->fail('العنصر المطلوب غير موجود.', 404);
        }
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(BookingStatus::values())],
            'reason' => ['nullable', 'string', 'max:500'],
            'notify_customer' => ['nullable', 'boolean'],
        ]);

        $target = BookingStatus::from((string) $validated['status']);
        $allowed = $target === BookingStatus::Cancelled
            ? $this->access->canCancelBooking($user, $workspace, $booking)
            : $this->access->canManageBookings($user, $workspace);

        if (! $allowed) {
            return $this->fail('غير مصرح لك بتغيير حالة الحجز.', 403);
        }

        try {
            $updated = $this->bookings->transitionStatus(
                workspace: $workspace,
                booking: $booking,
                targetStatus: $target,
                reason: $validated['reason'] ?? null,
                actor: $user,
                notifyCustomerViaMessage: $request->boolean('notify_customer', true),
            );
        } catch (BookingException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(new BookingResource($updated), message: 'تم تحديث حالة الحجز.');
    }

    public function settings(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $request->user();
        if (! $user || ! $this->access->canViewBookings($user, $workspace)) {
            return $this->fail('غير مصرح.', 403);
        }

        $setting = $this->settingsService->getOrCreate($workspace);

        return $this->ok([
            'setting' => $setting,
            'services' => BookingService::query()->where('workspace_id', $workspace->id)->where('is_active', true)->orderBy('sort_order')->get(),
            'branches' => BookingBranch::query()->where('workspace_id', $workspace->id)->where('is_active', true)->orderBy('sort_order')->get(),
            'providers' => BookingProvider::query()->where('workspace_id', $workspace->id)->where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }
}
