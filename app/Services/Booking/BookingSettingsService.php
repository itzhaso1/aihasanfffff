<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Models\BookingBlockedSlot;
use App\Models\BookingBranch;
use App\Models\BookingProvider;
use App\Models\BookingService;
use App\Models\BookingSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Money\Money;
use App\Support\Tenancy\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class BookingSettingsService
{
    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function getOrCreate(Workspace $workspace): BookingSetting
    {
        return $this->forWorkspaceId((int) $workspace->id);
    }

    public function forWorkspaceId(int $workspaceId): BookingSetting
    {
        return $this->workspaceContext->runForId($workspaceId, function () use ($workspaceId): BookingSetting {
            $setting = BookingSetting::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->first();

            if ($setting instanceof BookingSetting) {
                if (! is_array($setting->working_hours) || $setting->working_hours === []) {
                    $setting->forceFill(['working_hours' => BookingSetting::defaultWorkingHours()])->save();
                }

                return $setting;
            }

            return BookingSetting::withoutGlobalScopes()->create([
                'workspace_id' => $workspaceId,
                'enabled' => false,
                'timezone' => 'Asia/Riyadh',
                'slot_interval_minutes' => 30,
                'default_duration_minutes' => 30,
                'default_capacity_per_slot' => 1,
                'buffer_minutes' => 0,
                'min_advance_minutes' => 60,
                'max_advance_days' => 60,
                'allow_party_size' => false,
                'require_staff' => false,
                'require_branch' => false,
                'auto_confirm' => true,
                'working_hours' => BookingSetting::defaultWorkingHours(),
                'holidays' => [],
                'custom_fields_schema' => [],
                'notifications_enabled' => true,
                'send_confirmation_message' => true,
                'send_update_message' => true,
                'send_reschedule_message' => true,
                'send_cancellation_message' => true,
                'reminder_enabled' => true,
                'reminder_lead_minutes' => 120,
                'reminder_offsets_minutes' => [120],
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSettings(Workspace $workspace, array $data): BookingSetting
    {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $data): BookingSetting {
            $setting = $this->getOrCreate($workspace);

            $workingHours = $this->normalizeWorkingHours(
                $data['working_hours'] ?? $setting->working_hours ?? BookingSetting::defaultWorkingHours(),
            );
            $holidays = $this->normalizeHolidays($data['holidays'] ?? $setting->holidays ?? []);
            $customFields = $this->normalizeCustomFieldsSchema($data['custom_fields_schema'] ?? $setting->custom_fields_schema ?? []);
            $reminderLead = max(5, min(43200, (int) ($data['reminder_lead_minutes'] ?? $setting->reminder_lead_minutes ?? 120)));

            $setting->fill([
                'enabled' => (bool) ($data['enabled'] ?? $setting->enabled),
                'timezone' => trim((string) ($data['timezone'] ?? $setting->timezone ?: 'Asia/Riyadh')),
                'slot_interval_minutes' => max(5, min(480, (int) ($data['slot_interval_minutes'] ?? $setting->slot_interval_minutes))),
                'default_duration_minutes' => max(5, min(720, (int) ($data['default_duration_minutes'] ?? $setting->default_duration_minutes))),
                'default_capacity_per_slot' => max(1, min(1000, (int) ($data['default_capacity_per_slot'] ?? $setting->default_capacity_per_slot))),
                'buffer_minutes' => max(0, min(240, (int) ($data['buffer_minutes'] ?? $setting->buffer_minutes))),
                'min_advance_minutes' => max(0, min(43200, (int) ($data['min_advance_minutes'] ?? $setting->min_advance_minutes))),
                'max_advance_days' => max(1, min(365, (int) ($data['max_advance_days'] ?? $setting->max_advance_days))),
                'allow_party_size' => (bool) ($data['allow_party_size'] ?? $setting->allow_party_size),
                'require_staff' => (bool) ($data['require_staff'] ?? $setting->require_staff),
                'require_branch' => (bool) ($data['require_branch'] ?? $setting->require_branch),
                'auto_confirm' => (bool) ($data['auto_confirm'] ?? $setting->auto_confirm),
                'working_hours' => $workingHours,
                'holidays' => $holidays,
                'custom_fields_schema' => $customFields,
                'notifications_enabled' => (bool) ($data['notifications_enabled'] ?? $setting->notifications_enabled),
                'send_confirmation_message' => (bool) ($data['send_confirmation_message'] ?? $setting->send_confirmation_message),
                'send_update_message' => (bool) ($data['send_update_message'] ?? $setting->send_update_message),
                'send_reschedule_message' => (bool) ($data['send_reschedule_message'] ?? $setting->send_reschedule_message),
                'send_cancellation_message' => (bool) ($data['send_cancellation_message'] ?? $setting->send_cancellation_message),
                'reminder_enabled' => (bool) ($data['reminder_enabled'] ?? $setting->reminder_enabled),
                'reminder_lead_minutes' => $reminderLead,
                'reminder_offsets_minutes' => $data['reminder_offsets_minutes'] ?? [$reminderLead],
            ]);
            $setting->save();

            return $setting->fresh() ?? $setting;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveService(Workspace $workspace, array $data, ?BookingService $existing = null): BookingService
    {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $data, $existing): BookingService {
            $service = $existing ?? new BookingService(['workspace_id' => $workspace->id]);
            $name = trim((string) ($data['name'] ?? $service->name));
            $priceMinor = null;
            if (array_key_exists('price', $data) && $data['price'] !== null && $data['price'] !== '') {
                $priceMinor = Money::minor($data['price']);
            } elseif (array_key_exists('price_minor', $data) && $data['price_minor'] !== null) {
                $priceMinor = max(0, (int) $data['price_minor']);
            } elseif ($existing && ! array_key_exists('price', $data)) {
                $priceMinor = $existing->price_minor;
            }

            $service->fill([
                'workspace_id' => $workspace->id,
                'name' => $name,
                'slug' => Str::slug($name) ?: null,
                'category' => isset($data['category']) && trim((string) $data['category']) !== '' ? trim((string) $data['category']) : null,
                'description' => isset($data['description']) && trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null,
                'duration_minutes' => ! empty($data['duration_minutes']) ? max(5, min(720, (int) $data['duration_minutes'])) : null,
                'capacity_per_slot' => ! empty($data['capacity_per_slot']) ? max(1, min(1000, (int) $data['capacity_per_slot'])) : null,
                'price_minor' => $priceMinor,
                'currency' => strtoupper(trim((string) ($data['currency'] ?? $service->currency ?: 'SAR'))),
                'requires_party_size' => array_key_exists('requires_party_size', $data) && $data['requires_party_size'] !== null
                    ? (bool) $data['requires_party_size']
                    : null,
                'max_party_size' => ! empty($data['max_party_size']) ? max(1, (int) $data['max_party_size']) : null,
                'custom_fields' => is_array($data['custom_fields'] ?? null) ? $data['custom_fields'] : ($service->custom_fields ?? []),
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($existing ? $service->is_active : true),
                'sort_order' => (int) ($data['sort_order'] ?? $service->sort_order ?? 0),
            ]);
            $service->save();

            return $service->fresh() ?? $service;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveBranch(Workspace $workspace, array $data, ?BookingBranch $existing = null): BookingBranch
    {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $data, $existing): BookingBranch {
            $branch = $existing ?? new BookingBranch(['workspace_id' => $workspace->id]);
            $branch->fill([
                'workspace_id' => $workspace->id,
                'name' => trim((string) ($data['name'] ?? $branch->name)),
                'city' => isset($data['city']) && trim((string) $data['city']) !== '' ? trim((string) $data['city']) : null,
                'address' => isset($data['address']) && trim((string) $data['address']) !== '' ? trim((string) $data['address']) : null,
                'phone' => isset($data['phone']) && trim((string) $data['phone']) !== '' ? trim((string) $data['phone']) : null,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($existing ? $branch->is_active : true),
                'sort_order' => (int) ($data['sort_order'] ?? $branch->sort_order ?? 0),
            ]);
            $branch->save();

            return $branch->fresh() ?? $branch;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveProvider(Workspace $workspace, array $data, ?BookingProvider $existing = null): BookingProvider
    {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $data, $existing): BookingProvider {
            $provider = $existing ?? new BookingProvider(['workspace_id' => $workspace->id]);
            $branchId = ! empty($data['branch_id']) ? (int) $data['branch_id'] : null;
            if ($branchId !== null && ! BookingBranch::query()->where('workspace_id', $workspace->id)->whereKey($branchId)->exists()) {
                $branchId = null;
            }
            $serviceIds = is_array($data['service_ids'] ?? null)
                ? array_values(array_unique(array_filter(array_map('intval', $data['service_ids']))))
                : $provider->service_ids;

            $provider->fill([
                'workspace_id' => $workspace->id,
                'branch_id' => $branchId,
                'user_id' => ! empty($data['user_id']) ? (int) $data['user_id'] : null,
                'name' => trim((string) ($data['name'] ?? $provider->name)),
                'role_label' => isset($data['role_label']) && trim((string) $data['role_label']) !== '' ? trim((string) $data['role_label']) : null,
                'capacity_per_slot' => max(1, min(100, (int) ($data['capacity_per_slot'] ?? $provider->capacity_per_slot ?? 1))),
                'service_ids' => $serviceIds,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($existing ? $provider->is_active : true),
                'sort_order' => (int) ($data['sort_order'] ?? $provider->sort_order ?? 0),
            ]);
            $provider->save();

            return $provider->fresh() ?? $provider;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function blockSlot(Workspace $workspace, array $data, ?User $actor = null): BookingBlockedSlot
    {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $data, $actor): BookingBlockedSlot {
            $setting = $this->getOrCreate($workspace);
            $timezone = $setting->timezone ?: 'Asia/Riyadh';

            if (! empty($data['starts_at']) && ! empty($data['ends_at'])) {
                $start = CarbonImmutable::parse((string) $data['starts_at'], $timezone)->utc();
                $end = CarbonImmutable::parse((string) $data['ends_at'], $timezone)->utc();
            } else {
                $date = trim((string) ($data['date'] ?? ''));
                $startTime = trim((string) ($data['start_time'] ?? '00:00'));
                $endTime = trim((string) ($data['end_time'] ?? '23:59'));
                $start = CarbonImmutable::parse($date.' '.$startTime, $timezone)->utc();
                $end = CarbonImmutable::parse($date.' '.$endTime, $timezone)->utc();
            }

            if ($end->lessThanOrEqualTo($start)) {
                throw new Exceptions\BookingException('وقت نهاية الإغلاق يجب أن يكون بعد وقت البداية.');
            }

            return BookingBlockedSlot::query()->create([
                'workspace_id' => $workspace->id,
                'branch_id' => ! empty($data['branch_id']) ? (int) $data['branch_id'] : null,
                'provider_id' => ! empty($data['provider_id']) ? (int) $data['provider_id'] : null,
                'service_id' => ! empty($data['service_id']) ? (int) $data['service_id'] : null,
                'starts_at' => $start,
                'ends_at' => $end,
                'reason' => isset($data['reason']) && trim((string) $data['reason']) !== '' ? trim((string) $data['reason']) : null,
                'created_by_user_id' => $actor?->id,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, array{enabled:bool,start:string,end:string,breaks:list<array{start:string,end:string,label?:string}>}>
     */
    public function normalizeWorkingHours(mixed $raw): array
    {
        $defaults = BookingSetting::defaultWorkingHours();
        if (! is_array($raw)) {
            return $defaults;
        }

        $normalized = [];
        foreach ($defaults as $day => $default) {
            $row = is_array($raw[$day] ?? null) ? $raw[$day] : [];
            $breaks = [];
            if (is_array($row['breaks'] ?? null)) {
                foreach ($row['breaks'] as $break) {
                    if (! is_array($break)) {
                        continue;
                    }
                    $bStart = $this->sanitizeTime((string) ($break['start'] ?? ''), '');
                    $bEnd = $this->sanitizeTime((string) ($break['end'] ?? ''), '');
                    if ($bStart !== '' && $bEnd !== '' && $bEnd > $bStart) {
                        $breaks[] = array_filter([
                            'start' => $bStart,
                            'end' => $bEnd,
                            'label' => isset($break['label']) && trim((string) $break['label']) !== '' ? trim((string) $break['label']) : null,
                        ], static fn (mixed $v): bool => $v !== null);
                    }
                }
            }

            $normalized[$day] = [
                'enabled' => array_key_exists('enabled', $row) ? (bool) $row['enabled'] : $default['enabled'],
                'start' => $this->sanitizeTime((string) ($row['start'] ?? ''), $default['start']),
                'end' => $this->sanitizeTime((string) ($row['end'] ?? ''), $default['end']),
                'breaks' => $breaks,
            ];
        }

        return $normalized;
    }

    /**
     * @return list<array{date:string,title:string,recurring_yearly?:bool}>
     */
    public function normalizeHolidays(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $items = [];
        foreach ($raw as $entry) {
            if (is_string($entry) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($entry))) {
                $items[] = ['date' => trim($entry), 'title' => 'إجازة رسمية'];

                continue;
            }
            if (! is_array($entry)) {
                continue;
            }
            $date = trim((string) ($entry['date'] ?? ''));
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $items[] = [
                'date' => $date,
                'title' => trim((string) ($entry['title'] ?? '')) ?: 'إجازة',
                'recurring_yearly' => (bool) ($entry['recurring_yearly'] ?? false),
            ];
        }

        return $items;
    }

    /**
     * @return list<array{key:string,label:string,type:string,required:bool,options:list<string>}>
     */
    public function normalizeCustomFieldsSchema(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $schema = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $label = trim((string) ($entry['label'] ?? ''));
            $key = trim((string) ($entry['key'] ?? ''));
            if ($key === '' && $label !== '') {
                $key = Str::slug($label, '_') ?: 'field_'.count($schema);
            }
            if ($key === '' || $label === '') {
                continue;
            }
            $type = in_array((string) ($entry['type'] ?? 'text'), ['text', 'number', 'select', 'textarea'], true)
                ? (string) $entry['type']
                : 'text';
            $options = [];
            if (is_array($entry['options'] ?? null)) {
                $options = array_values(array_filter(array_map('trim', $entry['options']), static fn (string $v): bool => $v !== ''));
            } elseif (is_string($entry['options'] ?? null)) {
                $options = array_values(array_filter(array_map('trim', explode(',', $entry['options'])), static fn (string $v): bool => $v !== ''));
            }

            $schema[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => (bool) ($entry['required'] ?? false),
                'options' => $options,
            ];
        }

        return $schema;
    }

    private function sanitizeTime(string $value, string $fallback): string
    {
        $clean = trim($value);
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $clean, $m)) {
            return str_pad($m[1], 2, '0', STR_PAD_LEFT).':'.$m[2];
        }

        return $fallback;
    }
}
