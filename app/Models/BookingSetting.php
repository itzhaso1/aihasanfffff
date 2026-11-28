<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'workspace_id',
    'enabled',
    'timezone',
    'slot_interval_minutes',
    'default_duration_minutes',
    'default_capacity_per_slot',
    'buffer_minutes',
    'min_advance_minutes',
    'max_advance_days',
    'allow_party_size',
    'require_staff',
    'require_branch',
    'auto_confirm',
    'working_hours',
    'holidays',
    'custom_fields_schema',
    'notifications_enabled',
    'send_confirmation_message',
    'send_update_message',
    'send_reschedule_message',
    'send_cancellation_message',
    'reminder_enabled',
    'reminder_lead_minutes',
    'reminder_offsets_minutes',
    'metadata',
])]
class BookingSetting extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'slot_interval_minutes' => 'integer',
            'default_duration_minutes' => 'integer',
            'default_capacity_per_slot' => 'integer',
            'buffer_minutes' => 'integer',
            'min_advance_minutes' => 'integer',
            'max_advance_days' => 'integer',
            'allow_party_size' => 'boolean',
            'require_staff' => 'boolean',
            'require_branch' => 'boolean',
            'auto_confirm' => 'boolean',
            'working_hours' => 'array',
            'holidays' => 'array',
            'custom_fields_schema' => 'array',
            'notifications_enabled' => 'boolean',
            'send_confirmation_message' => 'boolean',
            'send_update_message' => 'boolean',
            'send_reschedule_message' => 'boolean',
            'send_cancellation_message' => 'boolean',
            'reminder_enabled' => 'boolean',
            'reminder_lead_minutes' => 'integer',
            'reminder_offsets_minutes' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * @return array<string, array{enabled:bool,start:string,end:string,breaks:list<array{start:string,end:string,label?:string}>}>
     */
    public static function defaultWorkingHours(): array
    {
        return [
            'sunday' => ['enabled' => true, 'start' => '09:00', 'end' => '21:00', 'breaks' => []],
            'monday' => ['enabled' => true, 'start' => '09:00', 'end' => '21:00', 'breaks' => []],
            'tuesday' => ['enabled' => true, 'start' => '09:00', 'end' => '21:00', 'breaks' => []],
            'wednesday' => ['enabled' => true, 'start' => '09:00', 'end' => '21:00', 'breaks' => []],
            'thursday' => ['enabled' => true, 'start' => '09:00', 'end' => '21:00', 'breaks' => []],
            'friday' => ['enabled' => false, 'start' => '16:00', 'end' => '22:00', 'breaks' => []],
            'saturday' => ['enabled' => true, 'start' => '10:00', 'end' => '21:00', 'breaks' => []],
        ];
    }

    /**
     * @return list<int>
     */
    public function resolvedReminderOffsets(): array
    {
        if (! $this->reminder_enabled) {
            return [];
        }

        $offsets = is_array($this->reminder_offsets_minutes) && $this->reminder_offsets_minutes !== []
            ? $this->reminder_offsets_minutes
            : [$this->reminder_lead_minutes ?: 120];

        $clean = [];
        foreach ($offsets as $offset) {
            $minutes = (int) $offset;
            if ($minutes >= 5 && $minutes <= 43200) {
                $clean[$minutes] = $minutes;
            }
        }

        if ($clean === []) {
            $fallback = max(5, (int) ($this->reminder_lead_minutes ?: 120));
            $clean[$fallback] = $fallback;
        }

        ksort($clean);

        return array_values($clean);
    }
}
