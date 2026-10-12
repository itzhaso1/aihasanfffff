@php
    $itemClass = 'rounded-lg px-3 py-2 text-sm font-semibold';
    $activeClass = 'bg-teal-600 text-white';
    $idleClass = 'bg-slate-100 text-slate-700 hover:bg-slate-200';
@endphp

<nav aria-label="حساب العامل المستقل" class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('worker.inbox.home') }}" class="{{ $itemClass }} {{ request()->routeIs('worker.inbox.*') ? $activeClass : $idleClass }}">المحادثات</a>
        <form method="POST" action="{{ route('logout') }}" class="ms-auto">
            @csrf
            <button type="submit" class="{{ $itemClass }} border border-slate-300 bg-white text-slate-700 hover:bg-slate-50">تسجيل الخروج</button>
        </form>
    </div>
</nav>
