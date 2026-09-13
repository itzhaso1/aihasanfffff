@extends('layouts.email', ['pageTitle' => 'تفاصيل الرسالة'])

@section('content')
    @php
        $backRoute = $returnTo === 'sent' ? 'workspace.emails.sent' : 'workspace.emails.inbox';
    @endphp

    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <a href="{{ route($backRoute, array_filter(['account_id' => $accountId, 'search' => $search ?: null])) }}"
               class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                العودة إلى القائمة
            </a>
            <a href="{{ route('workspace.emails.compose', ['account_id' => $message->email_account_id, 'reply_to_message_id' => $message->id]) }}"
               class="rounded-lg bg-[#06C2A4] px-3 py-2 text-xs font-semibold text-white hover:bg-[#05ab91]">
                رد على الرسالة
            </a>
        </div>

        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="grid gap-3 md:grid-cols-2">
                <div>
                    <p class="text-xs font-semibold text-slate-500">من</p>
                    <p class="text-sm text-slate-900">{{ $message->sender }}</p>
                </div>
                <div>
                    <p class="text-xs font-semibold text-slate-500">إلى</p>
                    <p class="text-sm text-slate-900">{{ $message->recipient }}</p>
                </div>
                <div>
                    <p class="text-xs font-semibold text-slate-500">التاريخ</p>
                    <p class="text-sm text-slate-900">{{ $message->created_at?->format('Y-m-d H:i:s') }}</p>
                </div>
                <div>
                    <p class="text-xs font-semibold text-slate-500">اسم الشركة / الحساب</p>
                    <p class="text-sm text-slate-900">{{ $message->account?->name ?? '-' }}</p>
                </div>
            </div>

            <div class="mt-5 border-t border-slate-200 pt-4">
                <h2 class="text-lg font-bold text-slate-900">{{ $message->subject ?: '(بدون عنوان)' }}</h2>
                <div class="mt-3 whitespace-pre-line text-sm leading-7 text-slate-700">{{ $message->body }}</div>
            </div>

            @if($message->attachments->isNotEmpty())
                <div class="mt-5 border-t border-slate-200 pt-4">
                    <h3 class="text-sm font-semibold text-slate-800">المرفقات</h3>
                    <div class="mt-2 space-y-1">
                        @foreach($message->attachments as $attachment)
                            <a class="block text-sm text-[#06C2A4] hover:underline"
                               href="{{ route('workspace.emails.attachments.download', $attachment) }}"
                               target="_blank">
                                {{ basename($attachment->file_path) }} ({{ $attachment->file_size }} bytes)
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </article>

        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-sm font-bold text-slate-900">سلسلة المحادثة</h3>
            <div class="space-y-2">
                @foreach($threadMessages as $threadMessage)
                    <article class="rounded-xl border border-slate-200 p-3">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-slate-500">{{ $threadMessage->created_at?->format('Y-m-d H:i') }}</p>
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">{{ $threadMessage->type }}</span>
                        </div>
                        <p class="mt-1 text-sm font-semibold text-slate-900">{{ $threadMessage->subject ?: '(بدون عنوان)' }}</p>
                        <p class="mt-1 line-clamp-2 text-xs text-slate-600">{{ $threadMessage->sender }} → {{ $threadMessage->recipient }}</p>
                    </article>
                @endforeach
            </div>
        </section>
    </div>
@endsection
