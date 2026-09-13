<div class="pointer-events-none fixed left-4 top-20 z-[70] w-full max-w-sm space-y-2">
    @if(session('success'))
        <div
            x-data="{ open: true }"
            x-init="setTimeout(() => open = false, 3500)"
            x-show="open"
            x-transition
            class="pointer-events-auto rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 shadow-sm"
            role="status"
        >
            <p class="font-semibold">تمت العملية بنجاح</p>
            <p class="mt-1">{{ session('success') }}</p>
        </div>
    @endif

    @if(session('error'))
        <div
            x-data="{ open: true }"
            x-init="setTimeout(() => open = false, 5000)"
            x-show="open"
            x-transition
            class="pointer-events-auto rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800 shadow-sm"
            role="alert"
        >
            <p class="font-semibold">حدث خطأ</p>
            <p class="mt-1">{{ session('error') }}</p>
        </div>
    @endif

    @if($errors->any())
        <div
            x-data="{ open: true }"
            x-init="setTimeout(() => open = false, 7000)"
            x-show="open"
            x-transition
            class="pointer-events-auto rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800 shadow-sm"
            role="alert"
        >
            <p class="font-semibold">يرجى مراجعة البيانات</p>
            <ul class="mt-1 list-disc space-y-1 pr-5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
