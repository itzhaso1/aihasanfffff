<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2.5 flex-wrap">
                    <h1 class="text-xl font-extrabold text-slate-900">Bookings Dashboard · لوحة إدارة الحجوزات</h1>
                    @if($setting->enabled)
                        <span class="hs-badge-success">نظام الحجز بالذكاء الاصطناعي مفعّل</span>
                    @else
                        <span class="hs-badge-warning">نظام الحجز معطّل حالياً</span>
                    @endif
                </div>
                <p class="mt-0.5 text-xs text-slate-500">متابعة وإدارة الحجوزات والمواعيد الواردة عبر واتساب وإنستغرام ومسنجر وتيليجرام أو المسجلة يدوياً.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($canManageSettings)
                    <a href="{{ route('workspace.bookings.settings.edit') }}" class="hs-btn-secondary text-xs">
                        إعدادات التوفر والحجوزات
                    </a>
                @endif
                @if($canManageBookings)
                    <a href="{{ route('workspace.bookings.create') }}" class="hs-btn-primary text-xs">
                        + حجز يدوي جديد
                    </a>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="space-y-5" dir="rtl">
        @include('partials.flash')

        {{-- KPI Cards --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            <a href="{{ route('workspace.bookings.index', ['scope' => 'today', 'view' => $viewMode]) }}" @class(['hs-stat-card p-4', 'border-[#06C2A4] bg-[#E8FAF6]/40' => $scope === 'today' && $status === ''])>
                <span class="text-xs font-medium text-slate-500">حجوزات اليوم</span>
                <p class="mt-1 text-2xl font-extrabold text-slate-900">{{ number_format($stats['today']) }}</p>
                <span class="text-[10px] text-slate-400">{{ $todayLocal }}</span>
            </a>

            <a href="{{ route('workspace.bookings.index', ['scope' => 'upcoming', 'view' => $viewMode]) }}" @class(['hs-stat-card p-4', 'border-[#06C2A4] bg-[#E8FAF6]/40' => $scope === 'upcoming' && $status === ''])>
                <span class="text-xs font-medium text-slate-500">الحجوزات القادمة</span>
                <p class="mt-1 text-2xl font-extrabold text-[#067E6B]">{{ number_format($stats['upcoming']) }}</p>
                <span class="text-[10px] text-slate-400">نشطة ومقبلة</span>
            </a>

            <a href="{{ route('workspace.bookings.index', ['status' => 'pending', 'view' => $viewMode]) }}" @class(['hs-stat-card p-4', 'border-amber-400 bg-amber-50/40' => $status === 'pending'])>
                <span class="text-xs font-medium text-amber-700">Pending · قيد الانتظار</span>
                <p class="mt-1 text-2xl font-extrabold text-amber-600">{{ number_format($stats['pending']) }}</p>
                <span class="text-[10px] text-amber-500">تنتظر التأكيد</span>
            </a>

            <a href="{{ route('workspace.bookings.index', ['status' => 'confirmed', 'view' => $viewMode]) }}" @class(['hs-stat-card p-4', 'border-emerald-400 bg-emerald-50/40' => $status === 'confirmed'])>
                <span class="text-xs font-medium text-emerald-700">Confirmed · مؤكدة</span>
                <p class="mt-1 text-2xl font-extrabold text-emerald-600">{{ number_format($stats['confirmed']) }}</p>
                <span class="text-[10px] text-emerald-500">معتمدة</span>
            </a>

            <a href="{{ route('workspace.bookings.index', ['status' => 'rescheduled', 'view' => $viewMode]) }}" @class(['hs-stat-card p-4', 'border-blue-400 bg-blue-50/40' => $status === 'rescheduled'])>
                <span class="text-xs font-medium text-blue-700">Rescheduled · مؤجلة</span>
                <p class="mt-1 text-2xl font-extrabold text-blue-600">{{ number_format($stats['rescheduled']) }}</p>
                <span class="text-[10px] text-blue-500">أُعيدت جدولتها</span>
            </a>

            <a href="{{ route('workspace.bookings.index', ['status' => 'cancelled', 'view' => $viewMode]) }}" @class(['hs-stat-card p-4', 'border-rose-400 bg-rose-50/40' => $status === 'cancelled'])>
                <span class="text-xs font-medium text-rose-700">Cancelled / No Show</span>
                <p class="mt-1 text-2xl font-extrabold text-rose-600">{{ number_format($stats['cancelled'] + $stats['no_show']) }}</p>
                <span class="text-[10px] text-slate-400">ملغي {{ $stats['cancelled'] }} · لم يحضر {{ $stats['no_show'] }}</span>
            </a>
        </div>

        {{-- View Mode Switcher & Scope Tabs --}}
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 rounded-2xl border border-slate-200/80 bg-white p-3 shadow-sm">
            <div class="flex flex-wrap items-center gap-1.5">
                @foreach(['all' => 'جميع الحجوزات', 'today' => 'اليوم', 'upcoming' => 'القادمة'] as $sKey => $sLabel)
                    <a href="{{ route('workspace.bookings.index', array_merge(request()->query(), ['scope' => $sKey, 'view' => $viewMode])) }}"
                       @class([
                           'rounded-xl px-3 py-1.5 text-xs font-bold transition',
                           'bg-[#E8FAF6] text-[#067E6B]' => $scope === $sKey,
                           'text-slate-600 hover:bg-slate-100' => $scope !== $sKey,
                       ])>{{ $sLabel }}</a>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                @foreach(['list' => 'القائمة', 'day' => 'تقويم يومي', 'week' => 'تقويم أسبوعي', 'month' => 'تقويم شهري'] as $vKey => $vLabel)
                    <a href="{{ route('workspace.bookings.index', array_merge(request()->query(), ['view' => $vKey])) }}"
                       @class([
                           'rounded-xl px-3 py-1.5 text-xs font-bold transition',
                           'bg-[#06C2A4] text-white shadow-sm' => $viewMode === $vKey,
                           'bg-slate-100 text-slate-600 hover:bg-slate-200' => $viewMode !== $vKey,
                       ])>{{ $vLabel }}</a>
                @endforeach
            </div>
        </div>

        {{-- Search & Filters --}}
        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm">
            <form method="GET" action="{{ route('workspace.bookings.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-2.5">
                <input type="hidden" name="view" value="{{ $viewMode }}">
                <input type="hidden" name="scope" value="{{ $scope }}">

                <div class="sm:col-span-2">
                    <input type="text" name="search" value="{{ $search }}" placeholder="بحث بالاسم، الجوال، أو رقم الحجز (BK-...)..." class="hs-input text-xs py-2">
                </div>

                <input type="date" name="date" value="{{ $date }}" class="hs-input text-xs py-2" title="تصفية بالتاريخ">

                <select name="service_id" class="hs-select text-xs py-2">
                    <option value="">كل الخدمات / الأنواع</option>
                    @foreach($services as $svc)
                        <option value="{{ $svc->id }}" @selected($serviceId === (int) $svc->id)>{{ $svc->name }}</option>
                    @endforeach
                </select>

                <select name="branch_id" class="hs-select text-xs py-2">
                    <option value="">كل الفروع</option>
                    @foreach($branches as $br)
                        <option value="{{ $br->id }}" @selected($branchId === (int) $br->id)>{{ $br->name }}</option>
                    @endforeach
                </select>

                <div class="flex gap-2">
                    <select name="status" class="hs-select text-xs py-2">
                        <option value="">كل الحالات</option>
                        @foreach(\App\Enums\BookingStatus::cases() as $st)
                            <option value="{{ $st->value }}" @selected($status === $st->value)>{{ $st->badgeLabel() }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="hs-btn-primary text-xs py-2 px-3 shrink-0">تصفية</button>
                    @if($search !== '' || $status !== '' || $date !== '' || $serviceId || $branchId)
                        <a href="{{ route('workspace.bookings.index', ['view' => $viewMode]) }}" class="hs-btn-secondary text-xs py-2 px-3 shrink-0">مسح</a>
                    @endif
                </div>
            </form>
        </div>

        {{-- LIST VIEW --}}
        @if($viewMode === 'list')
            @if($bookingsList->isEmpty())
                <div class="hs-empty">
                    <div class="hs-empty-icon">
                        <svg class="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                    </div>
                    <h3 class="hs-empty-title">لا توجد حجوزات مطابقة</h3>
                    <p class="hs-empty-text">يمكن للذكاء الاصطناعي استقبال الحجوزات تلقائياً من جميع القنوات عند تفعيل النظام، أو يمكنك إضافة حجز يدوي.</p>
                </div>
            @else
                {{-- Mobile Cards --}}
                <div class="grid grid-cols-1 gap-3 lg:hidden">
                    @foreach($bookingsList as $booking)
                        <div class="hs-card p-4 space-y-3">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <a href="{{ route('workspace.bookings.show', $booking) }}" class="font-mono text-xs font-extrabold text-[#067E6B]">{{ $booking->booking_reference }}</a>
                                    <h3 class="text-sm font-bold text-slate-900 mt-0.5">{{ $booking->customer_name }}</h3>
                                    @if($booking->customer_phone)
                                        <span class="text-xs font-mono text-slate-500" dir="ltr">{{ $booking->customer_phone }}</span>
                                    @endif
                                </div>
                                @include('workspace.bookings.partials.status-badge', ['status' => $booking->status])
                            </div>
                            <div class="grid grid-cols-2 gap-2 rounded-xl bg-slate-50 p-2.5 text-xs">
                                <div><span class="text-slate-400">الخدمة:</span> <strong class="text-slate-800">{{ $booking->service_name }}</strong></div>
                                <div><span class="text-slate-400">الموعد:</span> <strong class="text-slate-800">{{ $booking->booking_date?->format('Y-m-d') }} · {{ $booking->start_time }}</strong></div>
                                @if($booking->branch?->name)<div><span class="text-slate-400">الفرع:</span> {{ $booking->branch->name }}</div>@endif
                                @if($booking->provider?->name)<div><span class="text-slate-400">المقدم:</span> {{ $booking->provider->name }}</div>@endif
                            </div>
                            <div class="flex items-center justify-between gap-2 pt-1">
                                <span class="text-[11px] text-slate-400">المصدر: {{ $booking->source }} ({{ $booking->channel }})</span>
                                <a href="{{ route('workspace.bookings.show', $booking) }}" class="hs-btn-secondary text-xs py-1.5 px-3">التفاصيل والإجراءات</a>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Desktop Table --}}
                <div class="hidden lg:block hs-table-wrap">
                    <table class="hs-table">
                        <thead>
                            <tr>
                                <th>المرجع</th>
                                <th>العميل</th>
                                <th>الخدمة / الحجز</th>
                                <th>التاريخ والوقت</th>
                                <th>الفرع / المقدم</th>
                                <th>المصدر</th>
                                <th>الحالة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($bookingsList as $booking)
                                <tr>
                                    <td class="font-mono text-xs font-extrabold text-[#067E6B]">
                                        <a href="{{ route('workspace.bookings.show', $booking) }}" class="hover:underline">{{ $booking->booking_reference }}</a>
                                    </td>
                                    <td>
                                        <div class="font-bold text-slate-900">{{ $booking->customer_name }}</div>
                                        @if($booking->customer_phone)
                                            <div class="font-mono text-[11px] text-slate-500" dir="ltr">{{ $booking->customer_phone }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="font-semibold text-slate-800">{{ $booking->service_name }}</div>
                                        @if($booking->party_size)
                                            <span class="text-[11px] text-slate-500">{{ $booking->party_size }} أشخاص</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="font-bold text-slate-800">{{ $booking->booking_date?->format('Y-m-d') }}</div>
                                        <div class="text-xs font-mono text-slate-500">{{ $booking->start_time }} - {{ $booking->end_time }}</div>
                                    </td>
                                    <td class="text-xs">
                                        @if($booking->branch?->name)<div>{{ $booking->branch->name }}</div>@endif
                                        @if($booking->provider?->name)<div class="text-slate-500">{{ $booking->provider->name }}</div>@endif
                                        @if(! $booking->branch && ! $booking->provider)<span class="text-slate-400">—</span>@endif
                                    </td>
                                    <td>
                                        <span class="hs-badge-neutral text-[10px]">{{ $booking->source === 'ai' ? 'AI' : 'يدوي' }} · {{ $booking->channel }}</span>
                                    </td>
                                    <td>@include('workspace.bookings.partials.status-badge', ['status' => $booking->status])</td>
                                    <td>
                                        <div class="flex items-center gap-1.5">
                                            <a href="{{ route('workspace.bookings.show', $booking) }}" class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">إدارة</a>
                                            @if($canManageBookings && $booking->status === 'pending')
                                                <form method="POST" action="{{ route('workspace.bookings.status.update', $booking) }}">
                                                    @csrf @method('PATCH')
                                                    <input type="hidden" name="status" value="confirmed">
                                                    <button type="submit" class="rounded-lg bg-emerald-50 border border-emerald-200 px-2.5 py-1 text-xs font-bold text-emerald-700 hover:bg-emerald-100">تأكيد</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div>{{ $bookingsList->links() }}</div>
            @endif

        {{-- DAY CALENDAR --}}
        @elseif($viewMode === 'day')
            @php
                $prevDay = $anchorDate->subDay()->format('Y-m-d');
                $nextDay = $anchorDate->addDay()->format('Y-m-d');
                $dayStr = $anchorDate->format('Y-m-d');
                $dayBookings = $calendarData['bookings_by_date'][$dayStr] ?? collect();
            @endphp
            <div class="hs-card space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3">
                    <h2 class="text-sm font-extrabold text-slate-900">التقويم اليومي — {{ $anchorDate->translatedFormat('l') }} ({{ $dayStr }})</h2>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('workspace.bookings.index', ['view' => 'day', 'anchor_date' => $prevDay]) }}" class="hs-btn-secondary text-xs py-1.5 px-3">اليوم السابق</a>
                        <a href="{{ route('workspace.bookings.index', ['view' => 'day', 'anchor_date' => $todayLocal]) }}" class="hs-btn-subtle text-xs py-1.5 px-3">اليوم</a>
                        <a href="{{ route('workspace.bookings.index', ['view' => 'day', 'anchor_date' => $nextDay]) }}" class="hs-btn-secondary text-xs py-1.5 px-3">اليوم التالي</a>
                    </div>
                </div>

                @if(($calendarData['blocked_slots'] ?? collect())->isNotEmpty())
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900 space-y-1">
                        <div class="font-bold">أوقات مغلقة يدوياً في هذا اليوم:</div>
                        @foreach($calendarData['blocked_slots'] as $bl)
                            <div>• من {{ $bl->starts_at?->setTimezone($setting->timezone ?: 'Asia/Riyadh')->format('H:i') }} إلى {{ $bl->ends_at?->setTimezone($setting->timezone ?: 'Asia/Riyadh')->format('H:i') }} {{ $bl->reason ? '('.$bl->reason.')' : '' }}</div>
                        @endforeach
                    </div>
                @endif

                <div class="space-y-2.5">
                    @forelse($dayBookings as $b)
                        <a href="{{ route('workspace.bookings.show', $b) }}" class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 rounded-2xl border border-slate-200/80 bg-slate-50/70 p-3.5 hover:border-[#06C2A4] transition">
                            <div class="flex items-center gap-3">
                                <div class="rounded-xl bg-[#E8FAF6] px-3 py-2 text-center font-mono text-xs font-extrabold text-[#067E6B]">
                                    <div>{{ $b->start_time }}</div>
                                    <div class="text-[10px] opacity-70">{{ $b->end_time }}</div>
                                </div>
                                <div>
                                    <div class="font-bold text-slate-900 text-sm">{{ $b->customer_name }} <span class="font-mono text-xs text-slate-400">#{{ $b->booking_reference }}</span></div>
                                    <div class="text-xs text-slate-600">{{ $b->service_name }} @if($b->provider?->name) · {{ $b->provider->name }} @endif @if($b->branch?->name) · {{ $b->branch->name }} @endif</div>
                                </div>
                            </div>
                            @include('workspace.bookings.partials.status-badge', ['status' => $b->status])
                        </a>
                    @empty
                        <p class="text-xs text-slate-400 py-6 text-center">لا توجد حجوزات مسجلة في هذا اليوم.</p>
                    @endforelse
                </div>
            </div>

        {{-- WEEK CALENDAR --}}
        @elseif($viewMode === 'week')
            @php
                $rangeStart = $calendarData['range_start'];
                $prevWeek = $rangeStart->subWeek()->format('Y-m-d');
                $nextWeek = $rangeStart->addWeek()->format('Y-m-d');
            @endphp
            <div class="hs-card space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3">
                    <h2 class="text-sm font-extrabold text-slate-900">التقويم الأسبوعي ({{ $rangeStart->format('Y-m-d') }} — {{ $rangeStart->addDays(6)->format('Y-m-d') }})</h2>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('workspace.bookings.index', ['view' => 'week', 'anchor_date' => $prevWeek]) }}" class="hs-btn-secondary text-xs py-1.5 px-3">الأسبوع السابق</a>
                        <a href="{{ route('workspace.bookings.index', ['view' => 'week', 'anchor_date' => $todayLocal]) }}" class="hs-btn-subtle text-xs py-1.5 px-3">الأسبوع الحالي</a>
                        <a href="{{ route('workspace.bookings.index', ['view' => 'week', 'anchor_date' => $nextWeek]) }}" class="hs-btn-secondary text-xs py-1.5 px-3">الأسبوع التالي</a>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-7 gap-2.5">
                    @for($i = 0; $i < 7; $i++)
                        @php
                            $d = $rangeStart->addDays($i);
                            $dStr = $d->format('Y-m-d');
                            $items = $calendarData['bookings_by_date'][$dStr] ?? collect();
                        @endphp
                        <div @class(['rounded-2xl border p-2.5 min-h-[160px] space-y-2', 'border-[#06C2A4] bg-[#E8FAF6]/20' => $dStr === $todayLocal, 'border-slate-200/80 bg-slate-50/40' => $dStr !== $todayLocal])>
                            <a href="{{ route('workspace.bookings.index', ['view' => 'day', 'anchor_date' => $dStr]) }}" class="flex items-center justify-between border-b border-slate-200/60 pb-1.5 text-xs font-bold text-slate-800 hover:text-[#067E6B]">
                                <span>{{ $d->translatedFormat('D') }}</span>
                                <span class="font-mono text-[11px]">{{ $d->format('m/d') }}</span>
                            </a>
                            <div class="space-y-1.5">
                                @forelse($items as $b)
                                    <a href="{{ route('workspace.bookings.show', $b) }}" class="block rounded-xl border border-slate-200/80 bg-white p-2 text-[11px] hover:border-[#06C2A4]">
                                        <div class="font-mono font-bold text-[#067E6B]">{{ $b->start_time }}</div>
                                        <div class="font-bold text-slate-800 truncate">{{ $b->customer_name }}</div>
                                        <div class="text-slate-500 truncate">{{ $b->service_name }}</div>
                                    </a>
                                @empty
                                    <div class="text-[11px] text-slate-300 text-center py-4">—</div>
                                @endforelse
                            </div>
                        </div>
                    @endfor
                </div>
            </div>

        {{-- MONTH CALENDAR --}}
        @else
            @php
                $monthStart = $anchorDate->startOfMonth();
                $monthEnd = $anchorDate->endOfMonth();
                $gridStart = $monthStart->startOfWeek(\Carbon\CarbonInterface::SUNDAY);
                $gridEnd = $monthEnd->endOfWeek(\Carbon\CarbonInterface::SATURDAY);
                $prevMonth = $monthStart->subMonth()->format('Y-m-d');
                $nextMonth = $monthStart->addMonth()->format('Y-m-d');
                $holidaySet = collect($setting->holidays ?? [])->pluck('title', 'date')->all();
            @endphp
            <div class="hs-card space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3">
                    <h2 class="text-sm font-extrabold text-slate-900">التقويم الشهري — {{ $monthStart->format('Y-m') }}</h2>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('workspace.bookings.index', ['view' => 'month', 'anchor_date' => $prevMonth]) }}" class="hs-btn-secondary text-xs py-1.5 px-3">الشهر السابق</a>
                        <a href="{{ route('workspace.bookings.index', ['view' => 'month', 'anchor_date' => $todayLocal]) }}" class="hs-btn-subtle text-xs py-1.5 px-3">الشهر الحالي</a>
                        <a href="{{ route('workspace.bookings.index', ['view' => 'month', 'anchor_date' => $nextMonth]) }}" class="hs-btn-secondary text-xs py-1.5 px-3">الشهر التالي</a>
                    </div>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-7 gap-2">
                    @php $cursorDay = $gridStart; @endphp
                    @while($cursorDay->lessThanOrEqualTo($gridEnd))
                        @php
                            $cStr = $cursorDay->format('Y-m-d');
                            $inMonth = $cursorDay->month === $monthStart->month;
                            $dayItems = $calendarData['bookings_by_date'][$cStr] ?? collect();
                            $holidayTitle = $holidaySet[$cStr] ?? null;
                        @endphp
                        <div @class([
                            'rounded-xl border p-2 min-h-[100px] space-y-1',
                            'bg-white border-slate-200/80' => $inMonth,
                            'bg-slate-50/50 border-slate-100 opacity-60' => ! $inMonth,
                            'ring-2 ring-[#06C2A4]/50' => $cStr === $todayLocal,
                        ])>
                            <div class="flex items-center justify-between text-xs">
                                <a href="{{ route('workspace.bookings.index', ['view' => 'day', 'anchor_date' => $cStr]) }}" class="font-mono font-bold text-slate-700 hover:text-[#067E6B]">{{ $cursorDay->day }}</a>
                                @if($dayItems->count() > 0)
                                    <span class="rounded-full bg-[#E8FAF6] px-1.5 py-0.5 text-[10px] font-extrabold text-[#067E6B]">{{ $dayItems->count() }}</span>
                                @endif
                            </div>
                            @if($holidayTitle)
                                <div class="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800 truncate">{{ $holidayTitle }}</div>
                            @endif
                            @foreach($dayItems->take(3) as $b)
                                <a href="{{ route('workspace.bookings.show', $b) }}" class="block rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-700 truncate hover:bg-[#E8FAF6]">
                                    {{ $b->start_time }} · {{ $b->customer_name }}
                                </a>
                            @endforeach
                        </div>
                        @php $cursorDay = $cursorDay->addDay(); @endphp
                    @endwhile
                </div>
            </div>
        @endif
    </div>
</x-app-layout>
