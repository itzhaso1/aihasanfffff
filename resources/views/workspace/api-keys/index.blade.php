<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-bold tracking-tight text-slate-900">مفاتيح واجهة البرمجة (API Keys)</h2>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                <svg class="h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                تكامل آمن
            </span>
        </div>
    </x-slot>

    <div class="space-y-6" dir="rtl">
        @include('partials.flash')

        @if(!empty($plainText))
            <div class="rounded-2xl border border-amber-300 bg-amber-50/80 p-5 shadow-sm">
                <div class="flex items-start gap-3">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-700">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </span>
                    <div class="flex-1">
                        <h3 class="text-sm font-bold text-amber-900">مفتاح API الجديد جاهز — احفظه الآن!</h3>
                        <p class="mt-0.5 text-xs text-amber-800">
                            لأسباب أمنية، لن يتم عرض هذا المفتاح بالكامل مرة أخرى أبداً. خزنه في بيئة آمنة فوراً.
                        </p>
                        @if(!empty($createdKeyName))
                            <p class="mt-1 text-xs font-semibold text-amber-900">الاسم: {{ $createdKeyName }}</p>
                        @endif
                        
                        <div class="mt-3 flex items-center gap-2">
                            <code class="flex-1 select-all font-mono text-xs rounded-xl border border-amber-200 bg-white px-3 py-2 text-slate-900 break-all">{{ $plainText }}</code>
                            <button type="button" onclick="navigator.clipboard.writeText('{{ $plainText }}'); alert('تم نسخ المفتاح بنجاح')" class="hs-btn-secondary shrink-0 text-xs py-2 px-3">
                                نسخ المفتاح
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <!-- Generate Key Form -->
        <div class="hs-card">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-bold text-slate-900">إنشاء مفتاح وصول جديد</h3>
                    <p class="mt-0.5 text-xs text-slate-500">استخدم المفاتيح لربط تطبيقاتك وأنظمتك الخارجية بـ Hasim AI بأمان وسرعة.</p>
                </div>
            </div>

            <form method="POST" action="{{ route('workspace.api-keys.store') }}" class="mt-4">
                @csrf
                <div class="flex flex-col sm:flex-row items-end gap-3">
                    <div class="w-full sm:flex-1">
                        <label class="mb-1 block text-xs font-semibold text-slate-700">اسم المفتاح أو النظام المرتبط</label>
                        <input type="text" name="name" value="{{ old('name') }}" required maxlength="120" class="hs-input" placeholder="مثال: متجر زد، شوبيفاي، تطبيق الويب الخارجي">
                        @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="hs-btn-primary whitespace-nowrap">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        <span>توليد المفتاح</span>
                    </button>
                </div>
                <p class="mt-2 text-[11px] text-slate-400">
                    يتم تخزين التجزئة الآمنة (Hash) فقط في قاعدة البيانات لحماية مساحة عملك.
                </p>
            </form>
        </div>

        <!-- API Keys List -->
        <div class="hs-card">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div>
                    <h3 class="text-sm font-bold text-slate-900">المفاتيح الحالية</h3>
                    <p class="mt-0.5 text-xs text-slate-400">إدارة الصلاحيات وحالة المفاتيح النشطة</p>
                </div>
                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-bold text-slate-600">
                    {{ $keys->count() }} مفاتيح
                </span>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead>
                        <tr class="border-b border-slate-100 text-slate-400">
                            <th class="pb-3 font-semibold">اسم المفتاح</th>
                            <th class="pb-3 font-semibold">البادئة</th>
                            <th class="pb-3 font-semibold">الحالة</th>
                            <th class="pb-3 font-semibold">آخر استخدام</th>
                            <th class="pb-3 font-semibold text-left">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($keys as $key)
                            <tr class="transition hover:bg-slate-50/60">
                                <td class="py-3 font-semibold text-slate-800">
                                    <div class="flex items-center gap-2">
                                        <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-slate-100 text-slate-600">
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                                        </div>
                                        <span>{{ $key->name }}</span>
                                    </div>
                                </td>
                                <td class="py-3 font-mono text-slate-600">
                                    <span class="rounded bg-slate-100 px-2 py-0.5">{{ $key->key_prefix }}…</span>
                                </td>
                                <td class="py-3">
                                    @if($key->revoked_at)
                                        <span class="hs-badge-rose">
                                            <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>
                                            <span>ملغى</span>
                                        </span>
                                    @else
                                        <span class="hs-badge-emerald">
                                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                            <span>نشط ومفعل</span>
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3 text-slate-500">
                                    {{ $key->last_used_at?->diffForHumans() ?? 'لم يُستخدم بعد' }}
                                </td>
                                <td class="py-3 text-left">
                                    @unless($key->revoked_at)
                                        <div class="flex items-center justify-end gap-2">
                                            <form method="POST" action="{{ route('workspace.api-keys.regenerate', $key) }}" class="inline" onsubmit="return confirm('هل تريد إعادة توليد المفتاح؟ سيبطل ذلك عمل المفتاح القديم فوراً.')">
                                                @csrf
                                                <button type="submit" class="hs-btn-secondary py-1 px-2.5 text-xs text-slate-700">
                                                    إعادة توليد
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('workspace.api-keys.revoke', $key) }}" class="inline" onsubmit="return confirm('هل أنت متأكد من رغبتك في إلغاء هذا المفتاح؟')">
                                                @csrf
                                                <button type="submit" class="rounded-xl border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-100 transition">
                                                    إلغاء المفتاح
                                                </button>
                                            </form>
                                        </div>
                                    @else
                                        <span class="text-xs text-slate-400">لا توجد إجراءات</span>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-10 text-center">
                                    <div class="hs-empty">
                                        <svg class="h-8 w-8 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                                        <p class="mt-2 text-xs text-slate-500">لم يتم إنشاء أي مفتاح API حتى الآن.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
