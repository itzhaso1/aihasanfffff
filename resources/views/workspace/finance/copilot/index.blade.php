@extends('layouts.financial', ['pageTitle' => 'المساعد المالي'])

@section('content')
    <div class="space-y-4">
        <h2 class="text-xl font-bold">المساعد المالي</h2>
        @if(is_array($result ?? null))
            <p class="text-sm leading-7 text-slate-800">{{ $result['answer'] ?? '' }}</p>
            @if(! empty($result['requires_confirmation']))
                <p class="text-sm font-semibold text-amber-800">يتطلب تأكيد قبل إنشاء أي سجل.</p>
            @endif
        @endif
    </div>
@endsection
