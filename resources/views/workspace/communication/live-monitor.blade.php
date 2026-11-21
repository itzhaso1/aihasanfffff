<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2.5">
                    <h1 class="text-xl font-bold text-slate-900">المراقبة المباشرة للمحادثات</h1>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100/80 px-2.5 py-0.5 text-xs font-bold text-emerald-800 border border-emerald-300 shadow-2xs">
                        <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span>مباشر (Live Sync)</span>
                    </span>
                </div>
                <p class="mt-0.5 text-xs text-slate-500">
                    متابعة فورية لنشاط موظف الذكاء الاصطناعي (مشعل) وفريق العمل وتوزيع المسؤوليات وتفادي تعارض الردود.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('workspace.conversations.index') }}" class="hs-btn-primary text-xs">
                    فتح صندوق المحادثات
                </a>
            </div>
        </div>
    </x-slot>

    <div 
        x-data="liveMonitorApp()" 
        x-init="init()" 
        class="space-y-6" 
        dir="rtl"
    >
        @include('partials.flash')

        {{-- Top KPI Cards --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {{-- 1. AI Active --}}
            <div 
                @click="filterType = 'ai_active'"
                :class="filterType === 'ai_active' ? 'border-emerald-500 ring-2 ring-emerald-500/20 bg-emerald-50/40' : 'border-slate-200/80 hover:border-slate-300'"
                class="hs-stat-card p-4 transition cursor-pointer"
            >
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-600">يتولاها الذكاء الاصطناعي</span>
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </span>
                </div>
                <p class="mt-2 text-2xl font-black text-emerald-600" x-text="counts.ai_count">
                    {{ $stats['counts']['ai_count'] }}
                </p>
                <span class="text-[11px] text-slate-400 mt-0.5 block">مشعل AI يرد تلقائياً</span>
            </div>

            {{-- 2. Human Active --}}
            <div 
                @click="filterType = 'human_active'"
                :class="filterType === 'human_active' ? 'border-blue-500 ring-2 ring-blue-500/20 bg-blue-50/40' : 'border-slate-200/80 hover:border-slate-300'"
                class="hs-stat-card p-4 transition cursor-pointer"
            >
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-600">يتولاها الموظفون</span>
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-blue-100 text-blue-700">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    </span>
                </div>
                <p class="mt-2 text-2xl font-black text-blue-600" x-text="counts.human_count">
                    {{ $stats['counts']['human_count'] }}
                </p>
                <span class="text-[11px] text-slate-400 mt-0.5 block">محادثات قيد المتابعة البشرية</span>
            </div>

            {{-- 3. Human Needed --}}
            <div 
                @click="filterType = 'human_needed'"
                :class="filterType === 'human_needed' ? 'border-amber-500 ring-2 ring-amber-500/20 bg-amber-50/40' : 'border-slate-200/80 hover:border-slate-300'"
                class="hs-stat-card p-4 transition cursor-pointer"
            >
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-amber-800">تحتاج تدخلاً بشرياً</span>
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-amber-100 text-amber-700">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </span>
                </div>
                <p class="mt-2 text-2xl font-black text-amber-600" x-text="counts.human_needed_count">
                    {{ $stats['counts']['human_needed_count'] }}
                </p>
                <span class="text-[11px] text-amber-700 mt-0.5 block">تحويلات بانتظار الاستلام</span>
            </div>

            {{-- 4. Waiting for Reply --}}
            <div 
                @click="filterType = 'waiting'"
                :class="filterType === 'waiting' ? 'border-slate-500 ring-2 ring-slate-500/20 bg-slate-50/80' : 'border-slate-200/80 hover:border-slate-300'"
                class="hs-stat-card p-4 transition cursor-pointer"
            >
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-600">بانتظار الرد</span>
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-slate-100 text-slate-700">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                    </span>
                </div>
                <p class="mt-2 text-2xl font-black text-slate-700" x-text="counts.waiting_count">
                    {{ $stats['counts']['waiting_count'] }}
                </p>
                <span class="text-[11px] text-slate-400 mt-0.5 block">بدون مسؤول حالي</span>
            </div>
        </div>

        {{-- Active Employees Section --}}
        <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <div class="flex items-center gap-2">
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-blue-50 text-blue-700">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    </span>
                    <h3 class="text-sm font-bold text-slate-900">فريق العمل والمحادثات الموزعة</h3>
                </div>
                <span class="text-xs text-slate-400" x-text="employees.length + ' موظف مسجل'"></span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <template x-for="emp in employees" :key="emp.id">
                    <div 
                        class="rounded-xl border p-3.5 transition flex flex-col justify-between"
                        :class="emp.assigned_count > 0 ? 'border-blue-200 bg-blue-50/20' : 'border-slate-100 bg-slate-50/40'"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <span 
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full font-bold text-xs shadow-2xs"
                                    :class="emp.assigned_count > 0 ? 'bg-blue-600 text-white' : 'bg-slate-200 text-slate-700'"
                                    x-text="emp.name.substring(0, 1)"
                                ></span>
                                <div class="min-w-0">
                                    <p class="font-bold text-xs text-slate-900 truncate" x-text="emp.name"></p>
                                    <p class="text-[10px] text-slate-400 truncate" x-text="emp.role || 'عضو'"></p>
                                </div>
                            </div>
                            <span 
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold"
                                :class="emp.assigned_count > 0 ? 'bg-blue-100 text-blue-800' : 'bg-slate-100 text-slate-500'"
                                x-text="emp.assigned_count + ' محادثات'"
                            ></span>
                        </div>

                        <template x-if="emp.assigned_conversations && emp.assigned_conversations.length > 0">
                            <div class="mt-3 pt-2.5 border-t border-slate-200/60 text-[11px] space-y-1">
                                <span class="text-slate-400 block text-[10px] font-medium">العملاء قيد المتابعة:</span>
                                <div class="flex flex-wrap gap-1">
                                    <template x-for="c in emp.assigned_conversations" :key="c.conversation_id">
                                        <a 
                                            :href="'/workspace/conversations?conversation=' + c.conversation_id" 
                                            class="inline-flex items-center rounded-lg bg-white px-2 py-0.5 text-[10px] font-semibold text-slate-700 border border-slate-200 hover:text-blue-600 hover:border-blue-300 transition"
                                            x-text="c.customer_name"
                                        ></a>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>

        {{-- Conversations Live Table --}}
        <div class="rounded-2xl border border-slate-200/80 bg-white shadow-xs overflow-hidden">
            {{-- Controls Bar --}}
            <div class="p-4 border-b border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-3 bg-slate-50/50">
                <div class="flex items-center gap-1.5 flex-wrap w-full sm:w-auto">
                    <button 
                        type="button" 
                        @click="filterType = 'all'"
                        :class="filterType === 'all' ? 'bg-[#067E6B] text-white font-bold' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50'"
                        class="rounded-xl px-3 py-1.5 text-xs transition shadow-2xs"
                    >
                        الكل (<span x-text="conversations.length"></span>)
                    </button>
                    <button 
                        type="button" 
                        @click="filterType = 'ai_active'"
                        :class="filterType === 'ai_active' ? 'bg-emerald-600 text-white font-bold' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50'"
                        class="rounded-xl px-3 py-1.5 text-xs transition shadow-2xs"
                    >
                        مشعل AI (<span x-text="counts.ai_count"></span>)
                    </button>
                    <button 
                        type="button" 
                        @click="filterType = 'human_active'"
                        :class="filterType === 'human_active' ? 'bg-blue-600 text-white font-bold' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50'"
                        class="rounded-xl px-3 py-1.5 text-xs transition shadow-2xs"
                    >
                        الموظفون (<span x-text="counts.human_count"></span>)
                    </button>
                    <button 
                        type="button" 
                        @click="filterType = 'human_needed'"
                        :class="filterType === 'human_needed' ? 'bg-amber-600 text-white font-bold' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50'"
                        class="rounded-xl px-3 py-1.5 text-xs transition shadow-2xs"
                    >
                        تحتاج تدخلاً (<span x-text="counts.human_needed_count"></span>)
                    </button>
                    <button 
                        type="button" 
                        @click="filterType = 'waiting'"
                        :class="filterType === 'waiting' ? 'bg-slate-700 text-white font-bold' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50'"
                        class="rounded-xl px-3 py-1.5 text-xs transition shadow-2xs"
                    >
                        بانتظار الرد (<span x-text="counts.waiting_count"></span>)
                    </button>
                </div>

                {{-- Search Box --}}
                <div class="relative w-full sm:w-64">
                    <input 
                        type="text" 
                        x-model="searchQuery" 
                        placeholder="ابحث باسم العميل أو الهاتف..." 
                        class="hs-input text-xs w-full py-1.5 ps-8"
                    >
                    <svg class="pointer-events-none absolute start-2.5 top-2.5 h-3.5 w-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                </div>
            </div>

            {{-- Table --}}
            <div class="overflow-x-auto">
                <table class="hs-table w-full text-right text-xs">
                    <thead>
                        <tr>
                            <th class="px-4 py-3">العميل</th>
                            <th class="px-4 py-3">المسؤول عن الرد</th>
                            <th class="px-4 py-3">الحالة المباشرة</th>
                            <th class="px-4 py-3">آخر رسالة</th>
                            <th class="px-4 py-3">آخر تفاعل</th>
                            <th class="px-4 py-3 text-start">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="conv in filteredConversations" :key="conv.id">
                            <tr class="hover:bg-slate-50/80 transition">
                                {{-- Customer --}}
                                <td class="px-4 py-3.5">
                                    <div class="flex items-center gap-2.5">
                                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 font-bold text-slate-700 text-xs shadow-2xs" x-text="conv.customer_name.substring(0, 1)"></span>
                                        <div class="min-w-0">
                                            <a :href="'/workspace/conversations?conversation=' + conv.id" class="font-bold text-slate-900 hover:text-[#067E6B] block truncate" x-text="conv.customer_name"></a>
                                            <span class="text-[11px] text-slate-400 font-mono" x-text="conv.customer_phone"></span>
                                        </div>
                                    </div>
                                </td>

                                {{-- Responder --}}
                                <td class="px-4 py-3.5">
                                    <div class="flex items-center gap-2">
                                        <span 
                                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold border shadow-2xs"
                                            :class="conv.responder.badge_class"
                                        >
                                            <span class="h-1.5 w-1.5 rounded-full" :class="conv.responder.dot_class"></span>
                                            <span x-text="conv.responder.name"></span>
                                        </span>
                                    </div>
                                    <template x-if="conv.responder.assigned_at_human">
                                        <span class="text-[10px] text-slate-400 block mt-0.5" x-text="'منذ: ' + conv.responder.assigned_at_human"></span>
                                    </template>
                                </td>

                                {{-- Live Status --}}
                                <td class="px-4 py-3.5">
                                    <span 
                                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold"
                                        :class="conv.responder.badge_class"
                                    >
                                        <span x-text="conv.responder.label"></span>
                                    </span>
                                    <template x-if="conv.responder.is_ai_generating">
                                        <span class="text-[10px] text-emerald-600 block animate-pulse font-semibold mt-0.5">يولد الرد الآن...</span>
                                    </template>
                                </td>

                                {{-- Last Message --}}
                                <td class="px-4 py-3.5">
                                    <p class="truncate max-w-[220px] text-slate-600 text-xs" x-text="conv.last_message || '—'"></p>
                                </td>

                                {{-- Last Activity --}}
                                <td class="px-4 py-3.5 text-slate-500 font-mono text-[11px]">
                                    <span x-text="conv.last_message_at"></span>
                                    <span class="block text-[10px] text-slate-400 font-sans" x-text="conv.last_activity_human"></span>
                                </td>

                                {{-- Actions --}}
                                <td class="px-4 py-3.5 text-start whitespace-nowrap">
                                    <div class="flex items-center gap-1.5">
                                        {{-- Open chat --}}
                                        <a 
                                            :href="'/workspace/conversations?conversation=' + conv.id" 
                                            class="hs-btn-secondary text-[11px] py-1 px-2.5"
                                        >
                                            فتح
                                        </a>

                                        {{-- Takeover --}}
                                        <template x-if="conv.responder.type === 'ai' || conv.responder.status === 'human_needed' || conv.responder.status === 'waiting'">
                                            <button 
                                                type="button" 
                                                @click="takeoverConversation(conv.id)" 
                                                class="rounded-lg bg-blue-600 text-white px-2.5 py-1 text-[11px] font-bold hover:bg-blue-700 transition shadow-2xs"
                                                title="تولي المحادثة"
                                            >
                                                تولي
                                            </button>
                                        </template>

                                        {{-- Resume AI --}}
                                        <template x-if="conv.responder.type === 'human'">
                                            <button 
                                                type="button" 
                                                @click="resumeAiConversation(conv.id)" 
                                                class="rounded-lg bg-emerald-600 text-white px-2.5 py-1 text-[11px] font-bold hover:bg-emerald-700 transition shadow-2xs"
                                                title="إعادة المحادثة إلى مشعل AI"
                                            >
                                                إعادة لـ AI
                                            </button>
                                        </template>

                                        {{-- View Audit Log --}}
                                        <button 
                                            type="button" 
                                            @click="openAuditModal(conv.id)" 
                                            class="rounded-lg border border-slate-300 bg-white p-1 text-slate-500 hover:bg-slate-100 transition shadow-2xs"
                                            title="سجل المسؤولية"
                                        >
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>

                        <template x-if="filteredConversations.length === 0">
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-slate-500">
                                    لا توجد محادثات تطابق الفلتر الحالي.
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Audit Log Modal --}}
        <div
            x-show="modalOpen"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-xs"
            @keydown.escape.window="modalOpen = false"
        >
            <div 
                class="relative w-full max-w-lg rounded-2xl bg-white shadow-2xl border border-slate-200 overflow-hidden text-right"
                dir="rtl"
                @click.outside="modalOpen = false"
            >
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 bg-slate-50/70">
                    <div class="flex items-center gap-2.5">
                        <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-blue-100 text-blue-700">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                        </span>
                        <div>
                            <h3 class="text-sm font-bold text-slate-900">سجل المسؤولية والتحويلات</h3>
                            <p class="text-[11px] text-slate-500">تتبع تاريخ انتقال المسؤولية للمحادثة</p>
                        </div>
                    </div>
                    <button 
                        type="button" 
                        @click="modalOpen = false" 
                        class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-200/60 hover:text-slate-700 transition"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="p-5 max-h-[60vh] overflow-y-auto space-y-4 hs-scrollbar">
                    <template x-if="modalLoading">
                        <div class="py-10 text-center text-xs text-slate-500">
                            <svg class="mx-auto h-6 w-6 animate-spin text-slate-400 mb-2" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            <span>جاري تحميل السجل...</span>
                        </div>
                    </template>

                    <template x-if="!modalLoading && currentLogs.length === 0">
                        <div class="py-10 text-center text-xs text-slate-500">
                            لا توجد تحويلات مسجلة لهذه المحادثة بعد.
                        </div>
                    </template>

                    <template x-if="!modalLoading && currentLogs.length > 0">
                        <div class="relative border-s-2 border-slate-200 ms-3 space-y-5">
                            <template x-for="log in currentLogs" :key="log.id">
                                <div class="relative ps-6">
                                    <div class="absolute -start-[7px] top-1 h-3 w-3 rounded-full border-2 border-white bg-blue-600 shadow-2xs"></div>
                                    <div class="rounded-xl border border-slate-100 bg-slate-50/70 p-3 shadow-2xs">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="font-bold text-xs text-slate-900" x-text="log.action_label"></span>
                                            <span class="text-[10px] text-slate-400" x-text="log.created_at_human || log.created_at"></span>
                                        </div>
                                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs">
                                            <span class="text-slate-500">المسؤول:</span>
                                            <span class="font-bold text-blue-700" x-text="log.new_name"></span>
                                            <template x-if="log.previous_name && log.previous_name !== '—'">
                                                <span class="text-slate-400 text-[11px]">(سابقاً: <span x-text="log.previous_name"></span>)</span>
                                            </template>
                                        </div>
                                        <template x-if="log.reason">
                                            <p class="mt-1 text-[11px] text-slate-600 bg-white rounded-lg p-2 border border-slate-100" x-text="'السبب: ' + log.reason"></p>
                                        </template>
                                        <div class="mt-2 text-[10px] text-slate-400 flex items-center gap-1">
                                            <span>بواسطة:</span>
                                            <span class="font-medium text-slate-600" x-text="log.actor_name"></span>
                                            <span class="opacity-50">·</span>
                                            <span x-text="log.created_at"></span>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>

                <div class="border-t border-slate-100 bg-slate-50 px-5 py-3 text-left">
                    <button 
                        type="button" 
                        @click="modalOpen = false" 
                        class="rounded-xl border border-slate-300 bg-white px-4 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition"
                    >
                        إغلاق
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function liveMonitorApp() {
            return {
                counts: @json($stats['counts']),
                employees: @json($stats['active_employees'], JSON_UNESCAPED_UNICODE),
                conversations: @json($stats['conversations'], JSON_UNESCAPED_UNICODE),
                filterType: 'all',
                searchQuery: '',
                pollInterval: null,
                modalOpen: false,
                modalLoading: false,
                currentLogs: [],

                init() {
                    let debounceTimer = null;
                    const debouncedRefresh = () => {
                        clearTimeout(debounceTimer);
                        debounceTimer = setTimeout(() => this.refreshData(), 400);
                    };

                    const workspaceId = {{ $workspace->id ?? 'null' }};
                    if (window.Echo && workspaceId) {
                        try {
                            window.Echo.private(`workspace.${workspaceId}.conversations`)
                                .listen('.conversation.updated', debouncedRefresh)
                                .listen('.message.created', debouncedRefresh);
                        } catch (_) {}
                    }

                    // Gentle background poll (every 15s instead of aggressive 3s)
                    this.pollInterval = setInterval(() => {
                        this.refreshData();
                    }, 15000);

                    document.addEventListener('hs:before-navigate', () => {
                        if (this.pollInterval) {
                            clearInterval(this.pollInterval);
                            this.pollInterval = null;
                        }
                    }, { once: true });
                },

                async refreshData() {
                    try {
                        const res = await fetch('{{ route('workspace.conversations.monitor.data') }}', {
                            headers: { 'Accept': 'application/json' }
                        });
                        if (res.ok) {
                            const data = await res.json();
                            this.counts = data.counts || this.counts;
                            this.employees = data.active_employees || this.employees;
                            this.conversations = data.conversations || this.conversations;
                        }
                    } catch (e) {
                        // ignore network blips
                    }
                },

                get filteredConversations() {
                    let list = this.conversations;
                    if (this.filterType !== 'all') {
                        list = list.filter(c => c.responder && c.responder.status === this.filterType);
                    }
                    if (this.searchQuery.trim() !== '') {
                        const q = this.searchQuery.toLowerCase();
                        list = list.filter(c => 
                            (c.customer_name && c.customer_name.toLowerCase().includes(q)) ||
                            (c.customer_phone && c.customer_phone.includes(q)) ||
                            (c.last_message && c.last_message.toLowerCase().includes(q))
                        );
                    }
                    return list;
                },

                async takeoverConversation(id) {
                    try {
                        const res = await fetch(`/workspace/conversations/${id}/takeover`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                            }
                        });
                        if (res.ok) {
                            await this.refreshData();
                        }
                    } catch (e) {}
                },

                async resumeAiConversation(id) {
                    try {
                        const res = await fetch(`/workspace/conversations/${id}/resume-ai`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                            }
                        });
                        if (res.ok) {
                            await this.refreshData();
                        }
                    } catch (e) {}
                },

                async openAuditModal(id) {
                    this.modalOpen = true;
                    this.modalLoading = true;
                    try {
                        const res = await fetch(`/workspace/conversations/${id}/responder-logs`, {
                            headers: { 'Accept': 'application/json' }
                        });
                        if (res.ok) {
                            const data = await res.json();
                            this.currentLogs = data.logs || [];
                        }
                    } catch (e) {
                        this.currentLogs = [];
                    } finally {
                        this.modalLoading = false;
                    }
                }
            };
        }
    </script>
</x-app-layout>
