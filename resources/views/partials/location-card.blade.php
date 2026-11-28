@php
    $status = (string) ($location['verification_status'] ?? 'pending_confirmation');
    $statusStyles = match ($status) {
        'confirmed' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
        'needs_adjustment' => 'bg-rose-100 text-rose-800 border-rose-200',
        default => 'bg-amber-100 text-amber-800 border-amber-200',
    };
    $statusLabel = (string) ($location['verification_label'] ?? match ($status) {
        'confirmed' => 'موقع مؤكد',
        'needs_adjustment' => 'يحتاج مراجعة / تعديل',
        default => 'بانتظار التأكيد',
    });
    $sourceLabel = (string) ($location['source_label'] ?? ($location['source'] ?? 'GPS'));
    $formattedAddress = (string) ($location['formatted_address'] ?? '');
    $areaParts = array_values(array_filter([
        $location['city'] ?? null,
        $location['district'] ?? null,
        $location['street'] ?? null,
    ]));
    $mapsUrl = (string) ($location['google_maps_url'] ?? (
        isset($location['latitude'], $location['longitude'])
            ? 'https://www.google.com/maps?q='.$location['latitude'].','.$location['longitude']
            : '#'
    ));
    $locationId = $location['id'] ?? null;
    $compact = (bool) ($compact ?? false);
    $allowVerify = (bool) ($allowVerify ?? false);
@endphp

<div class="rounded-2xl border border-[#BEE9DF] bg-gradient-to-br from-[#F4FBF9] to-white p-3 text-right shadow-2xs" data-location-card="1" @if($locationId) data-location-id="{{ $locationId }}" @endif>
    <div class="flex flex-wrap items-center justify-between gap-2 mb-1.5">
        <span class="inline-flex items-center gap-1.5 text-xs font-bold text-[#067E6B]">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            <span>موقع التوصيل</span>
        </span>
        <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-bold {{ $statusStyles }}">
            {{ $statusLabel }}
        </span>
    </div>

    @if($formattedAddress !== '')
        <p class="text-xs font-semibold text-slate-800 leading-relaxed">{{ $formattedAddress }}</p>
    @endif

    @if($areaParts !== [] && ! $compact)
        <p class="mt-0.5 text-[11px] text-slate-500">{{ implode(' · ', $areaParts) }}</p>
    @endif

    @if(! empty($location['customer_notes']))
        <div class="mt-1.5 rounded-xl bg-white/80 border border-slate-200/80 px-2.5 py-1.5 text-[11px] text-slate-700">
            <span class="font-bold text-slate-500">ملاحظة:</span> {{ $location['customer_notes'] }}
        </div>
    @endif

    <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-[10.5px] text-slate-500">
        <div class="flex flex-wrap items-center gap-1.5">
            <span class="rounded-lg bg-white border border-slate-200 px-2 py-0.5 font-medium text-slate-600">{{ $sourceLabel }}</span>
            @if(isset($location['accuracy_meters']) && $location['accuracy_meters'] !== null)
                <span class="rounded-lg bg-white border border-slate-200 px-2 py-0.5 font-mono" dir="ltr">±{{ $location['accuracy_meters'] }}m</span>
            @endif
        </div>
        @if($mapsUrl !== '#')
            <a href="{{ $mapsUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 rounded-xl bg-[#067E6B] px-2.5 py-1 text-[11px] font-bold text-white transition hover:bg-[#056153]">
                <span>فتح في الخريطة</span>
                <span>↗</span>
            </a>
        @endif
    </div>

    @if($allowVerify && $locationId && $status !== 'confirmed' && \Illuminate\Support\Facades\Route::has('workspace.locations.verification.update'))
        <div class="mt-2 pt-2 border-t border-slate-200/70 flex items-center gap-2">
            <form method="POST" action="{{ route('workspace.locations.verification.update', $locationId) }}">
                @csrf @method('PATCH')
                <input type="hidden" name="verification_status" value="confirmed">
                <button type="submit" class="rounded-lg bg-emerald-600 px-2.5 py-1 text-[10.5px] font-bold text-white hover:bg-emerald-700 transition">تأكيد الموقع</button>
            </form>
            @if($status !== 'needs_adjustment')
                <form method="POST" action="{{ route('workspace.locations.verification.update', $locationId) }}">
                    @csrf @method('PATCH')
                    <input type="hidden" name="verification_status" value="needs_adjustment">
                    <button type="submit" class="rounded-lg bg-amber-100 px-2.5 py-1 text-[10.5px] font-bold text-amber-800 hover:bg-amber-200 transition">طلب تعديل</button>
                </form>
            @endif
        </div>
    @endif
</div>
