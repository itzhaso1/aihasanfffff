@php
    $routePrefix = $routePrefix ?? 'workspace.finance.contracts';
    $allowDelete = $allowDelete ?? false;
@endphp

<article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
    <div class="mb-3 flex items-center justify-between">
        <h3 class="text-sm font-bold text-slate-900">المرفقات</h3>
        <p class="text-xs text-slate-500">{{ $contract->attachments->count() }} ملف</p>
    </div>

    @if($contract->attachments->isEmpty())
        <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-5 text-center text-sm text-slate-500">
            لا توجد مرفقات مضافة حتى الآن.
        </div>
    @else
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach($contract->attachments as $attachment)
                @php
                    $exists = \Illuminate\Support\Facades\Storage::disk('public')->exists($attachment->file_path);
                    $fileName = $attachment->file_name ?: basename((string) $attachment->file_path);
                    $mime = $attachment->file_type ?: 'unknown';
                    $sizeLabel = $attachment->file_size ? number_format(((int) $attachment->file_size) / 1024, 1).' KB' : '—';
                @endphp
                <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                    <div class="mb-2 flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-slate-900">{{ $fileName }}</p>
                            <p class="mt-1 text-[11px] text-slate-500">{{ $mime }}</p>
                        </div>
                        <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $exists ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">
                            {{ $exists ? 'متاح' : 'مفقود' }}
                        </span>
                    </div>

                    <p class="mb-3 text-[11px] text-slate-500">الحجم: {{ $sizeLabel }}</p>

                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ route($routePrefix.'.attachments.download', [$contract, $attachment]) }}" class="rounded-md border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-white">تنزيل</a>
                        @if($allowDelete)
                            <form method="POST" action="{{ route($routePrefix.'.attachments.destroy', [$contract, $attachment]) }}" onsubmit="return confirm('حذف المرفق؟')">
                                @csrf
                                @method('DELETE')
                                <button class="rounded-md border border-red-200 px-2 py-1 text-xs font-semibold text-red-600 hover:bg-red-50">حذف</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</article>
