<div
    x-data="assistantWidget(@js(route('assistant.chat')))"
    @pointermove.window="onDrag($event)"
    @pointerup.window="endDrag()"
    @pointercancel.window="endDrag()"
    class="fixed z-[70]"
    data-hs-chrome="assistant"
    :style="launcherStyle()"
>
    <div
        x-cloak
        x-show="open"
        x-transition.opacity
        class="absolute bottom-16 w-[min(20.6rem,calc(100vw-1.5rem))] overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl"
        :class="panelAlignClass()"
    >
        <div class="flex items-center justify-between border-b border-gray-100 bg-[#06C2A4] px-4 py-3 text-white">
            <div class="flex items-center gap-2.5">
                <img
                    src="{{ asset('images/hasim-assistant-icon.jpg') }}"
                    alt=""
                    class="h-9 w-9 rounded-full object-cover object-center ring-2 ring-white/30"
                >
                <div>
                    <p class="text-sm font-bold">مساعد حاسم</p>
                    <p class="text-[11px] text-white/90">دعم فوري للدخول والتنقل داخل المنصة</p>
                </div>
            </div>
            <button @click="open = false" class="rounded-md p-1 hover:bg-white/20">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div x-ref="log" class="max-h-72 space-y-2 overflow-y-auto bg-[#F7FCFB] p-3">
            <template x-for="(item, idx) in messages" :key="idx">
                <div :class="item.role === 'assistant' ? 'justify-start' : 'justify-end'" class="flex">
                    <div :class="item.role === 'assistant' ? 'bg-white text-gray-800 border border-gray-200' : 'bg-[#06C2A4] text-white'" class="max-w-[85%] rounded-2xl px-3 py-2 text-xs leading-6 shadow-sm">
                        <p x-text="item.text"></p>
                    </div>
                </div>
            </template>
            <p x-show="loading" class="text-xs text-gray-500">جاري كتابة الرد...</p>
        </div>

        <form @submit.prevent="send" class="border-t border-gray-100 bg-white p-3">
            <div class="flex items-center gap-2">
                <input
                    x-model="message"
                    type="text"
                    placeholder="اكتب سؤالك..."
                    class="w-full rounded-xl border-gray-300 text-sm focus:border-[#06C2A4] focus:ring-[#06C2A4]"
                />
                <button type="submit" class="rounded-xl bg-[#06C2A4] px-3 py-2 text-xs font-semibold text-white hover:bg-[#04a98e]">إرسال</button>
            </div>
        </form>
    </div>

    <button
        @pointerdown.prevent="startDrag($event)"
        @click="toggleFromLauncher()"
        type="button"
        class="inline-flex h-16 w-16 cursor-move items-center justify-center overflow-hidden rounded-full bg-transparent p-0 transition hover:scale-[1.03]"
        aria-label="فتح مساعد حاسم"
    >
        <img
            src="{{ asset('images/hasim-assistant-icon.png') }}"
            alt="مساعد {{ ($publicSite ?? \App\Support\Site\PublicSite::current())->nameAr() }}"
            class="h-full w-full scale-[1.08] object-cover object-center"
        >
    </button>
</div>
