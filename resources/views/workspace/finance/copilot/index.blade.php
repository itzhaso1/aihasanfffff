@extends('layouts.financial', ['pageTitle' => 'المساعد المالي'])

@section('content')
    <div class="space-y-4">
        <h2 class="text-xl font-bold">المساعد المالي</h2>
        <p class="text-sm text-slate-600">الإجابات تُحسب من بيانات مساحة العمل الحالية فقط. الأرقام لا تُختلق. إنشاء الفواتير من الدردشة يتطلب تأكيدًا ولا يُنفَّذ تلقائيًا.</p>
        <form method="POST" action="{{ route('workspace.finance.copilot.ask') }}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            @csrf
            <label class="mb-2 block text-xs font-semibold">السؤال</label>
            <textarea name="question" rows="3" class="w-full rounded-lg border-slate-300 text-sm" placeholder="كم مبيعات هذا الشهر؟">{{ $question }}</textarea>
            <button class="mt-3 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">اسأل</button>
        </form>
        @if(!empty($result))
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-sm leading-7">{{ $result['answer'] }}</p>
                @if(!empty($result['range']))
                    <p class="mt-2 text-xs text-slate-500">النطاق: {{ $result['range']['from'] }} → {{ $result['range']['to'] }}</p>
                @endif
                @if(!empty($result['requires_confirmation']))
                    <p class="mt-2 text-xs font-semibold text-amber-700">يتطلب تأكيد المستخدم. لم يتم تنفيذ أي قيد مالي.</p>
                @endif
            </article>
        @endif
    </div>
@endsection
