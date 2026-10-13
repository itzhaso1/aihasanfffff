<x-communication-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-xl font-semibold text-slate-800">{{ $template->name }}</h2>
                <p class="text-sm text-slate-600">{{ $template->slug }} · v{{ $template->schema_version }}</p>
            </div>
            <a href="{{ route('workspace.chat-catalog.templates.index') }}" class="text-sm text-blue-600 hover:underline">← القوالب</a>
        </div>
    </x-slot>

    @if($template->is_system)
        <form method="POST" action="{{ route('workspace.chat-catalog.templates.clone', $template) }}" class="mb-6 rounded-xl border border-slate-200 bg-slate-50 p-4">
            @csrf
            <p class="mb-2 text-sm text-slate-700">انسخ قالب النظام إلى مساحة عملك للتخصيص.</p>
            <div class="flex flex-wrap gap-2">
                <input name="name" required class="rounded-lg border-slate-300 text-sm" value="{{ $template->name }} (نسخة)" />
                <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">نسخ</button>
            </div>
        </form>
    @endif

    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <h3 class="mb-3 text-sm font-bold text-slate-900">الحقول</h3>
        <ul class="space-y-3">
            @foreach($template->fields as $field)
                <li class="rounded-xl border border-slate-100 p-3 text-sm">
                    <div class="font-semibold">{{ data_get($field->label, 'ar') ?: $field->field_key }}</div>
                    <div class="mt-1 text-xs text-slate-500">
                        {{ $field->field_key }} · {{ $field->field_type->value ?? $field->field_type }} · {{ $field->level->value ?? $field->level }}
                        @if($field->required) · مطلوب @endif
                        @if(! $field->customer_visible) · مخفي عن العميل @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
</x-communication-layout>
