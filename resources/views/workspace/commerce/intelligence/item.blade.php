<x-communication-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">ذكاء المنتج: {{ $item->name }}</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-6xl px-4">
            @include('workspace.partials.nav')
            @if(session('status'))
                <div class="mb-4 rounded bg-green-100 p-3 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            <div class="mb-4 text-sm text-gray-500">
                الكتالوج: <span class="font-medium text-gray-900">{{ $item->name }}</span> | التصنيف: <span class="font-medium text-gray-900">{{ $item->category?->name ?? 'عام' }}</span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="md:col-span-2 rounded-xl border bg-white p-6 shadow-sm">
                    <h3 class="text-lg font-bold mb-4">خصائص المنتج المحددة (Item Attribute Values)</h3>
                    <form method="POST" action="{{ route('workspace.commerce.intelligence.items.attributes.store', $item) }}" class="space-y-4">
                        @csrf
                        @forelse($definitions as $def)
                            @php
                                $val = $intelligenceProfile->attributes[$def->key]['value'] ?? '';
                            @endphp
                            <div class="grid grid-cols-3 items-center gap-2 border-b pb-3">
                                <div>
                                    <label class="text-xs font-semibold text-gray-800">{{ $def->display_name }}</label>
                                    <span class="block text-xs font-mono text-gray-400">{{ $def->key }}</span>
                                </div>
                                <div>
                                    <input type="hidden" name="attributes[{{ $loop->index }}][definition_id]" value="{{ $def->id }}">
                                    <input type="text" name="attributes[{{ $loop->index }}][value]" value="{{ is_array($val) ? json_encode($val) : $val }}" placeholder="قيمة الخاصية" class="w-full rounded border-gray-300 text-sm">
                                </div>
                                <div>
                                    <select name="attributes[{{ $loop->index }}][source]" class="w-full rounded border-gray-300 text-xs">
                                        <option value="merchant_provided" selected>Merchant Provided (Authoritative)</option>
                                        <option value="system_derived">System Derived</option>
                                    </select>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">لا توجد تعاريف خصائص بالمنظومة بعد.</p>
                        @endforelse

                        @if($definitions->isNotEmpty())
                            <div class="pt-4 flex justify-end">
                                <button type="submit" class="rounded bg-indigo-600 px-4 py-2 text-sm text-white hover:bg-indigo-700">حفظ الخصائص</button>
                            </div>
                        @endif
                    </form>
                </div>

                <div class="rounded-xl border bg-white p-6 shadow-sm space-y-6">
                    <div>
                        <h4 class="font-bold text-sm text-gray-900 border-b pb-2 mb-2">المتطلبات المطبقة</h4>
                        <ul class="text-xs space-y-1 text-gray-600">
                            @forelse($intelligenceProfile->requirements as $r)
                                <li>• <span class="font-semibold">{{ $r['label'] }}</span> ({{ $r['key'] }})</li>
                            @empty
                                <li class="text-gray-400">لا توجد متطلبات مطبقة.</li>
                            @endforelse
                        </ul>
                    </div>

                    <div>
                        <h4 class="font-bold text-sm text-gray-900 border-b pb-2 mb-2">علاقات التوافقية</h4>
                        <ul class="text-xs space-y-1 text-gray-600">
                            @forelse($intelligenceProfile->compatibilities as $c)
                                <li>• <span class="font-semibold">{{ $c['compatibility_type'] }}</span>: {{ json_encode($c['target_reference'], JSON_UNESCAPED_UNICODE) }}</li>
                            @empty
                                <li class="text-gray-400">لا توجد سجلات توافقية.</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-communication-layout>
