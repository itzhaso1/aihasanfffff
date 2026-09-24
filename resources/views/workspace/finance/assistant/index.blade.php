@extends('layouts.financial', ['pageTitle' => 'المساعد المالي'])

@section('content')
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold">المساعد المالي</h2>
                <p class="mt-1 text-sm text-slate-600">
                    محادثة طبيعية مع بيانات مساحة العمل الحالية. الأرقام تُجلب من السجلات فقط ولا تُختلق.
                </p>
            </div>
            @if($conversationId)
                <a href="{{ route('workspace.finance.assistant.index') }}" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                    محادثة جديدة
                </a>
            @endif
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="max-h-[32rem] space-y-3 overflow-y-auto p-4">
                @forelse($messages as $message)
                    <div @class([
                        'flex',
                        'justify-end' => $message['role'] === 'user',
                        'justify-start' => $message['role'] !== 'user',
                    ])>
                        <article @class([
                            'max-w-[85%] rounded-2xl px-4 py-3 text-sm leading-7 shadow-sm',
                            'bg-slate-900 text-white' => $message['role'] === 'user',
                            'border border-slate-200 bg-slate-50 text-slate-800' => $message['role'] !== 'user',
                        ])>
                            {!! nl2br(e($message['body'])) !!}
                        </article>
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-200 bg-slate-50 p-6 text-center text-sm text-slate-500">
                        ابدأ بسؤال مثل: «كم مبيعات هذا الشهر؟» أو «من أكثر عملائنا؟» أو «هل عندنا فواتير متأخرة؟»
                    </div>
                @endforelse
            </div>

            <form method="POST" action="{{ route('workspace.finance.assistant.ask') }}" class="border-t border-slate-200 p-4">
                @csrf
                @if($conversationId)
                    <input type="hidden" name="conversation_id" value="{{ $conversationId }}">
                @endif
                <label class="mb-2 block text-xs font-semibold text-slate-700">سؤالك</label>
                <textarea
                    name="question"
                    rows="3"
                    class="w-full rounded-lg border-slate-300 text-sm @error('question') border-red-400 @enderror"
                    placeholder="اكتب سؤالك هنا..."
                    required
                >{{ old('question', $question) }}</textarea>
                @error('question')
                    <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
                @enderror
                <button type="submit" class="mt-3 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    إرسال
                </button>
            </form>
        </div>
    </div>
@endsection
