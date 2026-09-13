@extends('layouts.financial', ['pageTitle' => 'العقود'])

@section('content')
    @php
        $routePrefix = $routePrefix ?? 'workspace.finance.contracts';
        $stats = $stats ?? ['open' => 0, 'draft' => 0, 'closed' => 0, 'expiring' => 0, 'value_open' => 0];
    @endphp

    <div class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-xl font-black text-slate-900">إدارة العقود</h2>
                <p class="mt-1 text-sm text-slate-500">العقد مربوطة بالعميل والمشروع والفواتير. التنبيه يظهر قبل الانتهاء بـ 30 يومًا.</p>
            </div>
            <a href="{{ route($routePrefix.'.create') }}" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">إنشاء عقد</a>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @include('workspace.finance.partials.kpi-card', ['label' => 'عقود سارية', 'value' => number_format((int) $stats['open'], 0), 'tone' => 'emerald'])
            @include('workspace.finance.partials.kpi-card', ['label' => 'تنتهي خلال 30 يومًا', 'value' => number_format((int) $stats['expiring'], 0), 'tone' => 'amber', 'href' => request()->fullUrlWithQuery(['expiring' => 1])])
            @include('workspace.finance.partials.kpi-card', ['label' => 'مسودات', 'value' => number_format((int) $stats['draft'], 0), 'tone' => 'slate'])
            @include('workspace.finance.partials.kpi-card', ['label' => 'قيمة العقود السارية', 'value' => $stats['value_open'], 'tone' => 'indigo'])
        </div>

        <form method="GET" class="grid gap-2 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-6">
            <input name="search" value="{{ $filters['search'] ?? '' }}" class="rounded-lg border-slate-300 text-sm md:col-span-2" placeholder="بحث برقم العقد أو العنوان أو العميل">
            <select name="status" class="rounded-lg border-slate-300 text-sm">
                <option value="">كل الحالات</option>
                @foreach(\App\Support\Finance\ContractPresentation::statusLabels() as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <select name="customer_id" class="rounded-lg border-slate-300 text-sm">
                <option value="">كل العملاء</option>
                @foreach($customers ?? [] as $customer)
                    <option value="{{ $customer->id }}" @selected((string) ($filters['customer_id'] ?? '') === (string) $customer->id)>{{ $customer->name }}</option>
                @endforeach
            </select>
            <select name="project_id" class="rounded-lg border-slate-300 text-sm">
                <option value="">كل المشاريع</option>
                @foreach($projects ?? [] as $project)
                    <option value="{{ $project->id }}" @selected((string) ($filters['project_id'] ?? '') === (string) $project->id)>{{ $project->name }}</option>
                @endforeach
            </select>
            <label class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 text-xs font-semibold text-slate-600">
                <input type="checkbox" name="expiring" value="1" @checked(!empty($filters['expiring'])) class="rounded border-slate-300">
                قاربت على الانتهاء
            </label>
            <div class="flex gap-2 md:col-span-6">
                <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">تطبيق</button>
                <a href="{{ route($routePrefix.'.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">إعادة ضبط</a>
            </div>
        </form>

        <div class="space-y-3 md:hidden">
            @forelse($contracts as $contract)
                @php $expiry = \App\Support\Finance\ContractPresentation::expiry($contract); @endphp
                <a href="{{ route($routePrefix.'.show', $contract) }}" class="block rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="font-bold">{{ $contract->contract_number }}</p>
                            <p class="text-sm text-slate-600">{{ $contract->title }}</p>
                        </div>
                        @include('workspace.finance.partials.status-badge', ['label' => \App\Support\Finance\ContractPresentation::statusLabel($contract->status), 'class' => \App\Support\Finance\ContractPresentation::statusBadgeClass($contract->status)])
                    </div>
                    <p class="mt-2 text-sm font-semibold">{{ number_format((float) $contract->value, 2) }} {{ $contract->currency }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $contract->customer?->name ?: 'بدون عميل' }} · {{ $contract->project?->name ?: 'بدون مشروع' }}</p>
                    <p class="mt-2 text-xs font-semibold {{ $expiry['severity'] === 'ok' || $expiry['severity'] === 'none' ? 'text-slate-500' : 'text-amber-700' }}">{{ $expiry['label'] }}</p>
                </a>
            @empty
                @include('workspace.finance.partials.empty-state', [
                    'title' => 'لا توجد عقود',
                    'body' => 'أنشئ عقدًا واربطه بعميل ومشروع ثم ولّد الفواتير منه.',
                    'actionHref' => route($routePrefix.'.create'),
                    'actionLabel' => 'إنشاء عقد',
                ])
            @endforelse
        </div>

        <div class="hidden overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm md:block">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="px-3 py-2 text-right">العقد</th>
                        <th class="px-3 py-2 text-right">العميل / المشروع</th>
                        <th class="px-3 py-2 text-right">الحالة</th>
                        <th class="px-3 py-2 text-right">القيمة / الفوترة</th>
                        <th class="px-3 py-2 text-right">الفترة</th>
                        <th class="px-3 py-2 text-left">إجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($contracts as $contract)
                        @php
                            $expiry = \App\Support\Finance\ContractPresentation::expiry($contract);
                            $invoiced = (float) $contract->invoices->sum('total');
                            $paid = (float) $contract->invoices->sum('amount_paid');
                        @endphp
                        <tr class="hover:bg-slate-50">
                            <td class="px-3 py-3">
                                <a href="{{ route($routePrefix.'.show', $contract) }}" class="font-bold text-slate-900 hover:text-[#0f7668]">{{ $contract->contract_number }}</a>
                                <p class="text-xs text-slate-500">{{ $contract->title }}</p>
                            </td>
                            <td class="px-3 py-3">
                                <p>{{ $contract->customer?->name ?: '—' }}</p>
                                <p class="text-xs text-slate-500">{{ $contract->project?->name ?: 'بدون مشروع' }}</p>
                            </td>
                            <td class="px-3 py-3">
                                @include('workspace.finance.partials.status-badge', ['label' => \App\Support\Finance\ContractPresentation::statusLabel($contract->status), 'class' => \App\Support\Finance\ContractPresentation::statusBadgeClass($contract->status)])
                                <div class="mt-1">@include('workspace.finance.partials.status-badge', ['label' => $expiry['label'], 'class' => \App\Support\Finance\ContractPresentation::expiryBadgeClass($expiry['severity'])])</div>
                            </td>
                            <td class="px-3 py-3">
                                <p class="font-semibold">{{ number_format((float) $contract->value, 2) }} {{ $contract->currency }}</p>
                                <p class="text-xs text-slate-500">مفوتر {{ number_format($invoiced, 2) }} · محصّل {{ number_format($paid, 2) }}</p>
                            </td>
                            <td class="px-3 py-3 text-xs text-slate-600">
                                {{ optional($contract->start_date)->format('Y-m-d') ?: '—' }} → {{ optional($contract->end_date)->format('Y-m-d') ?: '—' }}
                            </td>
                            <td class="px-3 py-3 text-left">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <a href="{{ route($routePrefix.'.show', $contract) }}" class="rounded-md border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">متابعة</a>
                                    <a href="{{ route($routePrefix.'.edit', $contract) }}" class="rounded-md border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">تعديل</a>
                                    <a href="{{ route($routePrefix.'.pdf', $contract) }}" class="rounded-md border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">PDF</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-8">
                                @include('workspace.finance.partials.empty-state', [
                                    'title' => 'لا توجد عقود حالياً',
                                    'actionHref' => route($routePrefix.'.create'),
                                    'actionLabel' => 'إنشاء عقد',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $contracts->links() }}</div>
    </div>
@endsection
