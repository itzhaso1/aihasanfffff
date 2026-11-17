<x-communication-layout page-title="إنشاء محادثة جديدة — حاسم">
    <div class="py-6 px-4 md:px-8 max-w-4xl mx-auto">
        {{-- Header & Back button --}}
        <div class="mb-6 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a
                    href="{{ route('workspace.conversations.index') }}"
                    class="flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 shadow-2xs transition hover:bg-slate-50 hover:text-slate-900"
                    title="العودة إلى المحادثات"
                >
                    <svg class="h-4 w-4 rtl:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                </a>
                <div>
                    <h2 class="text-lg font-bold text-slate-900">إنشاء محادثة جديدة</h2>
                    <p class="text-xs text-slate-500">بدء جلسة محادثة مباشرة مع عميل عبر إحدى القنوات المتاحة</p>
                </div>
            </div>
        </div>

        @include('partials.flash')

        <div class="rounded-3xl border border-slate-200/90 bg-white p-6 shadow-sm md:p-8">
            <form method="POST" action="{{ route('workspace.conversations.store') }}" class="space-y-6">
                @csrf
                <div class="grid gap-6 md:grid-cols-2">
                    {{-- العميل --}}
                    <div>
                        <label for="customer_id" class="mb-1.5 block text-xs font-bold text-slate-700">
                            العميل <span class="text-slate-400 font-normal">(اختياري)</span>
                        </label>
                        <select
                            id="customer_id"
                            name="customer_id"
                            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3 py-2.5 text-xs text-slate-800 transition focus:border-[#00BFAE] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#00BFAE]/15"
                        >
                            <option value="">بدون عميل (محادثة عامة)</option>
                            @foreach($customers as $customer)
                                <option value="{{ $customer->id }}" @selected(old('customer_id') == $customer->id)>
                                    {{ $customer->name }} @if($customer->phone) ({{ $customer->phone }}) @endif
                                </option>
                            @endforeach
                        </select>
                        @error('customer_id')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- القناة --}}
                    <div>
                        <label for="channel" class="mb-1.5 block text-xs font-bold text-slate-700">
                            القناة <span class="text-rose-500">*</span>
                        </label>
                        @php
                            $channelNames = [
                                'whatsapp' => 'WhatsApp (واتساب)',
                                'instagram' => 'Instagram (انستغرام)',
                                'facebook_messenger' => 'Facebook Messenger (ماسنجر)',
                                'email' => 'Email (بريد إلكتروني)',
                                'web' => 'Web (المحادثة الفورية)',
                                'manual' => 'Manual (يدوي)',
                            ];
                        @endphp
                        <select
                            id="channel"
                            name="channel"
                            required
                            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3 py-2.5 text-xs text-slate-800 transition focus:border-[#00BFAE] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#00BFAE]/15"
                        >
                            @foreach(['whatsapp','instagram','facebook_messenger','email','web','manual'] as $channel)
                                <option value="{{ $channel }}" @selected(old('channel') === $channel)>
                                    {{ $channelNames[$channel] ?? $channel }}
                                </option>
                            @endforeach
                        </select>
                        @error('channel')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- External ID --}}
                    <div>
                        <label for="external_id" class="mb-1.5 block text-xs font-bold text-slate-700">
                            External ID <span class="text-slate-400 font-normal">(معرّف خارجي إن وجد)</span>
                        </label>
                        <input
                            type="text"
                            id="external_id"
                            name="external_id"
                            value="{{ old('external_id') }}"
                            placeholder="مثال: wa_chat_966500000000"
                            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3 py-2.5 text-xs text-slate-800 placeholder-slate-400 transition focus:border-[#00BFAE] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#00BFAE]/15"
                        />
                        @error('external_id')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- الحالة --}}
                    <div>
                        <label for="status" class="mb-1.5 block text-xs font-bold text-slate-700">
                            حالة المحادثة
                        </label>
                        <select
                            id="status"
                            name="status"
                            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3 py-2.5 text-xs text-slate-800 transition focus:border-[#00BFAE] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#00BFAE]/15"
                        >
                            <option value="open" @selected(old('status', 'open') === 'open')>مفتوحة (نشطة)</option>
                            <option value="closed" @selected(old('status') === 'closed')>مغلقة</option>
                            <option value="archived" @selected(old('status') === 'archived')>مؤرشفة</option>
                        </select>
                        @error('status')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                {{-- تفعيل AI --}}
                <div class="rounded-2xl border border-slate-100 bg-slate-50/70 p-4">
                    <label class="inline-flex cursor-pointer items-center gap-3">
                        <input type="hidden" name="ai_enabled" value="0">
                        <input
                            type="checkbox"
                            name="ai_enabled"
                            value="1"
                            checked
                            class="h-4 w-4 rounded border-slate-300 text-[#00BFAE] focus:ring-[#00BFAE]/30"
                        />
                        <div>
                            <span class="block text-xs font-bold text-slate-900">تفعيل الرد الذكي الآلي (AI)</span>
                            <span class="block text-[11px] text-slate-500">تمكين موظف الذكاء الاصطناعي من الرد على استفسارات العميل عند استلام رسائل جديدة.</span>
                        </div>
                    </label>
                </div>

                {{-- Metadata JSON --}}
                <div>
                    <label for="metadata_json" class="mb-1.5 block text-xs font-bold text-slate-700">
                        Metadata JSON <span class="text-slate-400 font-normal">(بيانات وصفية متقدمة)</span>
                    </label>
                    <textarea
                        id="metadata_json"
                        name="metadata_json"
                        rows="3"
                        dir="ltr"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50/50 p-3 font-mono text-xs text-slate-800 placeholder-slate-400 transition focus:border-[#00BFAE] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#00BFAE]/15"
                    >{{ old('metadata_json', '{}') }}</textarea>
                    @error('metadata_json')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Actions --}}
                <div class="flex items-center gap-3 pt-2">
                    <button
                        type="submit"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-[#00BFAE] px-6 py-2.5 text-xs font-semibold text-white shadow-xs transition hover:bg-[#06a89a]"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        <span>إنشاء المحادثة</span>
                    </button>
                    <a
                        href="{{ route('workspace.conversations.index') }}"
                        class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                    >
                        إلغاء
                    </a>
                </div>
            </form>
        </div>
    </div>
</x-communication-layout>
