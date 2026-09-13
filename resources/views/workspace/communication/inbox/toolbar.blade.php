<div class="border-b border-slate-200 bg-white px-3 py-3 sm:px-4">
    <div class="flex flex-col gap-3 xl:flex-row xl:items-center">
        <form method="GET" action="{{ route('workspace.conversations.index') }}" class="flex min-w-0 flex-1 flex-col gap-2 md:flex-row md:items-center">
            @if(request()->filled('conversation'))
                <input type="hidden" name="conversation" value="{{ request('conversation') }}">
            @endif
            <label class="relative min-w-0 flex-1">
                <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-slate-400">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="m21 21-4.35-4.35M17 10.5A6.5 6.5 0 1 1 4 10.5a6.5 6.5 0 0 1 13 0Z" />
                    </svg>
                </span>
                <input
                    name="search"
                    value="{{ request('search') }}"
                    class="hs-input pr-10"
                    placeholder="ابحث باسم العميل أو رقم الهاتف أو External ID..."
                />
            </label>
            <select name="channel" class="hs-input md:w-40">
                <option value="">كل القنوات</option>
                @foreach($availableChannels as $channelKey => $channelLabel)
                    <option value="{{ $channelKey }}" @selected($channelFilter === $channelKey)>{{ $channelLabel }}</option>
                @endforeach
            </select>
            <select name="status" class="hs-input md:w-36">
                <option value="">جميع الحالات</option>
                @foreach(['open' => 'مفتوحة', 'closed' => 'مغلقة', 'archived' => 'مؤرشفة'] as $statusKey => $statusLabel)
                    <option value="{{ $statusKey }}" @selected(request('status') === $statusKey)>{{ $statusLabel }}</option>
                @endforeach
            </select>
            <label class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600">
                <input type="checkbox" name="unread" value="1" class="rounded border-slate-300 text-[#06C2A4] focus:ring-[#06C2A4]" @checked(request()->boolean('unread'))>
                غير مقروءة
            </label>
            <button class="hs-btn-secondary shrink-0">تصفية</button>
        </form>
        <a href="{{ route('workspace.conversations.create') }}" class="hs-btn-primary shrink-0">
            + محادثة جديدة
        </a>
    </div>
</div>
