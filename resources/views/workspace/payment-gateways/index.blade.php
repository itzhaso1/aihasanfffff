<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-bold tracking-tight text-slate-900">بوابات وطرق الدفع</h2>
                <p class="mt-0.5 text-xs text-slate-500">ربط وإدارة معالجات الدفع الإلكتروني لقبول المدفوعات في المحادثات والطلبات.</p>
            </div>
            <a href="{{ route('workspace.payment-gateways.create') }}" class="hs-btn-primary">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <span>إضافة بوابة دفع</span>
            </a>
        </div>
    </x-slot>

    <div class="space-y-6" dir="rtl">
        @include('partials.flash')

        <!-- Gateways Summary / Info Card -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="hs-card flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </span>
                <div>
                    <span class="text-xs text-slate-400 font-medium">البوابات المتصلة</span>
                    <div class="text-lg font-bold text-slate-900">{{ $gateways->where('status', 'connected')->count() }}</div>
                </div>
            </div>

            <div class="hs-card flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                </span>
                <div>
                    <span class="text-xs text-slate-400 font-medium">إجمالي البوابات المجهزة</span>
                    <div class="text-lg font-bold text-slate-900">{{ $gateways->total() }}</div>
                </div>
            </div>

            <div class="hs-card flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#E8FAF6] text-[#067E6B]">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                </span>
                <div>
                    <span class="text-xs text-slate-400 font-medium">الدفع الفوري التلقائي</span>
                    <div class="text-xs font-bold text-[#067E6B]">مفعّل للمحادثات</div>
                </div>
            </div>
        </div>

        <!-- Gateways Table / List -->
        <div class="hs-card">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <h3 class="text-sm font-bold text-slate-900">بوابات الدفع المهيأة</h3>
                <span class="text-xs text-slate-400">تحديث فوري لروابط الدفع</span>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead>
                        <tr class="border-b border-slate-100 text-slate-400">
                            <th class="pb-3 font-semibold">المزوّد</th>
                            <th class="pb-3 font-semibold">حالة الاتصال</th>
                            <th class="pb-3 font-semibold">آخر تحقق</th>
                            <th class="pb-3 font-semibold text-left">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($gateways as $gateway)
                            <tr class="transition hover:bg-slate-50/60">
                                <td class="py-3.5 font-bold text-slate-900">
                                    <div class="flex items-center gap-2.5">
                                        <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-slate-100 text-slate-700 font-mono text-xs uppercase font-extrabold">
                                            {{ substr($gateway->provider, 0, 2) }}
                                        </span>
                                        <div>
                                            <div class="font-bold text-slate-800 uppercase">{{ $gateway->provider }}</div>
                                            <div class="text-[10px] text-slate-400">بوابة دفع مسجلة</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3.5">
                                    @if($gateway->status === 'connected')
                                        <span class="hs-badge-emerald">
                                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                            <span>متصل وجاهز</span>
                                        </span>
                                    @elseif($gateway->status === 'pending')
                                        <span class="hs-badge-amber">
                                            <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                                            <span>قيد التحقق</span>
                                        </span>
                                    @else
                                        <span class="hs-badge-rose">
                                            <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>
                                            <span>{{ $gateway->status }}</span>
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3.5 text-slate-500">
                                    {{ $gateway->last_verified_at ? \Carbon\Carbon::parse($gateway->last_verified_at)->diffForHumans() : 'لم يتم التحقق بعد' }}
                                </td>
                                <td class="py-3.5 text-left">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('workspace.payment-gateways.edit', $gateway) }}" class="hs-btn-secondary py-1 px-3 text-xs text-slate-700">
                                            تعديل
                                        </a>
                                        <form method="POST" action="{{ route('workspace.payment-gateways.destroy', $gateway) }}" class="inline" onsubmit="return confirm('هل أنت متأكد من حذف هذه البوابة؟')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="rounded-xl border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-100 transition">
                                                حذف
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-12 text-center">
                                    <div class="hs-empty">
                                        <svg class="h-8 w-8 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                                        <p class="mt-2 text-xs text-slate-500">لم يتم إعداد أي بوابة دفع حتى الآن.</p>
                                        <a href="{{ route('workspace.payment-gateways.create') }}" class="hs-btn-primary mt-3 text-xs">
                                            إضافة أول بوابة دفع
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($gateways->hasPages())
                <div class="mt-4 pt-3 border-t border-slate-100">
                    {{ $gateways->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
