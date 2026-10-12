@extends('layouts.email', ['pageTitle' => 'الرسائل الواردة'])

@section('content')
    <div class="space-y-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-base font-bold text-slate-900">صندوق الوارد</h2>
                <span class="rounded-full bg-[#E8FAF6] px-2 py-1 text-xs font-semibold text-[#0f7668]">{{ $messages->total() }} رسالة</span>
            </div>
            <form method="GET" action="{{ route('workspace.emails.inbox') }}" class="mt-3 grid gap-3 md:grid-cols-3">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">الحساب</label>
                    <select name="account_id" class="w-full rounded-lg border-slate-300 text-sm">
                        @foreach($accounts as $account)
                            <option value="{{ $account->id }}" @selected($currentAccount && $currentAccount->id === $account->id)>
                                {{ $account->name }} ({{ $account->email }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-semibold text-slate-600">بحث</label>
                    <div class="flex gap-2">
                        <input type="text" name="search" value="{{ $search }}" placeholder="ابحث بالعنوان أو المرسل أو المحتوى"
                               class="w-full rounded-lg border-slate-300 text-sm">
                        <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">بحث</button>
                    </div>
                </div>
            </form>
        </div>

        @include('workspace.emails.partials.messages-table', [
            'type' => 'inbound',
            'messages' => $messages,
            'currentAccount' => $currentAccount,
            'search' => $search,
        ])
    </div>
@endsection
