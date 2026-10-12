<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">قدرات ارتباط العامل</h2></x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-4xl space-y-6 px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')

            @php
                $capabilityPresentation = [
                    'inbox.messages.read' => [
                        'title' => 'قراءة الرسائل الواردة',
                        'description' => 'قراءة رسائل صندوق الوارد المعيّنة للعامل.',
                    ],
                    'inbox.messages.send' => [
                        'title' => 'إرسال رسائل الوارد',
                        'description' => 'إرسال الردود من صندوق الوارد.',
                    ],
                    'customers.view' => [
                        'title' => 'عرض العملاء',
                        'description' => 'عرض سجلات العملاء ضمن مساحة العمل.',
                    ],
                    'customers.phone.view' => [
                        'title' => 'عرض أرقام هواتف العملاء',
                        'description' => 'عرض أرقام هواتف العملاء عند منح هذه الصلاحية.',
                    ],
                    'customers.email.view' => [
                        'title' => 'عرض عناوين البريد الإلكتروني للعملاء',
                        'description' => 'عرض عناوين البريد الإلكتروني للعملاء عند منح هذه الصلاحية.',
                    ],
                    'catalog.products.view' => [
                        'title' => 'عرض المنتجات',
                        'description' => 'عرض كتالوج منتجات مساحة العمل.',
                    ],
                    'commerce.orders.view' => [
                        'title' => 'عرض الطلبات',
                        'description' => 'عرض معلومات الطلبات ضمن مساحة العمل.',
                    ],
                    'commerce.orders.create' => [
                        'title' => 'إنشاء الطلبات',
                        'description' => 'إنشاء الطلبات من خلال عملية مساحة العمل المصرّح بها.',
                    ],
                    'commerce.payment_links.create' => [
                        'title' => 'إنشاء روابط الدفع',
                        'description' => 'إنشاء روابط دفع من خلال عملية مساحة العمل المصرّح بها.',
                    ],
                    'reports.view' => [
                        'title' => 'عرض التقارير',
                        'description' => 'عرض تقارير مساحة العمل.',
                    ],
                    'email.view' => [
                        'title' => 'عرض البريد',
                        'description' => 'عرض رسائل البريد وبياناتها للارتباط المصرّح به.',
                    ],
                    'stories.view' => [
                        'title' => 'عرض التحديثات',
                        'description' => 'عرض تحديثات مساحة العمل دون نشر أو حذف التحديثات.',
                    ],
                ];
            @endphp

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
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-bold text-slate-900">{{ $engagement->workerProfile->display_name }}</h1>
                        <p class="mt-1 text-sm text-slate-600">إدارة قدرات هذا الارتباط فقط.</p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold">{{ $engagement->status->label() }}</span>
                </div>

                <p class="mt-6 rounded-lg bg-amber-50 p-4 text-sm text-amber-800">
                    لا تمنح هذه الصفحة صلاحيات مساحة العمل أو صلاحيات موظفي الشركة. لا تصبح القدرة فعالة للعامل إلا أثناء ارتباط نشط.
                </p>

                <div class="mt-6 divide-y">
                    @forelse($capabilities as $capability)
                        @continue($capability->capability_key === 'commerce.payment_links.create')
                        @php($permission = $permissions->get($capability->capability_key))
                        @php($presentation = $capabilityPresentation[$capability->capability_key] ?? [
                            'title' => 'صلاحية تشغيلية إضافية',
                            'description' => 'أداة متاحة للعامل ضمن هذا الارتباط.',
                        ])
                        <div class="flex flex-wrap items-center justify-between gap-4 py-4">
                            <div>
                                <h2 class="font-semibold text-slate-900">{{ $presentation['title'] }}</h2>
                                <p class="mt-1 font-mono text-xs text-slate-500">{{ $capability->capability_key }}</p>
                                <p class="mt-1 text-sm text-slate-600">{{ $presentation['description'] }}</p>
                            </div>
                            @if($canManage)
                                @if($permission?->allowed === true)
                                    <form method="POST" action="{{ route('workspace.worker-engagements.capabilities.destroy', [$engagement, $capability->capability_key]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white">سحب</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('workspace.worker-engagements.capabilities.store', $engagement) }}">
                                        @csrf
                                        <input type="hidden" name="capability_key" value="{{ $capability->capability_key }}">
                                        <button class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">منح</button>
                                    </form>
                                @endif
                            @else
                                <span class="rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-600">
                                    {{ $permission?->allowed === true ? 'ممنوحة' : 'غير ممنوحة' }}
                                </span>
                            @endif
                        </div>
                    @empty
                        <p class="py-4 text-sm text-slate-500">لا توجد قدرات معرفة حالياً.</p>
                    @endforelse
                </div>

                <a href="{{ route('workspace.worker-engagements.show', $engagement) }}" class="mt-6 inline-block text-sm font-semibold text-slate-600 underline">العودة إلى الارتباط</a>
            </div>
        </div>
    </div>
</x-app-layout>
