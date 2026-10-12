<x-communication-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-xl font-semibold text-slate-800">قوالب بيانات المنتجات</h2>
                <p class="mt-1 max-w-2xl text-sm text-slate-600">قوالب اختيارية تساعدك على إدخال بيانات المنتجات بشكل منظم. يمكنك استخدامها للمنتجات التي تحتاج حقولًا إضافية، لكنها ليست مطلوبة لعرض المنتجات.</p>
            </div>
            <a href="{{ route('workspace.chat-catalog.index') }}" class="text-sm text-blue-600 hover:underline">← المنتجات والمخزون</a>
        </div>
    </x-slot>

    @php
        $systemTemplates = $templates->filter(fn ($template) => $template->is_system);
        $workspaceTemplates = $templates->reject(fn ($template) => $template->is_system);
    @endphp

    <div class="space-y-8">
        <section>
            <h3 class="mb-3 text-sm font-semibold text-slate-900">قوالب النظام</h3>
            <p class="mb-3 text-xs text-slate-500">للعرض والنسخ فقط. لا تغيّر شكل عرض المنتجات.</p>
            @include('workspace.chat-catalog.templates.partials.table', ['rows' => $systemTemplates])
        </section>
        <section>
            <h3 class="mb-3 text-sm font-semibold text-slate-900">قوالبي</h3>
            <p class="mb-3 text-xs text-slate-500">نسخ مخصصة لمساحة العمل.</p>
            @include('workspace.chat-catalog.templates.partials.table', ['rows' => $workspaceTemplates])
        </section>
    </div>
</x-communication-layout>
