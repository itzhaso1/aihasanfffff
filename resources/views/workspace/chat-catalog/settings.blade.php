<x-communication-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold text-slate-800">إعدادات المنتجات والمخزون</h2>
                <p class="mt-0.5 text-xs text-slate-500">إدارة الهوية، الهيكلة التشغيلية، ساعات التوفر، لغة العرض، وتفضيلات التواصل.</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('workspace.chat-catalog.index') }}" class="text-sm font-medium text-slate-600 hover:text-slate-900">← المنتجات والمخزون</a>
            </div>
        </div>
    </x-slot>

    @php
        $display = $catalog->display_settings ?? [];
        $discovery = $catalog->discovery_settings ?? [];
        $channels = $catalog->channel_visibility ?? [];
        $policy = $catalog->customer_service_policy ?? [];
        $schedule = $catalog->availability_schedule ?? [];
        $seo = $catalog->seo ?? [];
        $profile = $catalog->communicationProfile;
        $days = ['monday' => 'الاثنين', 'tuesday' => 'الثلاثاء', 'wednesday' => 'الأربعاء', 'thursday' => 'الخميس', 'friday' => 'الجمعة', 'saturday' => 'السبت', 'sunday' => 'الأحد'];
        $weekly = collect($schedule['weekly'] ?? [])->keyBy('day');
    @endphp

    <div class="py-6" x-data="{ activeTab: 'general' }">
        <div class="mx-auto max-w-6xl space-y-6 px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')

            <!-- Operating Status Bar -->
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <span class="text-xs font-semibold text-slate-500">الحالة التشغيلية الحالية:</span>
                        <x-workspace.status-pill :value="$catalog->status->value" />
                        <span class="text-xs text-slate-500">({{ $catalog->status->value === 'published' ? 'منشور ومتاح للعملاء' : 'مخفي عن البحث المباشر' }})</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('workspace.chat-catalog.settings.draft') }}">
                            @csrf<button type="submit" class="rounded-xl border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 transition">تعيين كمسودة</button>
                        </form>
                        <form method="POST" action="{{ route('workspace.chat-catalog.settings.publish') }}">
                            @csrf<button type="submit" class="rounded-xl bg-emerald-600 px-3.5 py-1.5 text-xs font-medium text-white hover:bg-emerald-700 transition">نشر</button>
                        </form>
                        <form method="POST" action="{{ route('workspace.chat-catalog.settings.pause') }}">
                            @csrf<button type="submit" class="rounded-xl bg-amber-600 px-3.5 py-1.5 text-xs font-medium text-white hover:bg-amber-700 transition">إيقاف مؤقت</button>
                        </form>
                        <form method="POST" action="{{ route('workspace.chat-catalog.settings.archive') }}" onsubmit="return confirm('أرشفة المنتجات والمخزون؟')">
                            @csrf<button type="submit" class="rounded-xl bg-red-600 px-3.5 py-1.5 text-xs font-medium text-white hover:bg-red-700 transition">أرشفة</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Settings Tab Switcher -->
            <div class="flex flex-wrap gap-2 border-b border-slate-200 pb-3">
                <button type="button" @click="activeTab = 'general'" :class="activeTab === 'general' ? 'bg-slate-900 text-white font-medium' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'" class="rounded-xl px-4 py-2 text-xs transition">العام والهوية</button>
                <button type="button" @click="activeTab = 'branding'" :class="activeTab === 'branding' ? 'bg-slate-900 text-white font-medium' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'" class="rounded-xl px-4 py-2 text-xs transition">العلامة والبائع</button>
                <button type="button" @click="activeTab = 'display'" :class="activeTab === 'display' ? 'bg-slate-900 text-white font-medium' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'" class="rounded-xl px-4 py-2 text-xs transition">العرض والقنوات</button>
                <button type="button" @click="activeTab = 'schedule'" :class="activeTab === 'schedule' ? 'bg-slate-900 text-white font-medium' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'" class="rounded-xl px-4 py-2 text-xs transition">التوفر وساعات العمل</button>
                <button type="button" @click="activeTab = 'communication'" :class="activeTab === 'communication' ? 'bg-slate-900 text-white font-medium' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'" class="rounded-xl px-4 py-2 text-xs transition">اللغة والتواصل</button>
                <button type="button" @click="activeTab = 'seo'" :class="activeTab === 'seo' ? 'bg-slate-900 text-white font-medium' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'" class="rounded-xl px-4 py-2 text-xs transition">SEO والرسائل</button>
            </div>

            <form method="POST" action="{{ route('workspace.chat-catalog.settings.update') }}" class="space-y-6">
                @csrf
                @method('PUT')

                <!-- Section 1: General & Identity -->
                <div x-show="activeTab === 'general'" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
                    <h3 class="text-sm font-semibold text-slate-900">الهوية والإعدادات العامة</h3>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">الاسم <span class="text-red-500">*</span></label>
                            <input required name="name" value="{{ old('name', $catalog->name) }}" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" />
                            <x-input-error :messages="$errors->get('name')" class="mt-1" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">الرابط الفريد (Slug) <span class="text-red-500">*</span></label>
                            <input required name="slug" value="{{ old('slug', $catalog->slug) }}" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" />
                            <x-input-error :messages="$errors->get('slug')" class="mt-1" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">الاسم الداخلي</label>
                            <input name="internal_name" value="{{ old('internal_name', $catalog->internal_name) }}" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" />
                            <x-input-error :messages="$errors->get('internal_name')" class="mt-1" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">الاسم الظاهر للعملاء</label>
                            <input name="public_name" value="{{ old('public_name', $catalog->public_name) }}" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" />
                            <x-input-error :messages="$errors->get('public_name')" class="mt-1" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">العملة الافتراضية</label>
                            <input name="default_currency" maxlength="3" value="{{ old('default_currency', $catalog->default_currency) }}" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500 uppercase" />
                            <x-input-error :messages="$errors->get('default_currency')" class="mt-1" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">سياسة الفرز الافتراضية</label>
                            <select name="sort_policy" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500">
                                @foreach(['manual'=>'يدوي (Manual)','featured_first'=>'المميز أولاً','newest'=>'الأحدث','oldest'=>'الأقدم','price_low_high'=>'السعر تصاعدياً','price_high_low'=>'السعر تنازلياً','name_asc'=>'الاسم أ-ي','name_desc'=>'الاسم ي-أ','availability_first'=>'المتاح أولاً'] as $val => $lbl)
                                    <option value="{{ $val }}" @selected(old('sort_policy', $catalog->sort_policy->value) === $val)>{{ $lbl }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('sort_policy')" class="mt-1" />
                        </div>
                        <div class="sm:col-span-2 lg:col-span-3">
                            <label class="mb-1 block text-xs font-medium text-slate-700">الوصف العام للكتالوج</label>
                            <textarea name="description" rows="3" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500">{{ old('description', $catalog->description) }}</textarea>
                            <x-input-error :messages="$errors->get('description')" class="mt-1" />
                        </div>
                    </div>
                </div>

                <!-- Section 2: Branding & Seller -->
                <div x-show="activeTab === 'branding'" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
                    <h3 class="text-sm font-semibold text-slate-900">العلامة التجارية ومعلومات البائع</h3>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">اسم البائع الظاهر</label>
                            <input name="seller_public_display_name" value="{{ old('seller_public_display_name', $catalog->seller_public_display_name) }}" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">بريد دعم البائع</label>
                            <input type="email" name="seller_support_contact[email]" value="{{ old('seller_support_contact.email', data_get($catalog->seller_support_contact, 'email')) }}" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">هاتف دعم البائع</label>
                            <input name="seller_support_contact[phone]" value="{{ old('seller_support_contact.phone', data_get($catalog->seller_support_contact, 'phone')) }}" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">مسار الشعار (Logo Path)</label>
                            <input name="logo_path" value="{{ old('logo_path', $catalog->logo_path) }}" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">مسار الغلاف (Banner Path)</label>
                            <input name="banner_path" value="{{ old('banner_path', $catalog->banner_path) }}" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">اسم المنشأة القانوني</label>
                            <input name="legal_business_name" value="{{ old('legal_business_name', $catalog->legal_business_name) }}" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" />
                        </div>
                    </div>
                </div>

                <!-- Section 3: Display & Channels -->
                <div x-show="activeTab === 'display'" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
                    <h3 class="text-sm font-semibold text-slate-900">تفضيلات العرض وقنوات التواصل</h3>
                    
                    <div>
                        <h4 class="mb-2 text-xs font-semibold text-slate-700">إظهار/إخفاء عناصر السعر والمعلومات</h4>
                        <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-4">
                            @foreach([
                                'show_stock' => 'إظهار كمية المخزون',
                                'show_sku' => 'إظهار رمز SKU',
                                'show_brand' => 'إظهار العلامة',
                                'show_variants' => 'إظهار نسخ المنتجات',
                            ] as $dKey => $dLbl)
                                <label class="inline-flex items-center gap-2 text-xs text-slate-700">
                                    <input type="hidden" name="display_settings[{{ $dKey }}]" value="0">
                                    <input type="checkbox" name="display_settings[{{ $dKey }}]" value="1" @checked(old('display_settings.'.$dKey, $display[$dKey] ?? false)) class="rounded border-slate-300" />
                                    <span>{{ $dLbl }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="border-t pt-4">
                        <h4 class="mb-2 text-xs font-semibold text-slate-700">القنوات المفعلة للكتالوج</h4>
                        <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-5">
                            @foreach(['chat'=>'Chat','whatsapp'=>'WhatsApp','instagram'=>'Instagram','marketplace'=>'الماركت بلايس'] as $cKey => $cLbl)
                                <label class="inline-flex items-center gap-2 text-xs text-slate-700">
                                    <input type="hidden" name="channel_visibility[{{ $cKey }}]" value="0">
                                    <input type="checkbox" name="channel_visibility[{{ $cKey }}]" value="1" @checked(old('channel_visibility.'.$cKey, $channels[$cKey] ?? false)) class="rounded border-slate-300" />
                                    <span>{{ $cLbl }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>

                <!-- Section 4: Schedule & Availability -->
                <div x-show="activeTab === 'schedule'" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
                    <h3 class="text-sm font-semibold text-slate-900">ساعات التوفر والتشغيل</h3>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">نمط التوفر</label>
                            <select name="availability_mode" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500">
                                @foreach(['always_open'=>'متاح دائماً (24/7)','scheduled'=>'مجدول حسب ساعات العمل','temporarily_unavailable'=>'غير متاح مؤقتاً','maintenance'=>'تحت الصيانة'] as $val => $lbl)
                                    <option value="{{ $val }}" @selected(old('availability_mode', $catalog->availability_mode->value) === $val)>{{ $lbl }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">السلوك عند نفاد المنتج</label>
                            <select name="unavailable_item_behavior" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500">
                                @foreach(['hide'=>'إخفاء المنتج','show_unavailable'=>'إظهار كغير متاح','show_inquiry_only'=>'إظهار للاستفسار فقط','show_disable_purchase'=>'إظهار مع تعطيل الشراء'] as $val => $lbl)
                                    <option value="{{ $val }}" @selected(old('unavailable_item_behavior', $catalog->unavailable_item_behavior->value) === $val)>{{ $lbl }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Section 5: SEO -->
                <div x-show="activeTab === 'seo'" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
                    <h3 class="text-sm font-semibold text-slate-900">تحسين محركات البحث والرسائل المخصصة</h3>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">عنوان SEO للصفحة</label>
                            <input name="seo[title]" value="{{ old('seo.title', $seo['title'] ?? '') }}" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">Robots Directive</label>
                            <select name="seo[robots]" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500">
                                <option value="index,follow" @selected(old('seo.robots', $seo['robots'] ?? 'index,follow') === 'index,follow')>فهرسة ومتابعة (index, follow)</option>
                                <option value="noindex" @selected(old('seo.robots', $seo['robots'] ?? '') === 'noindex')>عدم الفهرسة (noindex)</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-700">وصف SEO (Meta Description)</label>
                            <textarea name="seo[description]" rows="2" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500">{{ old('seo.description', $seo['description'] ?? '') }}</textarea>
                        </div>
                    </div>
                </div>

                <!-- Submit Bar -->
                <div class="flex items-center justify-end border-t pt-4">
                    <button type="submit" class="rounded-xl bg-slate-900 px-6 py-2.5 text-xs font-medium text-white hover:bg-slate-800 shadow-sm transition">
                        حفظ الإعدادات
                    </button>
                </div>
            </form>

            <!-- Communication Settings Section -->
            <form method="POST" action="{{ route('workspace.chat-catalog.settings.communication.update') }}" x-show="activeTab === 'communication'" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
                @csrf
                @method('PUT')
                <h3 class="text-sm font-semibold text-slate-900">تفضيلات اللغة والتواصل مع العملاء</h3>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-700">اللغة الأساسية</label>
                        <select name="primary_language" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500">
                            <option value="ar" @selected(old('primary_language', $profile->primary_language) === 'ar')>العربية</option>
                            <option value="en" @selected(old('primary_language', $profile->primary_language) === 'en')>English</option>
                            <option value="bilingual" @selected(old('primary_language', $profile->primary_language) === 'bilingual')>ثنائي اللغة</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-700">اللهجة العربية في الإجابات</label>
                        <select name="arabic_dialect" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500">
                            @foreach(\App\Enums\ChatCatalog\ChatCatalogArabicDialect::cases() as $dialect)
                                <option value="{{ $dialect->value }}" @selected(old('arabic_dialect', $profile->arabic_dialect->value) === $dialect->value)>{{ $dialect->value }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-700">درجة الرسمية (Formality)</label>
                        <select name="formality" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500">
                            @foreach(['very_formal'=>'رسمية جداً','formal'=>'رسمية','neutral'=>'محايدة','friendly'=>'ودية','casual'=>'عفوية'] as $fVal => $fLbl)
                                <option value="{{ $fVal }}" @selected(old('formality', $profile->formality->value) === $fVal)>{{ $fLbl }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="flex items-center justify-end border-t pt-4">
                    <button type="submit" class="rounded-xl bg-slate-900 px-6 py-2.5 text-xs font-medium text-white hover:bg-slate-800 shadow-sm transition">
                        حفظ تفضيلات اللغة والتواصل
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-communication-layout>
