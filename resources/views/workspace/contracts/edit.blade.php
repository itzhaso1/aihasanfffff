@extends('layouts.financial', ['pageTitle' => 'تعديل العقد'])

@section('content')
    <div class="space-y-4">
        <h2 class="text-xl font-bold text-slate-900">تعديل العقد {{ $contract->contract_number }}</h2>
        @include('workspace.contracts._form')
        @include('workspace.contracts.partials.attachments-panel', [
            'contract' => $contract,
            'routePrefix' => $routePrefix ?? 'workspace.finance.contracts',
            'allowDelete' => true,
        ])
    </div>
@endsection
