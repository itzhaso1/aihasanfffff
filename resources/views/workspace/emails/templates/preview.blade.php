@extends('layouts.email', ['pageTitle' => 'معاينة '.$template['name']])

@section('content')
    <div class="space-y-4"
         x-data="{
            previewHtml: @js($previewHtml),
            previewHeight: 960,
            resizePreview() {
                this.$nextTick(() => {
                    const iframe = this.$refs.previewFrame;
                    const doc = iframe?.contentDocument;
                    if (!doc) {
                        return;
                    }
                    iframe.style.height = '1px';
                    const height = Math.max(
                        doc.documentElement?.scrollHeight || 0,
                        doc.body?.scrollHeight || 0,
                        960,
                    );
                    this.previewHeight = height + 32;
                    iframe.style.height = this.previewHeight + 'px';
                });
            },
         }">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <a href="{{ route('workspace.emails.templates.category', $template['category']) }}" class="text-sm font-semibold text-[#2563EB] hover:underline">رجوع للتصنيف</a>
                <h1 class="mt-2 text-2xl font-extrabold text-slate-900">{{ $template['name'] }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ $template['name_en'] }}</p>
            </div>
            <form method="POST" action="{{ route('workspace.emails.templates.use', $template['key']) }}">
                @csrf
                <button type="submit" class="rounded-2xl bg-black px-5 py-3 text-sm font-bold text-white hover:bg-slate-800">استخدام القالب</button>
            </form>
        </div>

        <div class="-mx-4 overflow-hidden border-y border-slate-200 bg-[#eef2f7] sm:-mx-6 lg:-mx-8">
            <div class="mx-auto max-w-[1100px] p-3 sm:p-6">
                <iframe x-ref="previewFrame"
                        :srcdoc="previewHtml"
                        title="معاينة القالب"
                        @load="resizePreview()"
                        class="block w-full border-0 bg-white shadow-sm"
                        :style="'min-height:calc(100vh - 12rem);height:' + previewHeight + 'px'"></iframe>
            </div>
        </div>
    </div>
@endsection
