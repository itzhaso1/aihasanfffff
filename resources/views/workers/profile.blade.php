<x-guest-layout title="ملف العامل المستقل">
    <div dir="rtl" class="mx-auto max-w-5xl space-y-6 px-4 py-8">
        <x-worker-account-nav />
        @include('partials.flash')

        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h1 class="text-2xl font-extrabold text-slate-900">ملف العامل المستقل</h1>
            <p class="mt-2 text-sm text-slate-600">هذه البيانات تخص حسابك على مستوى المنصة، ولا تنشئ عضوية في أي مساحة عمل.</p>
            @if($errors->any())
                <div class="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">
                    <ul class="list-inside list-disc space-y-1">
                        @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif
            <div class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                <div class="rounded-xl bg-slate-50 p-3"><span class="font-bold">حالة الحساب:</span> {{ $profile->lifecycleStatusLabel() }}</div>
                <div class="rounded-xl bg-slate-50 p-3"><span class="font-bold">حالة التحقق:</span> {{ $profile->verificationStatusLabel() }}</div>
                <div class="rounded-xl bg-slate-50 p-3"><span class="font-bold">التوفر:</span> {{ $profile->availabilityStatusLabel() }}</div>
            </div>
            @if($verificationNotice)
                <div class="mt-4 rounded-xl bg-red-50 p-4 text-sm text-red-800">
                    <p class="font-bold">{{ $verificationNotice['title'] }}</p>
                    <p class="mt-2">{{ $verificationNotice['next'] }}</p>
                    @if($verificationNotice['reason'])
                        <p class="mt-2"><span class="font-bold">سبب المراجعة:</span> {{ $verificationNotice['reason'] }}</p>
                    @endif
                </div>
            @endif
            @if($profile->user->email_verified_at === null)
                <p class="mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-800">
                    يجب تأكيد البريد الإلكتروني قبل رفع مستندات التحقق أو إرسال الملف للمراجعة.
                    <a class="font-bold underline" href="{{ route('verification.notice') }}">تأكيد البريد</a>
                </p>
            @endif
        </div>

        <form method="POST" action="{{ route('worker.profile.update') }}" enctype="multipart/form-data" class="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            @csrf
            @method('PATCH')
            <h2 class="text-lg font-bold text-slate-900">البيانات المهنية والهوية الخاصة</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <x-input-label for="display_name" value="الاسم المعروض" />
                    <x-text-input id="display_name" class="mt-1 block w-full" name="display_name" :value="old('display_name', $profile->display_name)" required />
                </div>
                <div>
                    <x-input-label for="profile_photo" value="الصورة الشخصية" />
                    <input id="profile_photo" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" type="file" name="profile_photo" accept=".jpg,.jpeg,.png,.webp">
                </div>
                <div>
                    <x-input-label for="country_code" value="الدولة" />
                    <select id="country_code" name="country_code" class="mt-1 block w-full rounded-lg border-slate-300">
                        <option value="JO" @selected(old('country_code', $profile->country_code) === 'JO')>الأردن</option>
                        <option value="SA" @selected(old('country_code', $profile->country_code) === 'SA')>السعودية</option>
                    </select>
                </div>
                <div>
                    <x-input-label for="nationality" value="الجنسية" />
                    <x-text-input id="nationality" class="mt-1 block w-full" name="nationality" :value="old('nationality', $profile->nationality)" required />
                </div>
                <div>
                    <x-input-label for="sales_experience_level" value="مستوى خبرة المبيعات" />
                    <x-text-input id="sales_experience_level" class="mt-1 block w-full" name="sales_experience_level" :value="old('sales_experience_level', $profile->sales_experience_level)" required />
                </div>
                <div>
                    <x-input-label for="availability_status" value="التوفر" />
                    <select id="availability_status" name="availability_status" class="mt-1 block w-full rounded-lg border-slate-300">
                        @foreach(['available' => 'متاح', 'limited' => 'متاح بشكل محدود', 'unavailable' => 'غير متاح'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('availability_status', $profile->availability_status) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-center gap-3 rounded-lg bg-slate-50 p-3">
                    <input type="hidden" name="is_discoverable" value="0">
                    <input id="is_discoverable" type="checkbox" name="is_discoverable" value="1" class="rounded border-slate-300" @checked(filter_var(old('is_discoverable', $profile->discoverySettings?->is_discoverable ?? false), FILTER_VALIDATE_BOOLEAN))>
                    <x-input-label for="is_discoverable" value="السماح للشركات بالعثور على ملفي المهني" />
                </div>
                <div class="md:col-span-2">
                    <x-input-label for="professional_summary" value="الوصف المهني" />
                    <textarea id="professional_summary" name="professional_summary" rows="4" required class="mt-1 block w-full rounded-lg border-slate-300">{{ old('professional_summary', $profile->professional_summary) }}</textarea>
                </div>
                <div>
                    <x-input-label for="legal_name" value="الاسم القانوني (خاص)" />
                    <x-text-input id="legal_name" class="mt-1 block w-full" name="legal_name" :value="old('legal_name', $profile->privateIdentity?->legal_name)" required />
                </div>
                <div>
                    <x-input-label for="date_of_birth" value="تاريخ الميلاد (خاص)" />
                    <x-text-input id="date_of_birth" class="mt-1 block w-full" type="date" name="date_of_birth" :value="old('date_of_birth', $profile->privateIdentity?->date_of_birth?->format('Y-m-d'))" required />
                </div>
                <div>
                    <x-input-label for="identity_number" value="رقم الهوية (خاص)" />
                    <x-text-input id="identity_number" class="mt-1 block w-full" name="identity_number" :value="old('identity_number', $profile->privateIdentity?->identity_number)" required />
                </div>
                <div>
                    <x-input-label for="address" value="العنوان (خاص، اختياري)" />
                    <x-text-input id="address" class="mt-1 block w-full" name="address" :value="old('address', $profile->privateIdentity?->address)" />
                </div>
            </div>
            <button class="rounded-xl bg-[#067e6b] px-5 py-2.5 text-sm font-bold text-white">حفظ الملف</button>
        </form>

        </form>

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-bold text-slate-900">المهارات واللغات والخبرة</h2>
            <form method="POST" action="{{ route('worker.skills.sync') }}" class="mt-4 space-y-3">
                @csrf
                @method('PUT')
                <div class="flex flex-wrap gap-3">
                    @foreach($skillCatalog as $skill)
                        <label class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm">
                            <input type="checkbox" name="skill_ids[]" value="{{ $skill->id }}" @checked($profile->skills->contains('id', $skill->id))>
                            {{ $skill->display_name }}
                        </label>
                    @endforeach
                </div>
                <button class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-bold text-white">حفظ المهارات</button>
            </form>
            <form method="POST" action="{{ route('worker.languages.sync') }}" class="mt-6 space-y-3">
                @csrf
                @method('PUT')
                <div class="flex flex-wrap gap-3">
                    @foreach($languageCatalog as $language)
                        <label class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm">
                            <input type="checkbox" name="language_codes[]" value="{{ $language['code'] }}" @checked($profile->languages->contains('language_code', $language['code']))>
                            {{ $language['display_name'] }}
                        </label>
                    @endforeach
                </div>
                <button class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-bold text-white">حفظ اللغات</button>
            </form>
            <form method="POST" action="{{ route('worker.experiences.store') }}" class="mt-6 grid gap-3 md:grid-cols-2">
                @csrf
                <input name="title" class="rounded-lg border-slate-300" placeholder="المسمى" required>
                <input name="organization_name" class="rounded-lg border-slate-300" placeholder="الجهة" required>
                <input name="started_on" type="date" class="rounded-lg border-slate-300" required>
                <input name="ended_on" type="date" class="rounded-lg border-slate-300">
                <label class="flex items-center gap-2 text-sm">
                    <input type="hidden" name="is_current" value="0">
                    <input type="checkbox" name="is_current" value="1"> خبرة حالية
                </label>
                <textarea name="description" class="rounded-lg border-slate-300 md:col-span-2" rows="2" placeholder="وصف مهني مختصر"></textarea>
                <button class="rounded-lg bg-[#067e6b] px-4 py-2 text-sm font-bold text-white md:col-span-2">إضافة خبرة</button>
            </form>
            <div class="mt-4 space-y-4 text-sm">
                @forelse($profile->experiences as $experience)
                    @php($editingExperience = (string) old('experience_id') === (string) $experience->id)
                    <form method="POST" action="{{ route('worker.experiences.update', $experience) }}" class="grid gap-3 rounded-xl border border-slate-200 p-4 md:grid-cols-2">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="experience_id" value="{{ $experience->id }}">
                        <input name="title" value="{{ $editingExperience ? old('title', $experience->title) : $experience->title }}" class="rounded-lg border-slate-300" placeholder="المسمى" required>
                        <input name="organization_name" value="{{ $editingExperience ? old('organization_name', $experience->organization_name) : $experience->organization_name }}" class="rounded-lg border-slate-300" placeholder="الجهة" required>
                        <input name="started_on" type="date" value="{{ $editingExperience ? old('started_on', $experience->started_on?->toDateString()) : $experience->started_on?->toDateString() }}" class="rounded-lg border-slate-300" required>
                        <input name="ended_on" type="date" value="{{ $editingExperience ? old('ended_on', $experience->ended_on?->toDateString()) : $experience->ended_on?->toDateString() }}" class="rounded-lg border-slate-300">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="hidden" name="is_current" value="0">
                            <input type="checkbox" name="is_current" value="1" @checked($editingExperience ? filter_var(old('is_current'), FILTER_VALIDATE_BOOLEAN) : $experience->is_current)> خبرة حالية
                        </label>
                        <textarea name="description" class="rounded-lg border-slate-300 md:col-span-2" rows="2" placeholder="وصف مهني مختصر">{{ $editingExperience ? old('description', $experience->description) : $experience->description }}</textarea>
                        <div class="flex items-center justify-between gap-3 md:col-span-2">
                            <button class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-bold text-white">حفظ التعديل</button>
                            <button type="submit" form="delete-experience-{{ $experience->id }}" class="text-red-700">حذف</button>
                        </div>
                    </form>
                    <form id="delete-experience-{{ $experience->id }}" method="POST" action="{{ route('worker.experiences.destroy', $experience) }}" class="hidden">
                        @csrf
                        @method('DELETE')
                    </form>
                @empty
                    <p class="py-3 text-slate-500">لا توجد خبرات مهنية بعد.</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-bold text-slate-900">مستندات التحقق</h2>
            <p class="mt-1 text-sm text-slate-600">يتم حفظ الملفات على التخزين الخاص ولا تظهر للشركات.</p>
            @if($requiredDocuments === [])
                <p class="mt-2 text-sm text-slate-500">لا توجد أنواع مستندات إلزامية معتمدة حالياً لهذه الدولة.</p>
            @else
                <ul class="mt-2 list-inside list-disc text-sm text-slate-700">
                    @foreach($requiredDocuments as $documentType)
                        <li>{{ $documentType }}</li>
                    @endforeach
                </ul>
            @endif
            <form method="POST" action="{{ route('worker.documents.store') }}" enctype="multipart/form-data" class="mt-4 grid gap-3 md:grid-cols-[1fr_1fr_auto]">
                @csrf
                <input name="document_type_code" class="rounded-lg border-slate-300" placeholder="رمز نوع المستند" required>
                <input name="document" type="file" class="rounded-lg border-slate-300 text-sm" accept=".pdf,.jpg,.jpeg,.png,.webp" required>
                <button class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-bold text-white" @disabled($profile->user->email_verified_at === null)>رفع آمن</button>
            </form>
            <div class="mt-5 divide-y divide-slate-100">
                @forelse($profile->documents as $document)
                    <div class="flex items-center justify-between gap-3 py-3 text-sm">
                        <span>{{ $document->document_type_code }} — {{ $document->original_name }}</span>
                        <span class="text-slate-500">{{ $document->status }}</span>
                    </div>
                @empty
                    <p class="py-4 text-sm text-slate-500">لم يتم رفع مستندات بعد.</p>
                @endforelse
            </div>
        </section>

        <form method="POST" action="{{ route('worker.verification.submit') }}" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            @csrf
            <h2 class="text-lg font-bold text-slate-900">إرسال للمراجعة</h2>
            <p class="mt-1 text-sm text-slate-600">بعد الإرسال، يراجع فريق المنصة البيانات والمستندات وفق السياسات المعتمدة.</p>
            <button class="mt-4 rounded-xl bg-[#06C2A4] px-5 py-2.5 text-sm font-bold text-white" @disabled($profile->user->email_verified_at === null)>إرسال طلب التحقق</button>
        </form>
    </div>
</x-guest-layout>
