<x-communication-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold text-slate-800">تعديل صنف: {{ $item->name }}</h2>
            <div class="flex items-center gap-3">
                <a href="{{ route('public.chat-catalog.show', ['workspaceSlug' => $item->workspace->slug]) }}" target="_blank" class="text-xs text-blue-600 hover:underline">معاينة في الصفحة العامة ↗</a>
                <a href="{{ route('workspace.chat-catalog.index') }}" class="text-sm font-medium text-slate-600 hover:text-slate-900">← العودة للكتالوج</a>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-5xl px-4 space-y-6">
            @include('workspace.partials.nav')
            @include('partials.flash')

            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <form method="POST" enctype="multipart/form-data" action="{{ route('workspace.chat-catalog.update', $item) }}" x-data="{ submitting: false }" @submit="submitting = true" class="space-y-6">
                    @csrf
                    @method('PUT')

                    @include('workspace.chat-catalog.form')

                    <div class="flex items-center justify-between border-t pt-4">
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-slate-500">الحالة: {{ $item->is_active ? 'نشط' : 'متوقف' }}</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <a href="{{ route('workspace.chat-catalog.index') }}" class="rounded-xl border border-slate-300 px-5 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50 transition">إلغاء</a>
                            <button type="submit" :disabled="submitting" class="rounded-xl bg-slate-900 px-6 py-2.5 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50 transition">
                                <span x-show="!submitting">حفظ التغييرات</span>
                                <span x-show="submitting">جاري التحديث...</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-communication-layout>
