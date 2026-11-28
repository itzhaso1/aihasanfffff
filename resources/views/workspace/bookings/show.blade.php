<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3 flex-wrap">
                <a href="{{ route('workspace.bookings.index') }}" class="hs-btn-secondary text-xs py-1.5 px-3">← لوحة الحجوزات</a>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-xl font-extrabold text-slate-900 font-mono">{{ $booking->booking_reference }}</h1>
                        @include('workspace.bookings.partials.status-badge', ['status' => $booking->status])
                    </div>
                    <p class="text-xs text-slate-500 mt-0.5">المصدر: {{ $booking->source }} · القناة: {{ $booking->channel }} · أنشئ {{ $booking->created_at?->format('Y-m-d H:i') }}</p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if($booking->conversation)
                    <a href="{{ route('workspace.conversations.index', ['conversation' => $booking->conversation->id]) }}" class="hs-btn-secondary text-xs">فتح المحادثة</a>
                @endif
                @if($booking->customer)
                    <a href="{{ route('workspace.customers.show', $booking->customer) }}" class="hs-btn-secondary text-xs">ملف العميل</a>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="space-y-5" dir="rtl">
        @include('partials.flash')
        @if($errors->any())
            <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-xs text-rose-800 space-y-1">
                @foreach($errors->all() as $err)<div>• {{ $err }}</div>@endforeach
            </div>
        @endif

        {{-- Summary & Quick Status Bar --}}
        <div class="hs-card space-y-4">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs">
                <div><span class="text-slate-400 block">العميل</span><strong class="text-sm text-slate-900">{{ $booking->customer_name }}</strong>@if($booking->customer_phone)<div class="font-mono text-slate-500" dir="ltr">{{ $booking->customer_phone }}</div>@endif</div>
                <div><span class="text-slate-400 block">الخدمة / الحجز</span><strong class="text-sm text-slate-900">{{ $booking->service_name }}</strong>@if($booking->party_size)<div>{{ $booking->party_size }} أشخاص</div>@endif</div>
                <div><span class="text-slate-400 block">التاريخ والوقت</span><strong class="text-sm text-[#067E6B] font-mono">{{ $booking->booking_date?->format('Y-m-d') }}</strong><div class="font-mono text-slate-700">{{ $booking->start_time }} - {{ $booking->end_time }} ({{ $booking->duration_minutes }} د)</div></div>
                <div><span class="text-slate-400 block">الفرع / المقدم</span><strong class="text-sm text-slate-800">{{ $booking->branch?->name ?? '—' }}</strong><div class="text-slate-600">{{ $booking->provider?->name ?? '—' }}</div></div>
            </div>

            @if($canManageBookings || $canCancelBooking)
                <div class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
                    @if($canManageBookings)
                        @foreach(['confirmed' => 'تأكيد الحجز (Confirm)', 'completed' => 'إكمال (Completed)', 'no_show' => 'لم يحضر (No Show)'] as $stVal => $stBtn)
                            @if($booking->status !== $stVal)
                                <form method="POST" action="{{ route('workspace.bookings.status.update', $booking) }}">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="status" value="{{ $stVal }}">
                                    <input type="hidden" name="notify_customer" value="1">
                                    <button type="submit" class="hs-btn-secondary text-xs py-1.5 px-3">{{ $stBtn }}</button>
                                </form>
                            @endif
                        @endforeach

                        @if($booking->conversation_id)
                            <form method="POST" action="{{ route('workspace.bookings.remind', $booking) }}">
                                @csrf
                                <button type="submit" class="hs-btn-primary text-xs py-1.5 px-3">إرسال تذكير الآن عبر المحادثة</button>
                            </form>
                        @endif
                    @endif

                    @if($canCancelBooking && $booking->status !== 'cancelled')
                        <form method="POST" action="{{ route('workspace.bookings.status.update', $booking) }}" class="flex items-center gap-1.5 ms-auto">
                            @csrf @method('PATCH')
                            <input type="hidden" name="status" value="cancelled">
                            <input type="hidden" name="notify_customer" value="1">
                            <input type="text" name="reason" placeholder="سبب الإلغاء (اختياري)" class="hs-input text-xs py-1.5 w-44">
                            <button type="submit" class="hs-btn-danger text-xs py-1.5 px-3">إلغاء الحجز</button>
                        </form>
                    @endif
                </div>
            @endif
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            {{-- Reschedule Form --}}
            @if($canManageBookings)
                <form method="POST" action="{{ route('workspace.bookings.reschedule', $booking) }}" class="hs-card space-y-4">
                    @csrf
                    <h2 class="text-sm font-extrabold text-slate-900">تغيير الموعد / تأجيل الحجز (Reschedule)</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">التاريخ الجديد</label>
                            <input type="date" name="booking_date" required value="{{ $booking->booking_date?->format('Y-m-d') }}" class="hs-input text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">وقت البداية الجديد</label>
                            <input type="time" name="start_time" required value="{{ $booking->start_time }}" class="hs-input text-sm">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">الفرع</label>
                            <select name="branch_id" class="hs-select text-sm">
                                <option value="">بدون تغيير / غير محدد</option>
                                @foreach($branches as $br)
                                    <option value="{{ $br->id }}" @selected($booking->branch_id === $br->id)>{{ $br->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">مقدم الخدمة</label>
                            <select name="provider_id" class="hs-select text-sm">
                                <option value="">تعيين تلقائي للمتاح</option>
                                @foreach($providers as $pr)
                                    <option value="{{ $pr->id }}" @selected($booking->provider_id === $pr->id)>{{ $pr->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">سبب التأجيل (اختياري)</label>
                        <input type="text" name="reason" class="hs-input text-sm" placeholder="طلب العميل تأجيل الموعد...">
                    </div>
                    <div class="flex flex-wrap items-center gap-3 text-xs">
                        <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="override_rules" value="1" class="rounded"><span>تجاوز قيود المدة المسبقة</span></label>
                        <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="notify_customer" value="1" checked class="rounded"><span>إشعار العميل بالموعد الجديد</span></label>
                    </div>
                    <button type="submit" class="hs-btn-primary text-xs">تأجيل وتحديث الموعد</button>
                </form>

                {{-- Update Details Form --}}
                <form method="POST" action="{{ route('workspace.bookings.update', $booking) }}" class="hs-card space-y-4">
                    @csrf @method('PUT')
                    <h2 class="text-sm font-extrabold text-slate-900">تعديل بيانات الحجز</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">اسم العميل</label>
                            <input type="text" name="customer_name" required value="{{ $booking->customer_name }}" class="hs-input text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">رقم الجوال</label>
                            <input type="text" name="customer_phone" value="{{ $booking->customer_phone }}" class="hs-input text-sm font-mono" dir="ltr">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">الخدمة / النوع</label>
                            <select name="service_id" class="hs-select text-sm">
                                <option value="">الاسم المخصص بالأسفل</option>
                                @foreach($services as $svc)
                                    <option value="{{ $svc->id }}" @selected($booking->service_id === $svc->id)>{{ $svc->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">عدد الأشخاص</label>
                            <input type="number" name="party_size" min="1" value="{{ $booking->party_size }}" class="hs-input text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">ملاحظات العميل</label>
                        <textarea name="customer_notes" rows="2" class="hs-input text-sm">{{ $booking->customer_notes }}</textarea>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">ملاحظات داخلية</label>
                        <textarea name="internal_notes" rows="2" class="hs-input text-sm">{{ $booking->internal_notes }}</textarea>
                    </div>
                    <label class="inline-flex items-center gap-1.5 text-xs"><input type="checkbox" name="notify_customer" value="1" checked class="rounded"><span>إرسال إشعار تعديل للعميل</span></label>
                    <div><button type="submit" class="hs-btn-secondary text-xs">حفظ التعديلات</button></div>
                </form>
            @endif
        </div>

        {{-- Custom Fields + Revisions + Reminders --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
            <div class="hs-card space-y-2">
                <h3 class="text-sm font-extrabold text-slate-900">معلومات إضافية مرنة</h3>
                @if(! empty($booking->custom_fields))
                    <dl class="space-y-1.5 text-xs">
                        @foreach($booking->custom_fields as $k => $v)
                            <div class="flex justify-between rounded-lg bg-slate-50 px-3 py-1.5"><dt class="text-slate-500">{{ $k }}</dt><dd class="font-bold text-slate-800">{{ $v }}</dd></div>
                        @endforeach
                    </dl>
                @else
                    <p class="text-xs text-slate-400">لا توجد حقول إضافية مسجلة.</p>
                @endif
            </div>

            <div class="hs-card space-y-2">
                <h3 class="text-sm font-extrabold text-slate-900">سجل التعديلات والحالات</h3>
                <div class="space-y-2 text-xs max-h-60 overflow-y-auto">
                    @foreach($booking->revisions as $rev)
                        <div class="rounded-lg bg-slate-50 p-2 border border-slate-100">
                            <div class="flex justify-between font-bold text-slate-800"><span>{{ $rev->event_type }}</span><span class="font-mono text-[10px] text-slate-400">{{ $rev->created_at?->format('Y-m-d H:i') }}</span></div>
                            <div class="text-[11px] text-slate-500">بواسطة: {{ $rev->actor_type }} @if($rev->new_status) · الحالة: {{ $rev->new_status }} @endif</div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="hs-card space-y-2">
                <h3 class="text-sm font-extrabold text-slate-900">سجل الإشعارات والتذكيرات</h3>
                <div class="space-y-2 text-xs max-h-60 overflow-y-auto">
                    @forelse($booking->reminders as $rem)
                        <div class="rounded-lg bg-slate-50 p-2 border border-slate-100">
                            <div class="flex justify-between font-bold text-slate-800"><span>{{ $rem->reminder_type }}</span><span>{{ $rem->status }}</span></div>
                            <div class="text-[11px] text-slate-500 font-mono">مجدول: {{ $rem->scheduled_for?->format('Y-m-d H:i') }} @if($rem->sent_at) · أُرسل: {{ $rem->sent_at->format('H:i') }} @endif</div>
                        </div>
                    @empty
                        <p class="text-xs text-slate-400">لا توجد تذكيرات مسجلة.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
