@extends('layouts.email', ['pageTitle' => 'هوية البريد الإلكتروني'])

@section('content')
    @php
        $headerLinks = old('header_links', $headerLinks);
    @endphp
    <script>
        window.emailBrandingPage = function (config) {
            return {
                tab: config.tab,
                viewport: 'desktop',
                previewHtml: config.previewHtml,
                logoPreview: config.logoUrl,
                logoDirty: false,
                primaryColor: config.primaryColor,
                secondaryColor: config.secondaryColor,
                backgroundColor: config.backgroundColor,
                textColor: config.textColor,
                previewHeight: 720,
                previewUrl: config.previewUrl,
                timer: null,
                async openPreview() {
                    this.tab = 'preview';
                    await this.refreshPreview();
                    this.resizePreview();
                },
                queuePreview() {
                    clearTimeout(this.timer);
                    this.timer = setTimeout(() => this.refreshPreview(), 180);
                },
                resizePreview() {
                    this.$nextTick(() => {
                        const iframe = this.$refs.previewFrame;
                        const doc = iframe?.contentDocument;
                        if (!doc) {
                            return;
                        }
                        iframe.style.height = '1px';
                        const height = Math.max(
                            doc.documentElement?.scrollHeight || 0,
                            doc.body?.scrollHeight || 0,
                            480,
                        );
                        this.previewHeight = height + 24;
                        iframe.style.height = this.previewHeight + 'px';
                    });
                },
                async refreshPreview() {
                    const form = this.$refs.form;
                    if (!form) {
                        return;
                    }
                    const response = await fetch(this.previewUrl, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'text/html',
                        },
                        body: new FormData(form),
                    });
                    if (!response.ok) {
                        return;
                    }
                    let html = await response.text();
                    const file = form.querySelector('input[name="logo"]')?.files?.[0];
                    if (file) {
                        const blobUrl = URL.createObjectURL(file);
                        html = html.replace(/<img([^>]+)src="[^"]+"/, '<img$1src="' + blobUrl + '"');
                    }
                    this.previewHtml = html;
                    this.resizePreview();
                },
                onLogoChange(event) {
                    const file = event.target.files?.[0];
                    this.logoPreview = file ? URL.createObjectURL(file) : config.logoUrl;
                    this.logoDirty = Boolean(file);
                    this.queuePreview();
                },
                init() {
                    if (this.tab === 'preview') {
                        this.$nextTick(() => this.openPreview());
                    }
                },
            };
        };
    </script>

    @php
        $colorSource = $templateValues ?? [];
        $identityColors = [
            'primaryColor' => \App\Support\Email\EmailLibraryBranding::swatch(old('primary_color', $colorSource['primaryColor'] ?? $branding->primary_color), \App\Support\Email\EmailLibraryBranding::DEFAULT_PRIMARY),
            'secondaryColor' => \App\Support\Email\EmailLibraryBranding::swatch(old('secondary_color', $colorSource['secondaryColor'] ?? $branding->secondary_color), \App\Support\Email\EmailLibraryBranding::DEFAULT_SECONDARY),
            'backgroundColor' => \App\Support\Email\EmailLibraryBranding::swatch(old('background_color', $colorSource['backgroundColor'] ?? $branding->background_color), \App\Support\Email\EmailLibraryBranding::DEFAULT_BACKGROUND),
            'textColor' => \App\Support\Email\EmailLibraryBranding::swatch(old('text_color', $colorSource['textColor'] ?? $branding->text_color), \App\Support\Email\EmailLibraryBranding::DEFAULT_TEXT),
        ];
    @endphp
    <div class="space-y-6"
         x-data="emailBrandingPage({
            tab: @js($tab),
            previewHtml: @js($previewHtml),
            logoUrl: @js($logoUrl),
            previewUrl: @js(route('workspace.emails.branding.preview')),
            primaryColor: @js($identityColors['primaryColor']),
            secondaryColor: @js($identityColors['secondaryColor']),
            backgroundColor: @js($identityColors['backgroundColor']),
            textColor: @js($identityColors['textColor']),
         })">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-xs font-semibold tracking-wide text-slate-500">تخصيص مظهر رسائل المستلمين</p>
                <h1 class="mt-1 text-2xl font-extrabold text-slate-900">هوية البريد الإلكتروني</h1>
                <p class="mt-2 max-w-2xl text-sm leading-7 text-slate-500">
                    تظهر هذه الهوية للمستلم وكأنها صادرة من شركتك. لا يُدرج اسم المنصة داخل الرسالة إلا إذا كتبته أنت.
                </p>
            </div>
            @include('workspace.emails.partials.branding-save-button')
        </div>

        <div class="flex flex-wrap gap-2 rounded-2xl border border-slate-200 bg-white p-2">
            @foreach([
                'general' => 'المحتوى العام',
                'colors' => 'الألوان',
                'header' => 'الهيدر',
                'footer' => 'الفوتر',
                'preview' => 'المعاينة',
            ] as $key => $label)
                <button type="button" @click="{{ $key === 'preview' ? 'openPreview()' : "tab = '{$key}'" }}"
                        :class="tab === '{{ $key }}' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50'"
                        class="rounded-xl px-4 py-2 text-sm font-semibold transition">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <form id="email-branding-form" x-ref="form" method="POST" action="{{ route('workspace.emails.branding.update') }}" enctype="multipart/form-data" class="space-y-6" @input="queuePreview()" @change="queuePreview()">
                @csrf
                @method('PUT')
                <input type="hidden" name="current_tab" :value="tab">
                <input type="hidden" name="primary_color" :value="primaryColor" value="{{ $identityColors['primaryColor'] }}">
                <input type="hidden" name="secondary_color" :value="secondaryColor" value="{{ $identityColors['secondaryColor'] }}">
                <input type="hidden" name="background_color" :value="backgroundColor" value="{{ $identityColors['backgroundColor'] }}">
                <input type="hidden" name="text_color" :value="textColor" value="{{ $identityColors['textColor'] }}">

                <section x-show="tab === 'general'" class="space-y-6">
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-base font-bold text-slate-900">معلومات الشركة</h2>
                        <div class="mt-5 grid gap-5 md:grid-cols-2">
                            <div class="md:col-span-1">
                                <label class="mb-2 block text-sm font-semibold text-slate-700">اسم الشركة</label>
                                <input name="company_name" value="{{ old('company_name', $branding->company_name) }}" required
                                       class="w-full rounded-2xl border-slate-200 text-sm" placeholder="شركة الأفق للتقنية">
                            </div>
                            <div class="md:col-span-1">
                                <label class="mb-2 block text-sm font-semibold text-slate-700">الوصف المختصر</label>
                                <input name="company_description" value="{{ old('company_description', $branding->company_description) }}"
                                       class="w-full rounded-2xl border-slate-200 text-sm" placeholder="حلول تقنية متقدمة">
                            </div>
                        </div>
                        <div class="mt-5">
                            <p class="mb-2 text-sm font-semibold text-slate-700">الشعار</p>
                            <div class="flex flex-wrap items-center gap-3">
                                <button type="button" @click="$refs.logoInput.click()" class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-dashed border-slate-300 bg-transparent">
                                    <template x-if="logoPreview">
                                        <img :src="logoPreview" alt="معاينة الشعار" class="h-10 w-10 bg-transparent object-contain">
                                    </template>
                                    <template x-if="!logoPreview">
                                        <span class="px-1 text-center text-[10px] leading-tight text-slate-400">رفع</span>
                                    </template>
                                </button>
                                <input x-ref="logoInput" type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="hidden" @change="onLogoChange($event)">
                                <div class="min-w-0 text-xs text-slate-500">
                                    <p>PNG أو JPG حتى 4MB</p>
                                    <button type="button" @click="$refs.logoInput.click()" class="mt-1 text-sm font-semibold text-[#2563EB] hover:underline">تغيير الشعار</button>
                                    @if($branding->logo_path)
                                        <label class="ms-3 inline-flex items-center gap-1 text-xs text-slate-600">
                                            <input type="checkbox" name="remove_logo" value="1" class="rounded border-slate-300">
                                            حذف
                                        </label>
                                    @endif
                                </div>
                                <button type="submit" x-show="logoDirty" x-cloak class="rounded-xl border-2 border-slate-900 bg-white px-4 py-2 text-sm font-bold text-slate-900 hover:bg-slate-900 hover:text-white">
                                    حفظ الشعار
                                </button>
                            </div>
                        </div>
                        <div class="mt-5">
                            <label class="mb-2 block text-sm font-semibold text-slate-700">نص المعاينة</label>
                            <input name="preview_subject" value="{{ old('preview_subject', $previewSubject) }}"
                                   class="mb-3 w-full rounded-2xl border-slate-200 text-sm" placeholder="موضوع الرسالة">
                            <textarea name="preview_body" rows="4" class="w-full rounded-2xl border-slate-200 text-sm">{{ old('preview_body', $previewBody) }}</textarea>
                        </div>
                    </div>

                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-base font-bold text-slate-900">معلومات التواصل</h2>
                        <div class="mt-5 grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="mb-2 block text-sm font-semibold text-slate-700">البريد الإلكتروني</label>
                                <input type="email" name="footer_email" value="{{ old('footer_email', $branding->footer_email) }}" class="w-full rounded-2xl border-slate-200 text-sm">
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-semibold text-slate-700">الموقع الإلكتروني</label>
                                <input type="url" name="footer_website" value="{{ old('footer_website', $branding->footer_website) }}" class="w-full rounded-2xl border-slate-200 text-sm" placeholder="https://">
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-semibold text-slate-700">رقم الهاتف</label>
                                <input name="footer_phone" value="{{ old('footer_phone', $branding->footer_phone) }}" class="w-full rounded-2xl border-slate-200 text-sm">
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-semibold text-slate-700">العنوان</label>
                                <input name="footer_address" value="{{ old('footer_address', $branding->footer_address) }}" class="w-full rounded-2xl border-slate-200 text-sm">
                            </div>
                        </div>
                    </div>

                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-base font-bold text-slate-900">روابط التواصل الاجتماعي</h2>
                        <div class="mt-5 grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="mb-2 block text-sm font-semibold text-slate-700">LinkedIn</label>
                                <input type="url" name="social_linkedin" value="{{ old('social_linkedin', $branding->social_linkedin) }}" class="w-full rounded-2xl border-slate-200 text-sm" placeholder="https://">
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-semibold text-slate-700">X</label>
                                <input type="url" name="social_x" value="{{ old('social_x', $branding->social_x) }}" class="w-full rounded-2xl border-slate-200 text-sm" placeholder="https://">
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-semibold text-slate-700">Facebook</label>
                                <input type="url" name="social_facebook" value="{{ old('social_facebook', $branding->social_facebook) }}" class="w-full rounded-2xl border-slate-200 text-sm" placeholder="https://">
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-semibold text-slate-700">Instagram</label>
                                <input type="url" name="social_instagram" value="{{ old('social_instagram', $branding->social_instagram) }}" class="w-full rounded-2xl border-slate-200 text-sm" placeholder="https://">
                            </div>
                            <div class="md:col-span-2">
                                <label class="mb-2 block text-sm font-semibold text-slate-700">YouTube</label>
                                <input type="url" name="social_youtube" value="{{ old('social_youtube', $branding->social_youtube) }}" class="w-full rounded-2xl border-slate-200 text-sm" placeholder="https://">
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end">
                        @include('workspace.emails.partials.branding-save-button')
                    </div>
                </section>

                <section x-show="tab === 'colors'" x-cloak class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-base font-bold text-slate-900">ألوان القالب</h2>
                    <p class="mt-2 text-sm text-slate-500">
                        @if(! empty($selectedTemplate) && ($selectedTemplate['view'] ?? null) !== 'emails.workspace-branded')
                            هذه الألوان من قالب
                            <span class="font-bold text-slate-800">{{ $selectedTemplate['name'] }}</span>
                            وتظهر في المعاينة والرسائل التي تستخدمه.
                            <a href="{{ route('workspace.emails.templates.edit') }}" class="font-semibold text-[#2563EB] hover:underline">تخصيص القالب</a>
                        @else
                            هذه الألوان الأربعة تُحفظ مع الهوية وتظهر إذا لم يُختر قالب من المكتبة.
                            <a href="{{ route('workspace.emails.templates.index') }}" class="font-semibold text-[#2563EB] hover:underline">اختر قالباً</a>
                        @endif
                    </p>
                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        @foreach([
                            'primaryColor' => 'اللون الرئيسي',
                            'secondaryColor' => 'اللون الثانوي',
                            'backgroundColor' => 'لون الخلفية',
                            'textColor' => 'لون النص',
                        ] as $model => $label)
                            <label class="rounded-2xl border border-slate-200 p-4">
                                <span class="mb-3 block text-sm font-semibold text-slate-700">{{ $label }}</span>
                                <div class="flex items-center gap-3">
                                    <input type="color" x-model="{{ $model }}" class="h-12 w-16 cursor-pointer rounded-xl border border-slate-300 bg-white">
                                    <input type="text" x-model="{{ $model }}" maxlength="7" dir="ltr" class="min-w-0 flex-1 rounded-xl border-slate-200 font-mono text-sm font-semibold text-black">
                                </div>
                            </label>
                        @endforeach
                    </div>
                    <div class="mt-6 flex justify-end">
                        @include('workspace.emails.partials.branding-save-button')
                    </div>
                </section>

                <section x-show="tab === 'header'" x-cloak class="space-y-6">
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-base font-bold text-slate-900">Header / Navbar</h2>
                        <div class="mt-5 grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="mb-2 block text-sm font-semibold text-slate-700">نمط الهيدر</label>
                                <select name="header_style" class="w-full rounded-2xl border-slate-200 text-sm">
                                    <option value="solid" @selected(old('header_style', $branding->header_style) === 'solid')>شريط لوني كامل</option>
                                    <option value="split" @selected(old('header_style', $branding->header_style) === 'split')>شعار وروابط منفصلان</option>
                                    <option value="minimal" @selected(old('header_style', $branding->header_style) === 'minimal')>بسيط بخط سفلي</option>
                                    <option value="centered" @selected(old('header_style', $branding->header_style) === 'centered')>وسط</option>
                                </select>
                            </div>
                            <div class="space-y-3 rounded-2xl bg-slate-50 p-4 text-sm">
                                <label class="flex items-center gap-2"><input type="hidden" name="show_header_logo" value="0"><input type="checkbox" name="show_header_logo" value="1" @checked(old('show_header_logo', $branding->show_header_logo)) class="rounded border-slate-300"> إظهار الشعار</label>
                                <label class="flex items-center gap-2"><input type="hidden" name="show_header_company_name" value="0"><input type="checkbox" name="show_header_company_name" value="1" @checked(old('show_header_company_name', $branding->show_header_company_name)) class="rounded border-slate-300"> إظهار اسم الشركة</label>
                            </div>
                        </div>
                    </div>
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-base font-bold text-slate-900">روابط اختيارية</h2>
                        <div class="mt-5 space-y-4">
                            @foreach($headerLinks as $index => $link)
                                <div class="grid gap-3 rounded-2xl border border-slate-100 p-4 md:grid-cols-[1fr_1fr_auto]">
                                    <input name="header_links[{{ $index }}][label]" value="{{ $link['label'] ?? '' }}" class="rounded-2xl border-slate-200 text-sm" placeholder="الرئيسية">
                                    <input type="url" name="header_links[{{ $index }}][url]" value="{{ $link['url'] ?? '' }}" class="rounded-2xl border-slate-200 text-sm" placeholder="https://">
                                    <label class="flex items-center gap-2 text-sm text-slate-600">
                                        <input type="hidden" name="header_links[{{ $index }}][visible]" value="0">
                                        <input type="checkbox" name="header_links[{{ $index }}][visible]" value="1" @checked(!empty($link['visible'])) class="rounded border-slate-300">
                                        إظهار
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-base font-bold text-slate-900">زر الإجراء</h2>
                        <div class="mt-5 grid gap-4 md:grid-cols-[1fr_1fr_auto]">
                            <input name="cta_label" value="{{ old('cta_label', $branding->cta_label) }}" class="rounded-2xl border-slate-200 text-sm" placeholder="ابدأ الآن">
                            <input type="url" name="cta_url" value="{{ old('cta_url', $branding->cta_url) }}" class="rounded-2xl border-slate-200 text-sm" placeholder="https://">
                            <label class="flex items-center gap-2 text-sm text-slate-600">
                                <input type="hidden" name="show_cta" value="0">
                                <input type="checkbox" name="show_cta" value="1" @checked(old('show_cta', $branding->show_cta)) class="rounded border-slate-300">
                                إظهار الزر
                            </label>
                        </div>
                    </div>
                    <div class="flex justify-end">
                        @include('workspace.emails.partials.branding-save-button')
                    </div>
                </section>

                <section x-show="tab === 'footer'" x-cloak class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-base font-bold text-slate-900">الفوتر</h2>
                    <div class="mt-5 grid gap-3 sm:grid-cols-2">
                        @foreach([
                            'show_footer_company_name' => 'اسم الشركة',
                            'show_footer_email' => 'البريد',
                            'show_footer_phone' => 'الهاتف',
                            'show_footer_website' => 'الموقع',
                            'show_footer_address' => 'العنوان',
                            'show_footer_social' => 'روابط التواصل',
                            'show_footer_copyright' => 'حقوق النشر',
                        ] as $name => $label)
                            <label class="flex items-center gap-2 rounded-2xl bg-slate-50 px-4 py-3 text-sm text-slate-700">
                                <input type="hidden" name="{{ $name }}" value="0">
                                <input type="checkbox" name="{{ $name }}" value="1" @checked(old($name, $branding->{$name})) class="rounded border-slate-300">
                                إظهار {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <div class="mt-5">
                        <label class="mb-2 block text-sm font-semibold text-slate-700">نص حقوق النشر</label>
                        <input name="footer_copyright" value="{{ old('footer_copyright', $branding->footer_copyright) }}" class="w-full rounded-2xl border-slate-200 text-sm" placeholder="يُولَّد تلقائياً من اسم الشركة إذا تُرك فارغاً">
                    </div>
                    <div class="mt-6 flex justify-end">
                        @include('workspace.emails.partials.branding-save-button')
                    </div>
                </section>

            <section x-show="tab === 'preview'" x-cloak class="rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-base font-bold text-slate-900">معاينة الرسالة كاملة</h2>
                        @if(! empty($selectedTemplate))
                            <p class="mt-1 text-xs text-slate-500">
                                القالب المختار:
                                <span class="font-bold text-slate-800">{{ $selectedTemplate['name'] }}</span>
                                <a href="{{ route('workspace.emails.templates.edit') }}" class="ms-2 font-semibold text-[#2563EB] hover:underline">تخصيص</a>
                                <a href="{{ route('workspace.emails.templates.index') }}" class="ms-2 font-semibold text-[#2563EB] hover:underline">تغيير القالب</a>
                            </p>
                        @else
                            <p class="mt-1 text-xs text-slate-500">
                                لم يُختر قالب بعد، لذلك تظهر الهوية الحالية.
                                <a href="{{ route('workspace.emails.templates.index') }}" class="font-semibold text-[#2563EB] hover:underline">اختر قالباً</a>
                            </p>
                        @endif
                    </div>
                    <div class="flex gap-2">
                        <button type="button" @click="viewport = 'desktop'; resizePreview()" :class="viewport === 'desktop' ? 'bg-slate-900 text-white' : 'text-slate-500'" class="rounded-lg px-3 py-1.5 text-xs font-semibold">سطح المكتب</button>
                        <button type="button" @click="viewport = 'mobile'; resizePreview()" :class="viewport === 'mobile' ? 'bg-slate-900 text-white' : 'text-slate-500'" class="rounded-lg px-3 py-1.5 text-xs font-semibold">الجوال</button>
                    </div>
                </div>
                <div class="bg-[#eef2f7] p-4 sm:p-8">
                    <div class="mx-auto overflow-visible rounded-2xl bg-white shadow-sm" :class="viewport === 'mobile' ? 'max-w-[390px]' : 'max-w-[720px]'">
                        <iframe x-ref="previewFrame"
                                :srcdoc="previewHtml"
                                title="معاينة البريد"
                                @load="resizePreview()"
                                class="block w-full border-0 bg-white"
                                :style="'height:' + previewHeight + 'px'"></iframe>
                    </div>
                </div>
                <div class="flex justify-end border-t border-slate-100 px-5 py-4">
                    @include('workspace.emails.partials.branding-save-button')
                </div>
            </section>
        </form>

        <div class="sticky bottom-4 z-20 flex justify-end pt-2">
            @include('workspace.emails.partials.branding-save-button')
        </div>
    </div>
@endsection
