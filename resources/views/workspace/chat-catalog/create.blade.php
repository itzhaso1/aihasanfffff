<x-communication-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold text-slate-800">إضافة منتج</h2>
            <a href="{{ route('workspace.chat-catalog.index') }}" class="text-sm font-medium text-slate-600 hover:text-slate-900">← المنتجات والمخزون</a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-5xl px-4 space-y-6">
            @include('workspace.partials.nav')
            @include('partials.flash')

            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <form method="POST" enctype="multipart/form-data" action="{{ route('workspace.chat-catalog.store') }}" x-data="{ submitting: false }" @submit="submitting = true" class="space-y-6">
                    @csrf
                    @include('workspace.chat-catalog.form')

                    <div class="flex items-center justify-end gap-3 border-t pt-4">
                        <a href="{{ route('workspace.chat-catalog.index') }}" class="rounded-xl border border-slate-300 px-5 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50 transition">إلغاء</a>
                        <button type="submit" :disabled="submitting" class="rounded-xl bg-slate-900 px-6 py-2.5 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50 transition">
                            <span x-show="!submitting">حفظ المنتج</span>
                            <span x-show="submitting">جاري الحفظ...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-communication-layout>
