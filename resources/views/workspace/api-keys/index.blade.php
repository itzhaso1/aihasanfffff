<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">مفاتيح API</h2></x-slot>

    <div class="py-8" dir="rtl">
        <div class="mx-auto max-w-5xl px-4 space-y-6">
            @include('workspace.partials.nav')
            @include('partials.flash')

            @if(!empty($plainText))
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                    <p class="text-sm font-bold text-amber-900">مفتاح جديد — انسخه الآن</p>
                    <p class="mt-1 text-xs text-amber-800">لن نعرض النص الكامل مرة أخرى. خزّنه في مكان آمن.</p>
                    @if(!empty($createdKeyName))
                        <p class="mt-2 text-xs text-amber-700">الاسم: {{ $createdKeyName }}</p>
                    @endif
                    <code class="mt-3 block break-all rounded-xl bg-white px-3 py-2 text-sm text-slate-900">{{ $plainText }}</code>
                </div>
            @endif

            <form method="POST" action="{{ route('workspace.api-keys.store') }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                @csrf
                <h3 class="text-sm font-semibold text-slate-900">إنشاء مفتاح</h3>
                <div class="mt-3 flex flex-wrap items-end gap-3">
                    <div class="min-w-[220px] flex-1">
                        <label class="mb-1 block text-xs font-semibold text-slate-600">الاسم</label>
                        <input type="text" name="name" value="{{ old('name') }}" required maxlength="120" class="w-full rounded-xl border-slate-300 text-sm" placeholder="مثال: تكامل المتجر">
                        @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">إنشاء</button>
                </div>
                <p class="mt-2 text-xs text-slate-500">يُخزَّن التجزئة فقط في قاعدة البيانات. النص الكامل يظهر مرة واحدة عند الإنشاء أو إعادة التوليد.</p>
            </form>

            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-4 py-3 text-right font-semibold">الاسم</th>
                            <th class="px-4 py-3 text-right font-semibold">البادئة</th>
                            <th class="px-4 py-3 text-right font-semibold">الحالة</th>
                            <th class="px-4 py-3 text-right font-semibold">آخر استخدام</th>
                            <th class="px-4 py-3 text-left font-semibold">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($keys as $key)
                            <tr>
                                <td class="px-4 py-3">{{ $key->name }}</td>
                                <td class="px-4 py-3"><code>{{ $key->key_prefix }}…</code></td>
                                <td class="px-4 py-3">
                                    @if($key->revoked_at)
                                        <span class="text-rose-600">ملغى</span>
                                    @else
                                        <span class="text-emerald-700">نشط</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $key->last_used_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="px-4 py-3 text-left">
                                    @unless($key->revoked_at)
                                        <form method="POST" action="{{ route('workspace.api-keys.regenerate', $key) }}" class="inline" onsubmit="return confirm('إعادة توليد المفتاح؟ سيبطل المفتاح الحالي.')">
                                            @csrf
                                            <button type="submit" class="text-slate-700 hover:underline">إعادة توليد</button>
                                        </form>
                                        <form method="POST" action="{{ route('workspace.api-keys.revoke', $key) }}" class="mr-3 inline" onsubmit="return confirm('إلغاء هذا المفتاح؟')">
                                            @csrf
                                            <button type="submit" class="text-rose-600 hover:underline">إلغاء</button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-slate-500">لا توجد مفاتيح بعد.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
