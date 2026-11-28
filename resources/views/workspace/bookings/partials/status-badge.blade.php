@php
    $statusValue = $status instanceof \App\Enums\BookingStatus ? $status->value : (string) $status;
    [$badgeClass, $label] = match ($statusValue) {
        'pending' => ['hs-badge-warning', 'Pending · قيد الانتظار'],
        'confirmed' => ['hs-badge-success', 'Confirmed · مؤكد'],
        'rescheduled' => ['hs-badge-info', 'Rescheduled · أُعيد جدولته'],
        'cancelled' => ['hs-badge-danger', 'Cancelled · ملغي'],
        'completed' => ['hs-badge-brand', 'Completed · مكتمل'],
        'no_show' => ['hs-badge-neutral', 'No Show · لم يحضر'],
        default => ['hs-badge-neutral', $statusValue],
    };
@endphp
<span class="{{ $badgeClass }} whitespace-nowrap">{{ $label }}</span>
