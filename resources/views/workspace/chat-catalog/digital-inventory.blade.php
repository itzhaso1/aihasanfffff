<x-communication-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold text-slate-800">إدارة المخزون الرقمي (Digital Inventory)</h2>
                <p class="mt-0.5 text-xs text-slate-500">خاصية تخزين واستيراد الأكواد والبطاقات الرقمية والتراخيص والملفات بشكل آمن ومغلق.</p>
            </div>
            <a href="{{ route('workspace.chat-catalog.index') }}" class="text-sm font-medium text-slate-600 hover:text-slate-900">← العودة للكتالوج</a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-7xl px-4 space-y-6">
            @include('workspace.partials.nav')
            @include('partials.flash')

            <!-- Security Notice Banner -->
            <div class="flex items-start gap-3 rounded-2xl border border-indigo-100 bg-indigo-50/70 p-4 text-xs text-indigo-900 shadow-sm">
                <svg class="h-5 w-5 shrink-0 text-indigo-600 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                <div>
                    <span class="font-bold">أمان المخزون الرقمي:</span>
                    تُحفظ جميع الأكواد، التراخيص، كلمات المرور ومسارات الملفات الرقمية في مشفرات خاصة ولن تُعرض قيمها السرية في أجهزة الإدارة أو واجهات API. يتم تسليم السر للعميل بصورة آمنة ومؤتمتة فقط بعد تأكيد الشراء.
                </div>
            </div>

            <!-- Pool Creation Section -->
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm space-y-4">
                <h3 class="text-sm font-semibold text-slate-900">إنشاء حوض مخزون رقمي جديد (Digital Pool)</h3>

                <form method="POST" action="{{ route('workspace.chat-catalog.digital-inventory.pools.store') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 items-end">
                    @csrf
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-700">الصنف الرقمي <span class="text-red-500">*</span></label>
                        <select name="chat_catalog_item_id" required class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500">
                            <option value="">اختر الصنف الرقمي</option>
                            @foreach($digitalItems as $item)
                                <option value="{{ $item->id }}">{{ $item->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('chat_catalog_item_id')" class="mt-1" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-700">اسم الحوض <span class="text-red-500">*</span></label>
                        <input name="name" required class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" placeholder="مثال: حوض بطاقات 50 ريال" />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-700">حد انخفاض المخزون</label>
                        <input name="low_stock_threshold" type="number" min="0" value="5" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" />
                        <x-input-error :messages="$errors->get('low_stock_threshold')" class="mt-1" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-700">مهلة الحجز (ثوانٍ)</label>
                        <input name="reservation_timeout_seconds" type="number" min="60" max="86400" value="900" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" />
                        <x-input-error :messages="$errors->get('reservation_timeout_seconds')" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2 lg:col-span-4">
                        <button type="submit" class="rounded-xl bg-slate-900 px-5 py-2 text-xs font-medium text-white hover:bg-slate-800 transition">إنشاء حوض المخزون</button>
                    </div>
                </form>
            </div>

            <!-- Pools List Table -->
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full text-right text-xs text-slate-700">
                    <thead class="bg-slate-50 text-slate-500 border-b border-slate-200">
                        <tr>
                            <th class="px-4 py-3 font-semibold">اسم الحوض</th>
                            <th class="px-4 py-3 font-semibold">الصنف والنسخة المرتبطة</th>
                            <th class="px-4 py-3 font-semibold">الوحدات المتاحة</th>
                            <th class="px-4 py-3 font-semibold">مهلة الحجز</th>
                            <th class="px-4 py-3 font-semibold">استيراد وحدات سرية وآمنة</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @forelse($pools as $pool)
                            <tr class="hover:bg-slate-50/50 transition">
                                <td class="px-4 py-3 font-semibold text-slate-900">{{ $pool->name }}</td>
                                <td class="px-4 py-3 text-slate-800">
                                    {{ $pool->item?->name ?? '—' }}
                                    @if($pool->variant)
                                        <span class="text-slate-500 font-normal">({{ $pool->variant->name }})</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 font-bold text-slate-900">
                                    <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold {{ $pool->available_units_count <= $pool->low_stock_threshold ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }}">
                                        {{ $pool->available_units_count }} وحدة متاحة
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-slate-600">{{ round($pool->reservation_timeout_seconds / 60) }} دقيقة ({{ $pool->reservation_timeout_seconds }} ثانية)</td>
                                <td class="px-4 py-3">
                                    <details class="group">
                                        <summary class="cursor-pointer font-medium text-blue-600 hover:text-blue-800 underline transition">
                                            + استيراد دفعة سرية
                                        </summary>
                                        <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-3">
                                            <form method="POST" action="{{ route('workspace.chat-catalog.digital-inventory.pools.import', $pool) }}" class="space-y-3">
                                                @csrf
                                                <div>
                                                    <label class="mb-1 block text-xs font-medium text-slate-700">نوع السر الرقمي</label>
                                                    <select name="unit_type" class="w-full rounded-lg border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500">
                                                        <option value="code">كود / رمز (Code)</option>
                                                        <option value="license">ترخيص (License)</option>
                                                        <option value="credential">بيانات دخول (Credential)</option>
                                                        <option value="subscription">حساب / اشتراك (Subscription)</option>
                                                        <option value="custom">مخصص (Custom)</option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="mb-1 block text-xs font-medium text-slate-700">أدخل الأسرار (سر واحد في كل سطر)</label>
                                                    <textarea name="secrets_text" required rows="4" class="w-full rounded-lg border-slate-300 text-xs font-mono focus:border-slate-500 focus:ring-slate-500" placeholder="CODE-AAAA-1111&#10;CODE-BBBB-2222&#10;CODE-CCCC-3333"></textarea>
                                                    <p class="mt-1 text-[11px] text-slate-500">ملاحظة أمان: تُشفر هذه البيانات فوراً ولن تظهر قيمها إطلاقاً في أي شاشة أو استعلام.</p>
                                                </div>
                                                <button type="submit" class="rounded-lg bg-slate-900 px-4 py-1.5 text-xs font-medium text-white hover:bg-slate-800 transition">تأكيد الاستيراد الآمن</button>
                                            </form>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-12">
                                    <x-empty-state title="لا توجد أحواض مخزون رقمي" text="قم بإنشاء حوض جديد للصنف الرقمي للبدء بتخزين واستيراد الأكواد والتراخيص." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="pt-2">{{ $pools->links() }}</div>
        </div>
    </div>
</x-communication-layout>
