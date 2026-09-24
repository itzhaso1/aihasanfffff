@extends('layouts.email', ['pageTitle' => 'تخصيص القالب'])

@section('content')
    <script>
        window.emailTemplateEditor = function (config) {
            return {
                previewHtml: config.previewHtml,
                previewHeight: 960,
                previewUrl: config.previewUrl,
                timer: null,
                queuePreview() {
                    clearTimeout(this.timer);
                    this.timer = setTimeout(() => this.refreshPreview(), 180);
                },
                resizePreview() {
                    this.$nextTick(() => {
                        const iframe = this.$refs.previewFrame;
                        const doc = iframe?.contentDocument;
                        if (!doc) {
                            return;
                        }
                        iframe.style.height = '1px';
                        const height = Math.max(doc.documentElement?.scrollHeight || 0, doc.body?.scrollHeight || 0, 900);
                        this.previewHeight = height + 24;
                        iframe.style.height = this.previewHeight + 'px';
                    });
                },
                async refreshPreview() {
                    const form = this.$refs.form;
                    if (!form) {
                        return;
                    }
                    const response = await fetch(this.previewUrl, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'text/html',
                        },
                        body: new FormData(form),
                    });
                    if (!response.ok) {
                        return;
                    }
                    this.previewHtml = await response.text();
                    this.resizePreview();
                },
            };
        };
    </script>

    <div class="space-y-6"
         x-data="emailTemplateEditor({
            previewHtml: @js($previewHtml),
            previewUrl: @js(route('workspace.emails.templates.live-preview')),
         })">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <a href="{{ route('workspace.emails.templates.category', $template['category']) }}" class="text-sm font-semibold text-[#2563EB] hover:underline">رجوع للمكتبة</a>
                <h1 class="mt-2 text-2xl font-extrabold text-slate-900">تخصيص {{ $template['name'] }}</h1>
                <p class="mt-1 text-sm text-slate-500">يُحفظ مفتاح القالب والإعدادات فقط، بدون نسخة HTML.</p>
            </div>
            <button type="submit" form="email-template-form" class="inline-flex items-center gap-2 rounded-2xl border-2 border-black bg-white px-5 py-3 text-sm font-bold text-black hover:bg-slate-100">
                <svg class="h-5 w-5 shrink-0" fill="none" stroke="#000000" stroke-width="2.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                حفظ
            </button>
        </div>

        <form id="email-template-form" x-ref="form" method="POST" action="{{ route('workspace.emails.templates.update') }}" class="grid gap-6 xl:grid-cols-[minmax(0,18rem)_minmax(0,1fr)]" @input="queuePreview()" @change="queuePreview()">
            @csrf
            @method('PUT')

            <section class="space-y-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-bold text-slate-900">إعدادات القالب</h2>
                @foreach($fields as $field)
                    @if($field['type'] === 'logo')
                        <div>
                            <p class="mb-2 text-sm font-semibold text-slate-700">{{ $field['label'] }}</p>
                            @if(! empty($values['logoUrl']))
                                <img src="{{ $values['logoUrl'] }}" alt="" class="h-14 w-14 bg-transparent object-contain">
                            @else
                                <p class="text-xs text-slate-500">يُدار الشعار من هوية البريد الإلكتروني.</p>
                            @endif
                        </div>
                    @elseif($field['type'] === 'textarea')
                        <label class="block">
                            <span class="mb-2 block text-sm font-semibold text-slate-700">{{ $field['label'] }}</span>
                            <textarea name="{{ $field['key'] }}" rows="5" class="w-full rounded-2xl border-slate-200 text-sm">{{ old($field['key'], $values[$field['key']] ?? '') }}</textarea>
                        </label>
                    @elseif($field['type'] === 'color')
                        @php
                            $colorFallback = match ($field['key']) {
                                'secondaryColor' => \App\Support\Email\EmailLibraryBranding::DEFAULT_SECONDARY,
                                'backgroundColor' => \App\Support\Email\EmailLibraryBranding::DEFAULT_BACKGROUND,
                                'textColor' => \App\Support\Email\EmailLibraryBranding::DEFAULT_TEXT,
                                default => \App\Support\Email\EmailLibraryBranding::DEFAULT_PRIMARY,
                            };
                            $colorValue = \App\Support\Email\EmailLibraryBranding::swatch(old($field['key'], $values[$field['key']] ?? null), $colorFallback);
                        @endphp
                        <label class="block rounded-2xl border border-slate-200 p-3" x-data="{ value: @js($colorValue) }">
                            <span class="mb-2 block text-sm font-semibold text-slate-700">{{ $field['label'] }}</span>
                            <div class="flex items-center gap-3">
                                <input type="hidden" name="{{ $field['key'] }}" :value="value" value="{{ $colorValue }}">
                                <input type="color" x-model="value" class="h-12 w-16 cursor-pointer rounded-xl border border-slate-300 bg-white">
                                <input type="text" x-model="value" maxlength="7" dir="ltr" class="min-w-0 flex-1 rounded-xl border-slate-200 font-mono text-sm font-semibold text-black">
                            </div>
                        </label>
                    @elseif($field['type'] === 'checkbox')
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="hidden" name="{{ $field['key'] }}" value="0">
                            <input type="checkbox" name="{{ $field['key'] }}" value="1" @checked(old($field['key'], $values[$field['key']] ?? false)) class="rounded border-slate-300">
                            {{ $field['label'] }}
                        </label>
                    @else
                        <label class="block">
                            <span class="mb-2 block text-sm font-semibold text-slate-700">{{ $field['label'] }}</span>
                            <input type="{{ $field['type'] === 'url' ? 'url' : 'text' }}" name="{{ $field['key'] }}" value="{{ old($field['key'], $values[$field['key']] ?? '') }}" class="w-full rounded-2xl border-slate-200 text-sm">
                        </label>
                    @endif
                @endforeach
                <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-2xl border-2 border-black bg-white px-5 py-3 text-sm font-bold text-black hover:bg-slate-100">
                    <svg class="h-5 w-5 shrink-0" fill="none" stroke="#000000" stroke-width="2.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    حفظ
                </button>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 class="text-base font-bold text-slate-900">معاينة مباشرة</h2>
                    <p class="mt-1 text-xs text-slate-500">نفس ملف القالب في المكتبة، مع ألوان الهوية أو تعديلاتك.</p>
                </div>
                <div class="bg-[#eef2f7] p-3 sm:p-4">
                    <iframe x-ref="previewFrame"
                            :srcdoc="previewHtml"
                            title="معاينة القالب"
                            @load="resizePreview()"
                            class="block w-full border-0 bg-white"
                            :style="'min-height:calc(100vh - 14rem);height:' + previewHeight + 'px'"></iframe>
                </div>
            </section>
        </form>
    </div>
@endsection
