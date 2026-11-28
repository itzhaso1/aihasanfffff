<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-xl font-extrabold text-slate-900">Booking Availability Settings · إعدادات الحجوزات والتوفر</h1>
                <p class="mt-0.5 text-xs text-slate-500">اضبط الخدمات وساعات العمل والاستراحات والسعة والفروع والموظفين والتذكيرات بدون أي تدخل تقني.</p>
            </div>
            <a href="{{ route('workspace.bookings.index') }}" class="hs-btn-secondary text-xs">← العودة إلى لوحة الحجوزات</a>
        </div>
    </x-slot>

    @php
        $dayNames = [
            'sunday' => 'الأحد',
            'monday' => 'الإثنين',
            'tuesday' => 'الثلاثاء',
            'wednesday' => 'الأربعاء',
            'thursday' => 'الخميس',
            'friday' => 'الجمعة',
            'saturday' => 'السبت',
        ];
        $hours = is_array($setting->working_hours) && $setting->working_hours !== [] ? $setting->working_hours : \App\Models\BookingSetting::defaultWorkingHours();
        $holidays = is_array($setting->holidays) ? $setting->holidays : [];
        $customFields = is_array($setting->custom_fields_schema) ? $setting->custom_fields_schema : [];
    @endphp

    <div class="space-y-6" dir="rtl">
        @include('partials.flash')
        @if($errors->any())
            <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-xs text-rose-800 space-y-1">
                @foreach($errors->all() as $err)<div>• {{ $err }}</div>@endforeach
            </div>
        @endif

        {{-- MAIN AVAILABILITY & NOTIFICATIONS SETTINGS FORM --}}
        <form method="POST" action="{{ route('workspace.bookings.settings.update') }}" class="space-y-5"
              x-data="{
                  holidays: {{ \Illuminate\Support\Js::from($holidays) }},
                  customFields: {{ \Illuminate\Support\Js::from($customFields) }},
                  workingHours: {{ \Illuminate\Support\Js::from($hours) }},
                  addBreak(day) {
                      if (!this.workingHours[day].breaks) this.workingHours[day].breaks = [];
                      this.workingHours[day].breaks.push({ start: '13:00', end: '14:00', label: 'استراحة' });
                  },
                  removeBreak(day, idx) {
                      this.workingHours[day].breaks.splice(idx, 1);
                  }
              }">
            @csrf @method('PUT')

            {{-- Core Controls --}}
            <div class="hs-card space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-3">
                    <div>
                        <h2 class="text-sm font-extrabold text-slate-900">1. التفعيل والضوابط العامة</h2>
                        <p class="text-xs text-slate-500">تحديد مدة الحجز والفترات الزمنية والسعة وحدود الحجز المسبق.</p>
                    </div>
                    <label class="inline-flex items-center gap-2 rounded-xl border border-[#06C2A4]/40 bg-[#E8FAF6] px-4 py-2 text-xs font-extrabold text-[#067E6B] cursor-pointer">
                        <input type="checkbox" name="enabled" value="1" @checked($setting->enabled) class="rounded border-[#06C2A4] text-[#06C2A4] focus:ring-[#06C2A4]">
                        <span>تفعيل نظام الحجوزات للذكاء الاصطناعي واللوحة</span>
                    </label>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">المنطقة الزمنية</label>
                        <select name="timezone" class="hs-select text-sm">
                            @foreach(['Asia/Riyadh' => 'الرياض (Asia/Riyadh)', 'Asia/Dubai' => 'دبي (Asia/Dubai)', 'Asia/Kuwait' => 'الكويت (Asia/Kuwait)', 'Asia/Qatar' => 'قطر (Asia/Qatar)', 'Asia/Bahrain' => 'البحرين (Asia/Bahrain)', 'Asia/Amman' => 'عمّان (Asia/Amman)', 'Africa/Cairo' => 'القاهرة (Africa/Cairo)', 'UTC' => 'UTC'] as $tz => $tzLabel)
                                <option value="{{ $tz }}" @selected($setting->timezone === $tz)>{{ $tzLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">الفاصل الزمني للفترات (دقيقة)</label>
                        <input type="number" name="slot_interval_minutes" min="5" max="480" value="{{ $setting->slot_interval_minutes }}" class="hs-input text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">مدة الحجز الافتراضية (دقيقة)</label>
                        <input type="number" name="default_duration_minutes" min="5" max="720" value="{{ $setting->default_duration_minutes }}" class="hs-input text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">الحد الأقصى للحجوزات بنفس الفترة</label>
                        <input type="number" name="default_capacity_per_slot" min="1" max="1000" value="{{ $setting->default_capacity_per_slot }}" class="hs-input text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">فاصل الراحة بين المواعيد (دقيقة)</label>
                        <input type="number" name="buffer_minutes" min="0" max="240" value="{{ $setting->buffer_minutes }}" class="hs-input text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">الحد الأدنى للحجز المسبق (دقيقة)</label>
                        <input type="number" name="min_advance_minutes" min="0" max="43200" value="{{ $setting->min_advance_minutes }}" class="hs-input text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">الحد الأقصى للحجز المسبق (يوم)</label>
                        <input type="number" name="max_advance_days" min="1" max="365" value="{{ $setting->max_advance_days }}" class="hs-input text-sm">
                    </div>
                    <div class="flex flex-col justify-end gap-2 text-xs">
                        <label class="inline-flex items-center gap-2"><input type="checkbox" name="auto_confirm" value="1" @checked($setting->auto_confirm) class="rounded text-[#06C2A4]"><span>تأكيد الحجز تلقائياً (Confirmed)</span></label>
                        <label class="inline-flex items-center gap-2"><input type="checkbox" name="allow_party_size" value="1" @checked($setting->allow_party_size) class="rounded text-[#06C2A4]"><span>السماح بطلب عدد الأشخاص</span></label>
                        <label class="inline-flex items-center gap-2"><input type="checkbox" name="require_staff" value="1" @checked($setting->require_staff) class="rounded text-[#06C2A4]"><span>تحديد موظف/مقدم خدمة</span></label>
                        <label class="inline-flex items-center gap-2"><input type="checkbox" name="require_branch" value="1" @checked($setting->require_branch) class="rounded text-[#06C2A4]"><span>إلزام اختيار الفرع</span></label>
                    </div>
                </div>
            </div>

            {{-- Working Hours & Breaks --}}
            <div class="hs-card space-y-4">
                <div>
                    <h2 class="text-sm font-extrabold text-slate-900">2. الأيام المتاحة وساعات العمل وأوقات الاستراحة</h2>
                    <p class="text-xs text-slate-500">تحكم بأيام وساعات العمل الأسبوعية وأضف فترات استراحة لكل يوم.</p>
                </div>

                <div class="space-y-3">
                    @foreach($dayNames as $dayKey => $dayLabel)
                        <div class="rounded-xl border border-slate-200/80 bg-slate-50/50 p-3 space-y-2">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <label class="inline-flex items-center gap-2 text-xs font-extrabold text-slate-800 min-w-[110px]">
                                    <input type="checkbox" :name="`working_hours[{{ $dayKey }}][enabled]`" value="1" x-model="workingHours['{{ $dayKey }}'].enabled" class="rounded text-[#06C2A4]">
                                    <span>{{ $dayLabel }}</span>
                                </label>

                                <div class="flex flex-wrap items-center gap-2" x-show="workingHours['{{ $dayKey }}'].enabled">
                                    <span class="text-xs text-slate-500">من</span>
                                    <input type="time" :name="`working_hours[{{ $dayKey }}][start]`" x-model="workingHours['{{ $dayKey }}'].start" class="hs-input text-xs py-1.5 w-32">
                                    <span class="text-xs text-slate-500">إلى</span>
                                    <input type="time" :name="`working_hours[{{ $dayKey }}][end]`" x-model="workingHours['{{ $dayKey }}'].end" class="hs-input text-xs py-1.5 w-32">
                                    <button type="button" @click="addBreak('{{ $dayKey }}')" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-bold text-[#067E6B] hover:bg-[#E8FAF6]">+ استراحة</button>
                                </div>
                                <span x-show="!workingHours['{{ $dayKey }}'].enabled" class="text-xs text-rose-500 font-semibold">مغلق</span>
                            </div>

                            <template x-for="(brk, bIdx) in (workingHours['{{ $dayKey }}'].breaks || [])" :key="bIdx">
                                <div class="flex flex-wrap items-center gap-2 ps-6 pt-1 text-xs">
                                    <span class="text-slate-500">استراحة:</span>
                                    <input type="time" :name="`working_hours[{{ $dayKey }}][breaks][${bIdx}][start]`" x-model="brk.start" class="hs-input text-xs py-1 w-28">
                                    <span>إلى</span>
                                    <input type="time" :name="`working_hours[{{ $dayKey }}][breaks][${bIdx}][end]`" x-model="brk.end" class="hs-input text-xs py-1 w-28">
                                    <input type="text" :name="`working_hours[{{ $dayKey }}][breaks][${bIdx}][label]`" x-model="brk.label" placeholder="مسمى (اختياري)" class="hs-input text-xs py-1 w-32">
                                    <button type="button" @click="removeBreak('{{ $dayKey }}', bIdx)" class="text-rose-600 font-bold">حذف</button>
                                </div>
                            </template>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Holidays & Custom Fields --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                <div class="hs-card space-y-3">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-extrabold text-slate-900">3. أيام الإجازات والعطل</h2>
                        <button type="button" @click="holidays.push({ date: '', title: 'إجازة رسمية', recurring_yearly: false })" class="hs-btn-secondary text-xs py-1 px-2.5">+ إضافة إجازة</button>
                    </div>
                    <div class="space-y-2">
                        <template x-for="(hol, hIdx) in holidays" :key="hIdx">
                            <div class="flex flex-wrap items-center gap-2 rounded-xl bg-slate-50 p-2 border border-slate-100">
                                <input type="date" :name="`holidays_raw[${hIdx}][date]`" x-model="hol.date" class="hs-input text-xs py-1.5 w-36">
                                <input type="text" :name="`holidays_raw[${hIdx}][title]`" x-model="hol.title" placeholder="سبب الإجازة" class="hs-input text-xs py-1.5 flex-1">
                                <label class="inline-flex items-center gap-1 text-[11px]"><input type="checkbox" :name="`holidays_raw[${hIdx}][recurring_yearly]`" value="1" x-model="hol.recurring_yearly" class="rounded"><span>سنوياً</span></label>
                                <button type="button" @click="holidays.splice(hIdx, 1)" class="text-xs font-bold text-rose-600">حذف</button>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="hs-card space-y-3">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-extrabold text-slate-900">4. حقول مرنة إضافية للحجز (حسب النشاط)</h2>
                        <button type="button" @click="customFields.push({ key: '', label: '', type: 'text', required: false, options: '' })" class="hs-btn-secondary text-xs py-1 px-2.5">+ إضافة حقل</button>
                    </div>
                    <p class="text-xs text-slate-500">أضف حقولاً يجمعها AI مثل: نوع السيارة، رقم الطاولة، نوع الملعب، أو ملاحظة طبية.</p>
                    <div class="space-y-2">
                        <template x-for="(cf, cIdx) in customFields" :key="cIdx">
                            <div class="grid grid-cols-1 sm:grid-cols-4 gap-2 rounded-xl bg-slate-50 p-2 border border-slate-100 items-center">
                                <input type="hidden" :name="`custom_fields_raw[${cIdx}][key]`" x-model="cf.key">
                                <input type="text" :name="`custom_fields_raw[${cIdx}][label]`" x-model="cf.label" placeholder="اسم الحقل" class="hs-input text-xs py-1.5">
                                <select :name="`custom_fields_raw[${cIdx}][type]`" x-model="cf.type" class="hs-select text-xs py-1.5">
                                    <option value="text">نص</option>
                                    <option value="number">رقم</option>
                                    <option value="select">اختيار</option>
                                </select>
                                <label class="inline-flex items-center gap-1 text-xs"><input type="checkbox" :name="`custom_fields_raw[${cIdx}][required]`" value="1" x-model="cf.required" class="rounded"><span>مطلوب</span></label>
                                <button type="button" @click="customFields.splice(cIdx, 1)" class="text-xs font-bold text-rose-600 text-end">حذف</button>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- Notifications & Reminders --}}
            <div class="hs-card space-y-4">
                <div>
                    <h2 class="text-sm font-extrabold text-slate-900">5. الإشعارات والتذكيرات التلقائية</h2>
                    <p class="text-xs text-slate-500">تحكم بإرسال رسائل التأكيد والتعديل والتأجيل والإلغاء والتذكير التلقائي قبل الموعد عبر نفس قناة العميل.</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 text-xs">
                    <label class="inline-flex items-center gap-2"><input type="checkbox" name="notifications_enabled" value="1" @checked($setting->notifications_enabled) class="rounded text-[#06C2A4]"><span>تفعيل إشعارات الحجوزات للعملاء</span></label>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" name="send_confirmation_message" value="1" @checked($setting->send_confirmation_message) class="rounded text-[#06C2A4]"><span>إرسال تأكيد الحجز</span></label>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" name="send_update_message" value="1" @checked($setting->send_update_message) class="rounded text-[#06C2A4]"><span>إرسال تأكيد التعديل</span></label>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" name="send_reschedule_message" value="1" @checked($setting->send_reschedule_message) class="rounded text-[#06C2A4]"><span>إرسال تأكيد التأجيل</span></label>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" name="send_cancellation_message" value="1" @checked($setting->send_cancellation_message) class="rounded text-[#06C2A4]"><span>إرسال تأكيد الإلغاء</span></label>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" name="reminder_enabled" value="1" @checked($setting->reminder_enabled) class="rounded text-[#06C2A4]"><span>تفعيل التذكير التلقائي قبل الموعد</span></label>
                </div>
                <div class="max-w-xs">
                    <label class="block text-xs font-bold text-slate-700 mb-1">إرسال التذكير قبل الموعد بـ (دقيقة)</label>
                    <input type="number" name="reminder_lead_minutes" min="5" max="43200" value="{{ $setting->reminder_lead_minutes }}" class="hs-input text-sm">
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="hs-btn-primary text-sm px-6">حفظ جميع إعدادات التوفر والتذكيرات</button>
            </div>
        </form>

        {{-- SERVICES / BOOKING TYPES --}}
        <div class="hs-card space-y-4">
            <h2 class="text-sm font-extrabold text-slate-900">6. أسماء الخدمات وأنواع الحجوزات</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach($services as $svc)
                    <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-3 flex items-start justify-between gap-2 text-xs">
                        <div>
                            <div class="font-bold text-slate-900">{{ $svc->name }}</div>
                            <div class="text-slate-500">{{ $svc->effectiveDurationMinutes($setting) }} دقيقة · السعة {{ $svc->effectiveCapacity($setting) }} @if($svc->price_minor !== null) · {{ $svc->formattedPrice() }} {{ $svc->currency }} @endif</div>
                        </div>
                        <form method="POST" action="{{ route('workspace.bookings.settings.services.destroy', $svc) }}">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-rose-600 font-bold hover:underline">حذف</button>
                        </form>
                    </div>
                @endforeach
            </div>
            <form method="POST" action="{{ route('workspace.bookings.settings.services.store') }}" class="grid grid-cols-1 sm:grid-cols-6 gap-2.5 border-t border-slate-100 pt-3">
                @csrf
                <input type="text" name="name" required placeholder="اسم الخدمة / الحجز *" class="hs-input text-xs sm:col-span-2">
                <input type="number" name="duration_minutes" min="5" placeholder="المدة (دقيقة)" class="hs-input text-xs">
                <input type="number" name="capacity_per_slot" min="1" placeholder="السعة/فترة" class="hs-input text-xs">
                <input type="number" step="0.01" min="0" name="price" placeholder="السعر (اختياري)" class="hs-input text-xs">
                <button type="submit" class="hs-btn-primary text-xs">+ إضافة خدمة</button>
            </form>
        </div>

        {{-- BRANCHES & PROVIDERS --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <div class="hs-card space-y-4">
                <h2 class="text-sm font-extrabold text-slate-900">7. الفروع والمواقع</h2>
                <div class="space-y-2 text-xs">
                    @foreach($branches as $br)
                        <div class="flex items-center justify-between rounded-xl bg-slate-50 p-2.5 border border-slate-100">
                            <div><strong>{{ $br->name }}</strong> <span class="text-slate-500">{{ $br->city }} {{ $br->address }}</span></div>
                            <form method="POST" action="{{ route('workspace.bookings.settings.branches.destroy', $br) }}">@csrf @method('DELETE')<button type="submit" class="text-rose-600 font-bold">حذف</button></form>
                        </div>
                    @endforeach
                </div>
                <form method="POST" action="{{ route('workspace.bookings.settings.branches.store') }}" class="grid grid-cols-1 sm:grid-cols-3 gap-2 border-t border-slate-100 pt-3">
                    @csrf
                    <input type="text" name="name" required placeholder="اسم الفرع *" class="hs-input text-xs">
                    <input type="text" name="city" placeholder="المدينة" class="hs-input text-xs">
                    <button type="submit" class="hs-btn-secondary text-xs">+ إضافة فرع</button>
                </form>
            </div>

            <div class="hs-card space-y-4">
                <h2 class="text-sm font-extrabold text-slate-900">8. الموظفون / مقدمو الخدمة / الموارد</h2>
                <div class="space-y-2 text-xs">
                    @foreach($providers as $pr)
                        <div class="flex items-center justify-between rounded-xl bg-slate-50 p-2.5 border border-slate-100">
                            <div><strong>{{ $pr->name }}</strong> <span class="text-slate-500">{{ $pr->role_label }} @if($pr->branch?->name) · {{ $pr->branch->name }} @endif (سعة {{ $pr->capacity_per_slot }})</span></div>
                            <form method="POST" action="{{ route('workspace.bookings.settings.providers.destroy', $pr) }}">@csrf @method('DELETE')<button type="submit" class="text-rose-600 font-bold">حذف</button></form>
                        </div>
                    @endforeach
                </div>
                <form method="POST" action="{{ route('workspace.bookings.settings.providers.store') }}" class="grid grid-cols-1 sm:grid-cols-4 gap-2 border-t border-slate-100 pt-3">
                    @csrf
                    <input type="text" name="name" required placeholder="الاسم / المورد *" class="hs-input text-xs">
                    <input type="text" name="role_label" placeholder="الصفة (اختياري)" class="hs-input text-xs">
                    <select name="branch_id" class="hs-select text-xs">
                        <option value="">كل الفروع</option>
                        @foreach($branches as $br)<option value="{{ $br->id }}">{{ $br->name }}</option>@endforeach
                    </select>
                    <button type="submit" class="hs-btn-secondary text-xs">+ إضافة مقدم</button>
                </form>
            </div>
        </div>

        {{-- MANUAL BLOCKED SLOTS --}}
        <div class="hs-card space-y-4">
            <h2 class="text-sm font-extrabold text-slate-900">9. إغلاق أوقات معينة يدوياً</h2>
            <div class="space-y-2 text-xs">
                @foreach($blockedSlots as $bl)
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-amber-50/70 p-2.5 border border-amber-200">
                        <div>
                            <strong class="font-mono">{{ $bl->starts_at?->setTimezone($setting->timezone ?: 'Asia/Riyadh')->format('Y-m-d H:i') }} → {{ $bl->ends_at?->setTimezone($setting->timezone ?: 'Asia/Riyadh')->format('H:i') }}</strong>
                            @if($bl->branch?->name) · فرع: {{ $bl->branch->name }} @endif
                            @if($bl->provider?->name) · المقدم: {{ $bl->provider->name }} @endif
                            @if($bl->reason) · {{ $bl->reason }} @endif
                        </div>
                        <form method="POST" action="{{ route('workspace.bookings.settings.blocked-slots.destroy', $bl) }}">@csrf @method('DELETE')<button type="submit" class="text-rose-600 font-bold">فتح الفترة</button></form>
                    </div>
                @endforeach
            </div>
            <form method="POST" action="{{ route('workspace.bookings.settings.blocked-slots.store') }}" class="grid grid-cols-1 sm:grid-cols-6 gap-2 border-t border-slate-100 pt-3">
                @csrf
                <input type="date" name="date" required class="hs-input text-xs">
                <input type="time" name="start_time" required value="12:00" class="hs-input text-xs">
                <input type="time" name="end_time" required value="15:00" class="hs-input text-xs">
                <select name="branch_id" class="hs-select text-xs"><option value="">كل الفروع</option>@foreach($branches as $br)<option value="{{ $br->id }}">{{ $br->name }}</option>@endforeach</select>
                <input type="text" name="reason" placeholder="السبب (صيانة/اجتماع)" class="hs-input text-xs">
                <button type="submit" class="hs-btn-danger text-xs">إغلاق الفترة</button>
            </form>
        </div>
    </div>
</x-app-layout>
