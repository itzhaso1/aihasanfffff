@extends('platform.layout')

@section('content')
    <div class="py-8" dir="rtl">
        <div class="mx-auto max-w-4xl px-4 space-y-6">
            @include('platform.partials.nav')
            @include('partials.flash')

            <div>
                <h1 class="text-xl font-bold text-gray-900">الموقع العام</h1>
                <p class="mt-1 text-sm text-gray-600">كل ما يظهر للزوار على الموقع العام: الاسم، الشعار، أيقونة التبويب، بيانات الشركة، وروابط تحميل التطبيقات. شعار مساحة العمل يبقى في لوحة المساحة وليس هنا.</p>
            </div>

            <form method="POST" action="{{ route('platform.site.update') }}" enctype="multipart/form-data" class="space-y-6">
                @csrf
                @method('PUT')

                <section class="rounded-xl border bg-white p-6 space-y-4">
                    <h2 class="text-lg font-semibold">هوية الموقع</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">الاسم الكامل بالعربية</label>
                            <input name="name_ar" value="{{ old('name_ar', $site['name_ar']) }}" required class="w-full rounded-lg border-gray-300">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">الاسم بالإنجليزية</label>
                            <input name="name_en" value="{{ old('name_en', $site['name_en']) }}" required class="w-full rounded-lg border-gray-300">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">اسم التطبيق / التبويب</label>
                            <input name="app_name" value="{{ old('app_name', $site['app_name']) }}" required class="w-full rounded-lg border-gray-300">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">السطر الصغير تحت الاسم</label>
                            <input name="tagline" value="{{ old('tagline', $site['tagline']) }}" class="w-full rounded-lg border-gray-300">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-sm font-medium">وصف الموقع في التذييل</label>
                            <textarea name="description" rows="3" class="w-full rounded-lg border-gray-300">{{ old('description', $site['description']) }}</textarea>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-sm font-medium">اسم الشركة في التذييل</label>
                            <input name="footer_company" value="{{ old('footer_company', $site['footer_company']) }}" class="w-full rounded-lg border-gray-300">
                        </div>
                    </div>
                </section>

                <section class="rounded-xl border bg-white p-6 space-y-6">
                    <h2 class="text-lg font-semibold">شعار الموقع وأيقونة التبويب</h2>
                    <div class="flex items-center gap-4">
                        <img src="{{ $logoUrl }}" alt="شعار الموقع الحالي" class="h-16 w-16 rounded-xl object-cover border">
                        <div class="flex-1 space-y-2">
                            <label class="block text-sm font-medium">شعار الموقع</label>
                            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="block w-full text-sm">
                            <p class="text-xs text-gray-500">PNG أو JPG أو WebP، حتى 2MB. يظهر في الهيدر والتسجيل وصفحات التحميل.</p>
                            @if($site['logo_path'])
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" name="remove_logo" value="1" class="rounded border-gray-300">
                                    إعادة الشعار الافتراضي
                                </label>
                            @endif
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <img src="{{ $faviconUrl }}" alt="أيقونة التبويب الحالية" class="h-10 w-10 rounded-lg object-cover border">
                        <div class="flex-1 space-y-2">
                            <label class="block text-sm font-medium">أيقونة التبويب (Favicon)</label>
                            <input type="file" name="favicon" accept="image/png,image/jpeg,image/webp" class="block w-full text-sm">
                            <p class="text-xs text-gray-500">اختياري. إن تُركت فارغة يُستخدم شعار الموقع.</p>
                            @if($site['favicon_path'])
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" name="remove_favicon" value="1" class="rounded border-gray-300">
                                    حذف أيقونة التبويب المخصصة
                                </label>
                            @endif
                        </div>
                    </div>
                </section>

                <section class="rounded-xl border bg-white p-6 space-y-4">
                    <h2 class="text-lg font-semibold">بيانات الشركة الظاهرة للزوار</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">رقم التواصل</label>
                            <input name="phone" value="{{ old('phone', $site['phone']) }}" required class="w-full rounded-lg border-gray-300">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">السجل التجاري</label>
                            <input name="commercial_registration" value="{{ old('commercial_registration', $site['commercial_registration']) }}" required class="w-full rounded-lg border-gray-300">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-sm font-medium">العنوان</label>
                            <input name="address" value="{{ old('address', $site['address']) }}" required class="w-full rounded-lg border-gray-300">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">الرقم الضريبي (اختياري)</label>
                            <input name="tax_number" value="{{ old('tax_number', $site['tax_number']) }}" class="w-full rounded-lg border-gray-300">
                            <p class="mt-1 text-xs text-gray-500">يُعرض للزوار فقط إذا وُجد. لا تضع قيمة تجريبية.</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">البريد الإلكتروني العام (اختياري)</label>
                            <input type="email" name="email" value="{{ old('email', $site['email']) }}" class="w-full rounded-lg border-gray-300">
                            <p class="mt-1 text-xs text-gray-500">يُعرض في الصفحات القانونية فقط إذا وُجد.</p>
                        </div>
                    </div>
                </section>

                <section class="rounded-xl border bg-white p-6 space-y-5">
                    <div>
                        <h2 class="text-lg font-semibold">روابط تحميل التطبيقات</h2>
                        <p class="mt-1 text-sm text-gray-600">اترك الحقل فارغًا إذا لم يُنشر التطبيق على تلك المنصة. لا تضع روابط تجريبية.</p>
                    </div>
                    @foreach([
                        'chat' => 'حاسم شات',
                        'finance' => 'حاسم المالية',
                        'cashier' => 'حاسم الكاشير',
                    ] as $key => $label)
                        <div class="rounded-lg border border-gray-100 bg-gray-50 p-4 space-y-3">
                            <h3 class="text-sm font-bold">{{ $label }}</h3>
                            <div class="grid gap-3 sm:grid-cols-3">
                                <div>
                                    <label class="mb-1 block text-xs">Android / Google Play</label>
                                    <input type="url" name="{{ $key }}_google_play_url" value="{{ old($key.'_google_play_url', $site[$key.'_google_play_url']) }}" class="w-full rounded-lg border-gray-300 text-sm" placeholder="https://">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs">Windows</label>
                                    <input type="url" name="{{ $key }}_windows_url" value="{{ old($key.'_windows_url', $site[$key.'_windows_url']) }}" class="w-full rounded-lg border-gray-300 text-sm" placeholder="https://">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs">iOS / App Store</label>
                                    <input type="url" name="{{ $key }}_app_store_url" value="{{ old($key.'_app_store_url', $site[$key.'_app_store_url']) }}" class="w-full rounded-lg border-gray-300 text-sm" placeholder="https://">
                                </div>
                            </div>
                        </div>
                    @endforeach
                </section>

                <button class="rounded-lg bg-blue-600 px-5 py-2.5 text-white">حفظ إعدادات الموقع</button>
            </form>
        </div>
    </div>
@endsection
