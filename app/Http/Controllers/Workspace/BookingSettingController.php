<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Models\BookingBlockedSlot;
use App\Models\BookingBranch;
use App\Models\BookingProvider;
use App\Models\BookingService;
use App\Models\BookingSetting;
use App\Services\Booking\BookingSettingsService;
use App\Services\Booking\Exceptions\BookingException;
use App\Support\Booking\BookingAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BookingSettingController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly BookingSettingsService $settingsService,
        private readonly BookingAccess $access,
    ) {}

    public function edit(Request $request): View
    {
        $workspace = $this->currentWorkspace();
        $user = $request->user();
        abort_unless($user && $this->access->canManageSettings($user, $workspace), 403);

        $setting = $this->settingsService->getOrCreate($workspace);
        $services = BookingService::query()->where('workspace_id', $workspace->id)->orderBy('sort_order')->orderBy('id')->get();
        $branches = BookingBranch::query()->where('workspace_id', $workspace->id)->orderBy('sort_order')->orderBy('id')->get();
        $providers = BookingProvider::query()->where('workspace_id', $workspace->id)->with(['branch', 'user'])->orderBy('sort_order')->orderBy('id')->get();
        $blockedSlots = BookingBlockedSlot::query()
            ->where('workspace_id', $workspace->id)
            ->with(['branch', 'provider', 'service'])
            ->where('ends_at', '>=', now()->subDay())
            ->orderBy('starts_at')
            ->limit(50)
            ->get();
        $members = $workspace->users()->wherePivot('status', 'active')->orderBy('name')->get(['users.id', 'users.name', 'users.email']);

        return view('workspace.bookings.settings', [
            'setting' => $setting,
            'services' => $services,
            'branches' => $branches,
            'providers' => $providers,
            'blockedSlots' => $blockedSlots,
            'members' => $members,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $user = $request->user();
        abort_unless($user && $this->access->canManageSettings($user, $workspace), 403);

        $validated = $request->validate([
            'timezone' => ['required', 'string', 'timezone:all'],
            'slot_interval_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'default_duration_minutes' => ['required', 'integer', 'min:5', 'max:720'],
            'default_capacity_per_slot' => ['required', 'integer', 'min:1', 'max:1000'],
            'buffer_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'min_advance_minutes' => ['required', 'integer', 'min:0', 'max:43200'],
            'max_advance_days' => ['required', 'integer', 'min:1', 'max:365'],
            'reminder_lead_minutes' => ['required', 'integer', 'min:5', 'max:43200'],
            'working_hours' => ['nullable', 'array'],
            'holidays_raw' => ['nullable', 'array'],
            'custom_fields_raw' => ['nullable', 'array'],
        ]);

        $holidays = [];
        foreach ((array) ($validated['holidays_raw'] ?? []) as $row) {
            if (! is_array($row) || empty($row['date'])) {
                continue;
            }
            $holidays[] = [
                'date' => (string) $row['date'],
                'title' => (string) ($row['title'] ?? 'إجازة'),
                'recurring_yearly' => ! empty($row['recurring_yearly']),
            ];
        }

        $customFields = [];
        foreach ((array) ($validated['custom_fields_raw'] ?? []) as $row) {
            if (! is_array($row) || empty(trim((string) ($row['label'] ?? '')))) {
                continue;
            }
            $customFields[] = [
                'key' => (string) ($row['key'] ?? ''),
                'label' => (string) $row['label'],
                'type' => (string) ($row['type'] ?? 'text'),
                'required' => ! empty($row['required']),
                'options' => (string) ($row['options'] ?? ''),
            ];
        }

        $this->settingsService->updateSettings($workspace, [
            'enabled' => $request->boolean('enabled'),
            'timezone' => $validated['timezone'],
            'slot_interval_minutes' => (int) $validated['slot_interval_minutes'],
            'default_duration_minutes' => (int) $validated['default_duration_minutes'],
            'default_capacity_per_slot' => (int) $validated['default_capacity_per_slot'],
            'buffer_minutes' => (int) ($validated['buffer_minutes'] ?? 0),
            'min_advance_minutes' => (int) $validated['min_advance_minutes'],
            'max_advance_days' => (int) $validated['max_advance_days'],
            'allow_party_size' => $request->boolean('allow_party_size'),
            'require_staff' => $request->boolean('require_staff'),
            'require_branch' => $request->boolean('require_branch'),
            'auto_confirm' => $request->boolean('auto_confirm'),
            'working_hours' => $this->parseSubmittedWorkingHours($request),
            'holidays' => $holidays,
            'custom_fields_schema' => $customFields,
            'notifications_enabled' => $request->boolean('notifications_enabled'),
            'send_confirmation_message' => $request->boolean('send_confirmation_message'),
            'send_update_message' => $request->boolean('send_update_message'),
            'send_reschedule_message' => $request->boolean('send_reschedule_message'),
            'send_cancellation_message' => $request->boolean('send_cancellation_message'),
            'reminder_enabled' => $request->boolean('reminder_enabled'),
            'reminder_lead_minutes' => (int) $validated['reminder_lead_minutes'],
        ]);

        return redirect()
            ->route('workspace.bookings.settings.edit')
            ->with('status', 'تم حفظ إعدادات التوفر والحجوزات بنجاح.');
    }

    public function storeService(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $user = $request->user();
        abort_unless($user && $this->access->canManageSettings($user, $workspace), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            'capacity_per_slot' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'max_party_size' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $this->settingsService->saveService($workspace, [
            ...$validated,
            'requires_party_size' => $request->has('requires_party_size') ? $request->boolean('requires_party_size') : null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('status', 'تمت إضافة الخدمة / نوع الحجز بنجاح.');
    }

    public function updateService(Request $request, BookingService $service): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $user = $request->user();
        abort_unless($user && $this->access->canManageSettings($user, $workspace), 403);
        $this->assertSameWorkspace($service->workspace_id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'category' => ['nullable', 'string', 'max:100'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            'capacity_per_slot' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'max_party_size' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $this->settingsService->saveService($workspace, [
            ...$validated,
            'requires_party_size' => $request->boolean('requires_party_size'),
            'is_active' => $request->boolean('is_active'),
        ], $service);

        return back()->with('status', 'تم تحديث بيانات الخدمة بنجاح.');
    }

    public function destroyService(Request $request, BookingService $service): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $user = $request->user();
        abort_unless($user && $this->access->canManageSettings($user, $workspace), 403);
        $this->assertSameWorkspace($service->workspace_id);

        $service->delete();

        return back()->with('status', 'تم حذف الخدمة.');
    }

    public function storeBranch(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $user = $request->user();
        abort_unless($user && $this->access->canManageSettings($user, $workspace), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        $this->settingsService->saveBranch($workspace, [
            ...$validated,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('status', 'تمت إضافة الفرع بنجاح.');
    }

    public function destroyBranch(Request $request, BookingBranch $branch): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $user = $request->user();
        abort_unless($user && $this->access->canManageSettings($user, $workspace), 403);
        $this->assertSameWorkspace($branch->workspace_id);

        $branch->delete();

        return back()->with('status', 'تم حذف الفرع.');
    }

    public function storeProvider(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $user = $request->user();
        abort_unless($user && $this->access->canManageSettings($user, $workspace), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'role_label' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'capacity_per_slot' => ['nullable', 'integer', 'min:1', 'max:100'],
            'service_ids' => ['nullable', 'array'],
        ]);

        $this->settingsService->saveProvider($workspace, [
            ...$validated,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('status', 'تمت إضافة الموظف / مقدم الخدمة بنجاح.');
    }

    public function destroyProvider(Request $request, BookingProvider $provider): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $user = $request->user();
        abort_unless($user && $this->access->canManageSettings($user, $workspace), 403);
        $this->assertSameWorkspace($provider->workspace_id);

        $provider->delete();

        return back()->with('status', 'تم حذف مقدم الخدمة.');
    }

    public function storeBlockedSlot(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $user = $request->user();
        abort_unless($user && $this->access->canManageSettings($user, $workspace), 403);

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'branch_id' => ['nullable', 'integer'],
            'provider_id' => ['nullable', 'integer'],
            'service_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->settingsService->blockSlot($workspace, $validated, $user);
        } catch (BookingException $e) {
            return back()->withErrors(['blocked_slot' => $e->getMessage()]);
        }

        return back()->with('status', 'تم إغلاق الفترة المحددة بنجاح.');
    }

    public function destroyBlockedSlot(Request $request, BookingBlockedSlot $blockedSlot): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $user = $request->user();
        abort_unless($user && $this->access->canManageSettings($user, $workspace), 403);
        $this->assertSameWorkspace($blockedSlot->workspace_id);

        $blockedSlot->delete();

        return back()->with('status', 'تم إلغاء الإغلاق اليدوي للفترة.');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function parseSubmittedWorkingHours(Request $request): array
    {
        $submitted = (array) $request->input('working_hours', []);
        $result = [];
        foreach (array_keys(BookingSetting::defaultWorkingHours()) as $day) {
            $row = is_array($submitted[$day] ?? null) ? $submitted[$day] : [];
            $breaks = [];
            if (is_array($row['breaks'] ?? null)) {
                foreach ($row['breaks'] as $b) {
                    if (is_array($b) && ! empty($b['start']) && ! empty($b['end'])) {
                        $breaks[] = [
                            'start' => (string) $b['start'],
                            'end' => (string) $b['end'],
                            'label' => isset($b['label']) ? (string) $b['label'] : null,
                        ];
                    }
                }
            }
            $result[$day] = [
                'enabled' => ! empty($row['enabled']),
                'start' => (string) ($row['start'] ?? '09:00'),
                'end' => (string) ($row['end'] ?? '21:00'),
                'breaks' => $breaks,
            ];
        }

        return $result;
    }
}
