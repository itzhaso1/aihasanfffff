@extends('layouts.financial', ['pageTitle' => 'إنشاء عقد'])

@section('content')
    <div class="space-y-4">
        <h2 class="text-xl font-bold text-slate-900">إنشاء عقد جديد</h2>
        @include('workspace.contracts._form')
    </div>
@endsection
