<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Models\Booking;
use App\Models\BookingBlockedSlot;
use App\Models\BookingBranch;
use App\Models\BookingProvider;
use App\Models\BookingReminder;
use App\Models\BookingService;
use App\Models\Conversation;
use App\Models\Customer;
use App\Services\Booking\BookingAvailabilityService;
use App\Services\Booking\BookingManagementService;
use App\Services\Booking\BookingNotificationService;
use App\Services\Booking\BookingSettingsService;
use App\Services\Booking\Exceptions\BookingException;
use App\Services\Booking\Exceptions\BookingUnavailableException;
use App\Support\Booking\BookingAccess;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BookingController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly BookingManagementService $bookings,
        private readonly BookingAvailabilityService $availability,
        private readonly BookingSettingsService $settingsService,
        private readonly BookingNotificationService $notifications,
        private readonly BookingAccess $access,
    ) {}

    public function index(Request $request): View
    {
        $workspace = $this->currentWorkspace();
        $user = $request->user();
        abort_unless($user && $this->access->canViewBookings($user, $workspace), 403);

        $setting = $this->settingsService->getOrCreate($workspace);
        $timezone = $setting->timezone ?: 'Asia/Riyadh';
        $todayLocal = CarbonImmutable::now($timezone)->format('Y-m-d');

        $viewMode = $request->string('view')->trim()->toString();
        $viewMode = in_array($viewMode, ['list', 'day', 'week', 'month'], true) ? $viewMode : 'list';

        $scope = $request->string('scope')->trim()->toString();
        $scope = in_array($scope, ['all', 'today', 'upcoming'], true) ? $scope : 'all';

        $search = $request->string('search')->trim()->toString();
        $status = $request->string('status')->trim()->toString();
        $date = $request->string('date')->trim()->toString();
        $serviceId = $request->integer('service_id') ?: null;
        $branchId = $request->integer('branch_id') ?: null;

        $anchorInput = $request->string('anchor_date')->trim()->toString() ?: $date ?: $todayLocal;
        try {
            $anchorDate = CarbonImmutable::parse($anchorInput, $timezone)->startOfDay();
        } catch (\Throwable) {
            $anchorDate = CarbonImmutable::now($timezone)->startOfDay();
        }

        $baseQuery = Booking::query()->where('workspace_id', $workspace->id);

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'today' => (clone $baseQuery)->where('booking_date', $todayLocal)->whereIn('status', BookingStatus::activeValues())->count(),
            'upcoming' => (clone $baseQuery)->where('booking_date', '>=', $todayLocal)->whereIn('status', BookingStatus::activeValues())->count(),
            'pending' => (clone $baseQuery)->where('status', BookingStatus::Pending->value)->count(),
            'confirmed' => (clone $baseQuery)->where('status', BookingStatus::Confirmed->value)->count(),
            'rescheduled' => (clone $baseQuery)->where('status', BookingStatus::Rescheduled->value)->count(),
            'cancelled' => (clone $baseQuery)->where('status', BookingStatus::Cancelled->value)->count(),
            'completed' => (clone $baseQuery)->where('status', BookingStatus::Completed->value)->count(),
            'no_show' => (clone $baseQuery)->where('status', BookingStatus::NoShow->value)->count(),
        ];

        $filteredQuery = Booking::query()
            ->where('workspace_id', $workspace->id)
            ->with(['service', 'branch', 'provider', 'customer', 'conversation'])
            ->when($scope === 'today', fn ($q) => $q->where('booking_date', $todayLocal))
            ->when($scope === 'upcoming', fn ($q) => $q->where('booking_date', '>=', $todayLocal)->whereIn('status', BookingStatus::activeValues()))
            ->when($search !== '', function ($q) use ($search): void {
                $q->where(function ($inner) use ($search): void {
                    $inner->where('booking_reference', 'like', '%'.$search.'%')
                        ->orWhere('customer_name', 'like', '%'.$search.'%')
                        ->orWhere('customer_phone', 'like', '%'.$search.'%')
                        ->orWhere('service_name', 'like', '%'.$search.'%');
                });
            })
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($date !== '', fn ($q) => $q->where('booking_date', $date))
            ->when($serviceId !== null, fn ($q) => $q->where('service_id', $serviceId))
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId));

        $bookingsList = (clone $filteredQuery)
            ->orderByDesc('booking_date')
            ->orderBy('start_time')
            ->paginate(20)
            ->withQueryString();

        $calendarData = [];
        if ($viewMode !== 'list') {
            if ($viewMode === 'day') {
                $rangeStart = $anchorDate;
                $rangeEnd = $anchorDate;
            } elseif ($viewMode === 'week') {
                $rangeStart = $anchorDate->startOfWeek(CarbonInterface::SUNDAY);
                $rangeEnd = $rangeStart->addDays(6);
            } else {
                $rangeStart = $anchorDate->startOfMonth();
                $rangeEnd = $anchorDate->endOfMonth();
            }

            $calendarBookings = (clone $filteredQuery)
                ->whereBetween('booking_date', [$rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d')])
                ->orderBy('booking_date')
                ->orderBy('start_time')
                ->get()
                ->groupBy(static fn (Booking $b): string => $b->booking_date?->format('Y-m-d') ?? '');

            $blockedSlots = BookingBlockedSlot::query()
                ->where('workspace_id', $workspace->id)
                ->where('starts_at', '<=', $rangeEnd->endOfDay()->utc())
                ->where('ends_at', '>=', $rangeStart->startOfDay()->utc())
                ->orderBy('starts_at')
                ->get();

            $calendarData = [
                'range_start' => $rangeStart,
                'range_end' => $rangeEnd,
                'bookings_by_date' => $calendarBookings,
                'blocked_slots' => $blockedSlots,
            ];
        }

        $services = BookingService::query()->where('workspace_id', $workspace->id)->orderBy('sort_order')->get();
        $branches = BookingBranch::query()->where('workspace_id', $workspace->id)->orderBy('sort_order')->get();
        $providers = BookingProvider::query()->where('workspace_id', $workspace->id)->orderBy('sort_order')->get();

        return view('workspace.bookings.index', [
            'setting' => $setting,
            'todayLocal' => $todayLocal,
            'viewMode' => $viewMode,
            'scope' => $scope,
            'search' => $search,
            'status' => $status,
            'date' => $date,
            'serviceId' => $serviceId,
            'branchId' => $branchId,
            'anchorDate' => $anchorDate,
            'stats' => $stats,
            'bookingsList' => $bookingsList,
            'calendarData' => $calendarData,
            'services' => $services,
            'branches' => $branches,
            'providers' => $providers,
            'canManageBookings' => $this->access->canManageBookings($user, $workspace),
            'canCancelBookings' => $this->access->canCancelBooking($user, $workspace),
            'canManageSettings' => $this->access->canManageSettings($user, $workspace),
        ]);
    }

    public function availability(Request $request): JsonResponse
    {
        $workspace = $this->currentWorkspace();
        $user = $request->user();
        abort_unless($user && $this->access->canViewBookings($user, $workspace), 403);

        $setting = $this->settingsService->getOrCreate($workspace);
        $timezone = $setting->timezone ?: 'Asia/Riyadh';
        $date = $request->string('date')->trim()->toString() ?: CarbonImmutable::now($timezone)->format('Y-m-d');
        $service = $request->integer('service_id')
            ? BookingService::query()->where('workspace_id', $workspace->id)->find($request->integer('service_id'))
            : null;
        $branch = $request->integer('branch_id')
            ? BookingBranch::query()->where('workspace_id', $workspace->id)->find($request->integer('branch_id'))
            : null;
        $provider = $request->integer('provider_id')
            ? BookingProvider::query()->where('workspace_id', $workspace->id)->find($request->integer('provider_id'))
            : null;
        $excludeId = $request->integer('exclude_booking_id') ?: null;
        $ignoreAdvance = $request->boolean('ignore_advance', false);

        $slots = $this->availability->availableSlotsForDate(
            workspace: $workspace,
            date: $date,
            service: $service,
            branch: $branch,
            provider: $provider,
            excludeBookingId: $excludeId,
            ignoreAdvanceRules: $ignoreAdvance,
            limit: 40,
        );

        return response()->json([
            'success' => true,
            'enabled' => (bool) $setting->enabled,
            'date' => $date,
            'slots' => $slots,
        ]);
    }

    public function create(Request $request): View
    {
        $workspace = $this->currentWorkspace();
        $user = $request->user();
        abort_unless($user && $this->access->canManageBookings($user, $workspace), 403);

        $setting = $this->settingsService->getOrCreate($workspace);
        $timezone = $setting->timezone ?: 'Asia/Riyadh';

        $prefillConversation = $request->integer('conversation_id')
            ? Conversation::query()->where('workspace_id', $workspace->id)->with('customer')->find($request->integer('conversation_id'))
            : null;
        $prefillCustomer = $prefillConversation?->customer ?: ($request->integer('customer_id')
            ? Customer::query()->where('workspace_id', $workspace->id)->find($request->integer('customer_id'))
            : null);

        $services = BookingService::query()->where('workspace_id', $workspace->id)->where('is_active', true)->orderBy('sort_order')->get();
        $branches = BookingBranch::query()->where('workspace_id', $workspace->id)->where('is_active', true)->orderBy('sort_order')->get();
        $providers = BookingProvider::query()->where('workspace_id', $workspace->id)->where('is_active', true)->orderBy('sort_order')->get();

        return view('workspace.bookings.create', [
            'setting' => $setting,
            'todayLocal' => CarbonImmutable::now($timezone)->format('Y-m-d'),
            'prefillConversation' => $prefillConversation,
            'prefillCustomer' => $prefillCustomer,
            'services' => $services,
            'branches' => $branches,
            'providers' => $providers,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $user = $request->user();
        abort_unless($user && $this->access->canManageBookings($user, $workspace), 403);

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
            'status' => ['nullable', 'string', Rule::in(BookingStatus::values())],
            'customer_notes' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
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
                data: [
                    ...$validated,
                    'source' => 'manual',
                    'channel' => $conversation?->channel ?? 'manual',
                ],
                conversation: $conversation,
                actor: $user,
                allowOverrideRules: $request->boolean('override_rules', true),
                notifyCustomerViaMessage: $request->boolean('notify_customer', true),
            );
        } catch (BookingUnavailableException $e) {
            return back()->withInput()->withErrors(['start_time' => $e->getMessage()]);
        } catch (BookingException $e) {
            return back()->withInput()->withErrors(['booking_date' => $e->getMessage()]);
        }

        return redirect()
            ->route('workspace.bookings.show', $booking)
            ->with('status', 'تم إنشاء الحجز #'.$booking->booking_reference.' بنجاح.');
    }

    public function show(Request $request, Booking $booking): View
    {
        $workspace = $this->currentWorkspace();
        $user = $request->user();
        abort_unless($user && $this->access->canViewBookings($user, $workspace), 403);
        $this->assertSameWorkspace($booking->workspace_id);

        $setting = $this->settingsService->getOrCreate($workspace);
        $booking->load(['customer', 'conversation', 'service', 'branch', 'provider', 'deliveryLocation', 'revisions', 'reminders', 'createdBy', 'updatedBy']);

        return view('workspace.bookings.show', [
            'booking' => $booking,
            'setting' => $setting,
            'services' => BookingService::query()->where('workspace_id', $workspace->id)->where('is_active', true)->orderBy('sort_order')->get(),
            'branches' => BookingBranch::query()->where('workspace_id', $workspace->id)->where('is_active', true)->orderBy('sort_order')->get(),
            'providers' => BookingProvider::query()->where('workspace_id', $workspace->id)->where('is_active', true)->orderBy('sort_order')->get(),
            'canManageBookings' => $this->access->canManageBookings($user, $workspace),
            'canCancelBooking' => $this->access->canCancelBooking($user, $workspace, $booking),
        ]);
    }

    public function update(Request $request, Booking $booking): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $user = $request->user();
        abort_unless($user && $this->access->canManageBookings($user, $workspace), 403);
        $this->assertSameWorkspace($booking->workspace_id);

        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:190'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'service_id' => ['nullable', 'integer'],
            'service_name' => ['nullable', 'string', 'max:190'],
            'branch_id' => ['nullable', 'integer'],
            'provider_id' => ['nullable', 'integer'],
            'party_size' => ['nullable', 'integer', 'min:1', 'max:500'],
            'customer_notes' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'custom_fields' => ['nullable', 'array'],
            'notify_customer' => ['nullable', 'boolean'],
        ]);

        try {
            $this->bookings->updateBookingDetails(
                workspace: $workspace,
                booking: $booking,
                data: $validated,
                actor: $user,
                allowOverrideRules: true,
                notifyCustomerViaMessage: $request->boolean('notify_customer', true),
            );
        } catch (BookingException $e) {
            return back()->withErrors(['update' => $e->getMessage()]);
        }

        return redirect()
            ->route('workspace.bookings.show', $booking)
            ->with('status', 'تم حفظ تعديلات الحجز بنجاح.');
    }

    public function reschedule(Request $request, Booking $booking): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $user = $request->user();
        abort_unless($user && $this->access->canManageBookings($user, $workspace), 403);
        $this->assertSameWorkspace($booking->workspace_id);

        $validated = $request->validate([
            'booking_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'branch_id' => ['nullable', 'integer'],
            'provider_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:500'],
            'override_rules' => ['nullable', 'boolean'],
            'notify_customer' => ['nullable', 'boolean'],
        ]);

        try {
            $this->bookings->rescheduleBooking(
                workspace: $workspace,
                booking: $booking,
                newDate: (string) $validated['booking_date'],
                newStartTime: (string) $validated['start_time'],
                data: $validated,
                actor: $user,
                allowOverrideRules: $request->boolean('override_rules', true),
                notifyCustomerViaMessage: $request->boolean('notify_customer', true),
            );
        } catch (BookingException $e) {
            return back()->withErrors(['reschedule' => $e->getMessage()]);
        }

        return redirect()
            ->route('workspace.bookings.show', $booking)
            ->with('status', 'تمت إعادة جدولة الحجز وتأجيله بنجاح.');
    }

    public function updateStatus(Request $request, Booking $booking): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $user = $request->user();
        abort_unless($user !== null, 403);
        $this->assertSameWorkspace($booking->workspace_id);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(BookingStatus::values())],
            'reason' => ['nullable', 'string', 'max:500'],
            'notify_customer' => ['nullable', 'boolean'],
        ]);

        $target = BookingStatus::from((string) $validated['status']);
        if ($target === BookingStatus::Cancelled) {
            abort_unless($this->access->canCancelBooking($user, $workspace, $booking), 403);
        } else {
            abort_unless($this->access->canManageBookings($user, $workspace), 403);
        }

        try {
            $this->bookings->transitionStatus(
                workspace: $workspace,
                booking: $booking,
                targetStatus: $target,
                reason: $validated['reason'] ?? null,
                actor: $user,
                notifyCustomerViaMessage: $request->boolean('notify_customer', true),
            );
        } catch (BookingException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()
            ->route('workspace.bookings.show', $booking)
            ->with('status', 'تم تحديث حالة الحجز إلى '.$target->labelAr().'.');
    }

    public function sendReminder(Request $request, Booking $booking): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $user = $request->user();
        abort_unless($user && $this->access->canManageBookings($user, $workspace), 403);
        $this->assertSameWorkspace($booking->workspace_id);

        if ($booking->conversation_id === null) {
            return back()->withErrors(['reminder' => 'لا توجد محادثة مرتبطة بهذا الحجز لإرسال التذكير عبرها.']);
        }

        $this->notifications->sendEventMessageToCustomer(
            $booking->fresh(['branch', 'provider', 'service']) ?? $booking,
            BookingReminder::TYPE_UPCOMING,
            $user,
            true,
        );

        return back()->with('status', 'تم إرسال رسالة التذكير للعميل عبر قناة المحادثة.');
    }
}
