@extends('platform.layout')

@section('content')
    <div class="py-8" dir="rtl">
        <div class="mx-auto max-w-4xl px-4 space-y-6">
            @include('platform.partials.nav')
            @include('partials.flash')

            <div>
                <h1 class="text-xl font-bold text-gray-900">إعداد نموذج الذكاء الاصطناعي</h1>
                <p class="mt-1 text-sm text-gray-600">هذا هو المصدر الوحيد للموديل المستخدم في كامل الموقع: المحادثة، توليد المحتوى، التحليل، الوكلاء، وأي ميزة لاحقة. لا يمكن للواجهات أو مساحات العمل اختيار موديل مختلف.</p>
            </div>

            <form method="POST" action="{{ route('platform.ai.update') }}" class="space-y-6"
                  x-data="{
                    provider: @js(old('provider', $selected->provider)),
                    fallbackProvider: @js(old('fallback_provider', $selected->fallbackProvider ?? '')),
                    catalog: @js($catalog),
                    modelsFor(id) {
                        const item = this.catalog.find((entry) => entry.id === id);
                        return item ? item.models : [];
                    }
                  }">
                @csrf
                @method('PUT')

                <section class="rounded-xl border bg-white p-6 space-y-4">
                    <h2 class="text-lg font-semibold">الموديل المعتمد</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">المزود</label>
                            <select name="provider" x-model="provider" required class="w-full rounded-lg border-gray-300">
                                @foreach ($catalog as $entry)
                                    <option value="{{ $entry['id'] }}" @selected(old('provider', $selected->provider) === $entry['id'])>{{ $entry['label'] }}</option>
                                @endforeach
                            </select>
                            @error('provider')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">الموديل</label>
                            <select name="model" required class="w-full rounded-lg border-gray-300">
                                <template x-for="model in modelsFor(provider)" :key="model">
                                    <option :value="model" :selected="model === @js(old('model', $selected->model))" x-text="model"></option>
                                </template>
                            </select>
                            @error('model')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    @if ($selected->usingFallback)
                        <p class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">الموديل المحدد حالياً خارج القائمة المسموحة، لذلك يعمل الموقع بالموديل الاحتياطي.</p>
                    @endif
                </section>

                <section class="rounded-xl border bg-white p-6 space-y-4">
                    <h2 class="text-lg font-semibold">احتياطي المنصة (اختياري)</h2>
                    <p class="text-sm text-gray-600">يُستخدم فقط إذا أصبح الموديل المعتمد غير مسموح في إعدادات الخادم. لا يوجد احتياطي ثابت في الواجهات أو الخدمات.</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">المزود الاحتياطي</label>
                            <select name="fallback_provider" x-model="fallbackProvider" class="w-full rounded-lg border-gray-300">
                                <option value="">بدون احتياطي</option>
                                @foreach ($catalog as $entry)
                                    <option value="{{ $entry['id'] }}" @selected(old('fallback_provider', $selected->fallbackProvider) === $entry['id'])>{{ $entry['label'] }}</option>
                                @endforeach
                            </select>
                            @error('fallback_provider')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">الموديل الاحتياطي</label>
                            <select name="fallback_model" class="w-full rounded-lg border-gray-300">
                                <option value="">بدون احتياطي</option>
                                <template x-for="model in modelsFor(fallbackProvider)" :key="'fb-'+model">
                                    <option :value="model" :selected="model === @js(old('fallback_model', $selected->fallbackModel))" x-text="model"></option>
                                </template>
                            </select>
                            @error('fallback_model')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </section>

                <button class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white">حفظ إعداد النموذج</button>
            </form>
        </div>
    </div>
@endsection
