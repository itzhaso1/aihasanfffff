<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">عرض ارتباط عامل مستقل</h2></x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl space-y-6 px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')

            @if($errors->any())
                <div class="rounded-xl bg-red-50 p-4 text-sm text-red-700">
                    <ul class="list-inside list-disc space-y-1">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="rounded-xl border bg-white p-6">
                <h1 class="text-2xl font-bold text-slate-900">إنشاء عرض ارتباط</h1>
                <p class="mt-2 text-sm text-slate-600">أدخل معرّف عامل موثّق تعرفه مسبقاً. لا يوفر هذا النموذج بحثاً أو دليلاً عاماً للعمال.</p>

                <form method="POST" action="{{ route('workspace.worker-engagements.store') }}" class="mt-6 grid gap-4">
                    @csrf
                    <div>
                        <x-input-label for="worker_profile_id" value="معرّف ملف العامل" />
                        <x-text-input id="worker_profile_id" class="mt-1 block w-full" type="number" name="worker_profile_id" :value="old('worker_profile_id')" required />
                    </div>
                    <div>
                        <x-input-label for="job_title" value="الدور أو المسمى" />
                        <x-text-input id="job_title" class="mt-1 block w-full" name="job_title" :value="old('job_title')" />
                    </div>
                    <div>
                        <x-input-label for="job_description" value="وصف العمل" />
                        <textarea id="job_description" name="job_description" rows="4" maxlength="5000" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm" placeholder="اشرح للعامل المطلوب منه وما يجب إنجازه">{{ old('job_description') }}</textarea>
                        <p class="mt-1 text-xs text-slate-500">سيظهر هذا الوصف للعامل ضمن تفاصيل الارتباط.</p>
                    </div>
                    @include('workspace.worker-engagements._duration-fields')
                    @include('workspace.worker-engagements._guide-fields', ['guide' => null])
                    @include('workspace.worker-engagements._product-selection', ['products' => $products])
                    <div class="flex items-center justify-between gap-3">
                        <a href="{{ route('workspace.worker-engagements.index') }}" class="text-sm font-semibold text-slate-600 underline">إلغاء</a>
                        <button class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white">إرسال العرض</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
