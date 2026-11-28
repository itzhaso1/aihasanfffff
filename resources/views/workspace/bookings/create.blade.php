<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-extrabold text-slate-900">إضافة حجز جديد يدوياً</h1>
                <p class="mt-0.5 text-xs text-slate-500">أنشئ حجزاً مباشرة من لوحة التحكم مع فحص الأوقات المتاحة لحظياً.</p>
            </div>
            <a href="{{ route('workspace.bookings.index') }}" class="hs-btn-secondary text-xs">العودة للوحة الحجوزات</a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-3xl space-y-5" dir="rtl">
        @include('partials.flash')
        @if($errors->any())
            <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-xs text-rose-800 space-y-1">
                @foreach($errors->all() as $err)
                    <div>• {{ $err }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('workspace.bookings.store') }}" class="hs-card space-y-5">
            @csrf
            @if($prefillConversation)
                <input type="hidden" name="conversation_id" value="{{ $prefillConversation->id }}">
                <div class="rounded-xl border border-[#06C2A4]/30 bg-[#E8FAF6]/60 px-4 py-2.5 text-xs text-[#067E6B]">
                    مرتبط بالمحادثة #{{ $prefillConversation->id }} ({{ $prefillConversation->channel }})
                </div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">اسم العميل *</label>
                    <input type="text" name="customer_name" required value="{{ old('customer_name', $prefillCustomer?->name) }}" class="hs-input text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">رقم الجوال</label>
                    <input type="text" name="customer_phone" value="{{ old('customer_phone', $prefillCustomer?->phone) }}" class="hs-input text-sm font-mono" dir="ltr" placeholder="+9665...">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">الخدمة / نوع الحجز</label>
                    <select id="booking_service_id" name="service_id" class="hs-select text-sm">
                        <option value="">-- اختر أو اكتب بالأسفل --</option>
                        @foreach($services as $svc)
                            <option value="{{ $svc->id }}" @selected(old('service_id') == $svc->id)>{{ $svc->name }} ({{ $svc->effectiveDurationMinutes($setting) }} د)</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">أو اسم خدمة مخصص</label>
                    <input type="text" name="service_name" value="{{ old('service_name') }}" class="hs-input text-sm" placeholder="مثال: طاولة لشخصين / حجز ملعب">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                @if($branches->isNotEmpty())
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">الفرع / الموقع</label>
                        <select id="booking_branch_id" name="branch_id" class="hs-select text-sm">
                            <option value="">أي فرع / غير محدد</option>
                            @foreach($branches as $br)
                                <option value="{{ $br->id }}" @selected(old('branch_id') == $br->id)>{{ $br->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                @if($providers->isNotEmpty())
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">الموظف / مقدم الخدمة</label>
                        <select id="booking_provider_id" name="provider_id" class="hs-select text-sm">
                            <option value="">تعيين تلقائي للمتاح</option>
                            @foreach($providers as $pr)
                                <option value="{{ $pr->id }}" @selected(old('provider_id') == $pr->id)>{{ $pr->name }} @if($pr->role_label) ({{ $pr->role_label }}) @endif</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">عدد الأشخاص (اختياري)</label>
                    <input type="number" name="party_size" min="1" max="500" value="{{ old('party_size') }}" class="hs-input text-sm">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">تاريخ الحجز *</label>
                    <input id="booking_date_input" type="date" name="booking_date" required value="{{ old('booking_date', $todayLocal) }}" class="hs-input text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">وقت البداية *</label>
                    <input id="booking_time_input" type="time" name="start_time" required value="{{ old('start_time', '10:00') }}" class="hs-input text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">حالة الحجز الابتدائية</label>
                    <select name="status" class="hs-select text-sm">
                        <option value="confirmed" @selected(old('status', $setting->auto_confirm ? 'confirmed' : 'pending') === 'confirmed')>Confirmed · مؤكد</option>
                        <option value="pending" @selected(old('status') === 'pending')>Pending · قيد الانتظار</option>
                    </select>
                </div>
            </div>

            {{-- Available Slots Quick Picker --}}
            <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-3 space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-700">الأوقات المتاحة حسب إعدادات التوفر:</span>
                    <button type="button" id="load_slots_btn" class="text-xs font-bold text-[#067E6B] hover:underline">تحديث الأوقات المتاحة</button>
                </div>
                <div id="available_slots_box" class="flex flex-wrap gap-1.5 text-xs text-slate-400">اضغط تحديث الأوقات لعرض الفترات المتاحة للتاريخ المحدد.</div>
            </div>

            @if(! empty($setting->custom_fields_schema))
                <div class="space-y-3 border-t border-slate-100 pt-4">
                    <h3 class="text-xs font-extrabold text-slate-800">معلومات إضافية حسب نشاط المنشأة</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach($setting->custom_fields_schema as $field)
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">{{ $field['label'] }} @if($field['required']) * @endif</label>
                                @if(($field['type'] ?? 'text') === 'select' && ! empty($field['options']))
                                    <select name="custom_fields[{{ $field['key'] }}]" class="hs-select text-sm">
                                        <option value="">-- اختر --</option>
                                        @foreach($field['options'] as $opt)
                                            <option value="{{ $opt }}">{{ $opt }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <input type="{{ ($field['type'] ?? 'text') === 'number' ? 'number' : 'text' }}" name="custom_fields[{{ $field['key'] }}]" value="{{ old('custom_fields.'.$field['key']) }}" class="hs-input text-sm">
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">ملاحظات العميل</label>
                    <textarea name="customer_notes" rows="2" class="hs-input text-sm">{{ old('customer_notes') }}</textarea>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">ملاحظات داخلية للفريق</label>
                    <textarea name="internal_notes" rows="2" class="hs-input text-sm">{{ old('internal_notes') }}</textarea>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-4 text-xs text-slate-700">
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" name="override_rules" value="1" class="rounded border-slate-300 text-[#06C2A4] focus:ring-[#06C2A4]">
                    <span>السماح للحجز اليدوي بتجاوز قيود المدة المسبقة أو الإغلاق</span>
                </label>
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" name="notify_customer" value="1" checked class="rounded border-slate-300 text-[#06C2A4] focus:ring-[#06C2A4]">
                    <span>إرسال رسالة تأكيد عبر قناة المحادثة إن وُجدت</span>
                </label>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <a href="{{ route('workspace.bookings.index') }}" class="hs-btn-secondary text-xs">إلغاء</a>
                <button type="submit" class="hs-btn-primary text-xs">حفظ وإنشاء الحجز</button>
            </div>
        </form>
    </div>

    <script>
        (function () {
            const btn = document.getElementById('load_slots_btn');
            const box = document.getElementById('available_slots_box');
            const dateInput = document.getElementById('booking_date_input');
            const timeInput = document.getElementById('booking_time_input');
            const serviceEl = document.getElementById('booking_service_id');
            const branchEl = document.getElementById('booking_branch_id');
            const providerEl = document.getElementById('booking_provider_id');
            if (!btn || !box) return;

            const fetchSlots = async () => {
                box.textContent = 'جاري التحقق من التوفر...';
                const params = new URLSearchParams({
                    date: dateInput?.value || '',
                    service_id: serviceEl?.value || '',
                    branch_id: branchEl?.value || '',
                    provider_id: providerEl?.value || '',
                    ignore_advance: '1',
                });
                const res = await fetch(`{{ route('workspace.bookings.availability') }}?${params.toString()}`, { headers: { Accept: 'application/json' } });
                const data = await res.json();
                const slots = data.slots || [];
                if (!slots.length) {
                    box.textContent = data.enabled ? 'لا توجد فترات متاحة في هذا التاريخ.' : 'نظام الحجوزات معطّل حالياً في الإعدادات (يمكنك المتابعة كحجز يدوي).';
                    return;
                }
                box.innerHTML = '';
                slots.forEach((slot) => {
                    const chip = document.createElement('button');
                    chip.type = 'button';
                    chip.className = 'rounded-lg border border-[#06C2A4]/40 bg-white px-2.5 py-1 text-xs font-bold text-[#067E6B] hover:bg-[#E8FAF6]';
                    chip.textContent = `${slot.start_time} (متبقي ${slot.remaining_capacity})`;
                    chip.onclick = () => { if (timeInput) timeInput.value = slot.start_time; };
                    box.appendChild(chip);
                });
            };
            btn.addEventListener('click', fetchSlots);
            dateInput?.addEventListener('change', fetchSlots);
        })();
    </script>
</x-app-layout>
