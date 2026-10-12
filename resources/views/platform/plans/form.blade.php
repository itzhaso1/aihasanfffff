@php
    /** @var \App\Models\Plan|null $planData */
    $planData = isset($plan) ? $plan : null;
    $commercialFeatures = $commercialFeatures ?? config('plans.commercial_features', []);
    $limitFields = $limitFields ?? config('plans.limit_fields', []);
    $defaultPermissions = [
        'workspace.view' => 'عرض المساحة',
        'workspace.manage' => 'إدارة المساحة',
        'products.manage' => 'إدارة المنتجات',
        'inventory.manage' => 'إدارة المخزون',
        'customers.manage' => 'إدارة العملاء',
        'orders.manage' => 'إدارة الطلبات',
        'pos.manage' => 'إدارة نقطة البيع',
        'conversations.manage' => 'إدارة المحادثات',
        'ai.manage' => 'إدارة الذكاء الاصطناعي',
        'whatsapp.manage' => 'إدارة واتساب',
        'payments.manage' => 'إدارة المدفوعات',
        'employees.manage' => 'إدارة الموظفين',
        'subscriptions.manage' => 'إدارة الاشتراكات',
        'finance.view' => 'عرض المالية',
        'finance.manage' => 'إدارة المالية',
    ];
    $tierOptions = [
        '' => '— بدون مستوى —',
        'starter' => 'Starter',
        'pro' => 'Pro',
        'business' => 'Business',
        'enterprise' => 'Enterprise',
    ];
    $selectedFeatures = old('features', $planData?->features ?? []);
    $selectedPermissions = old('permissions', $planData?->permissions ?? []);
    $limitValues = old('limits', $planData?->limits ?? []);
@endphp

<div class="grid gap-4 md:grid-cols-2" dir="rtl">
    <div>
        <label class="mb-1 block text-sm font-semibold text-gray-700">رمز الباقة (Code)</label>
        <input name="code" value="{{ old('code', $planData?->code) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm font-semibold text-gray-700">اسم الباقة</label>
        <input name="name" required value="{{ old('name', $planData?->name) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm font-semibold text-gray-700">الاسم العربي (display_name_ar)</label>
        <input name="display_name_ar" value="{{ old('display_name_ar', $planData?->display_name_ar) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div class="md:col-span-2">
        <label class="mb-1 block text-sm font-semibold text-gray-700">الوصف</label>
        <textarea name="description" rows="2" class="w-full rounded-lg border-gray-300">{{ old('description', $planData?->description) }}</textarea>
    </div>
    <div>
        <label class="mb-1 block text-sm font-semibold text-gray-700">المستوى التجاري (Tier)</label>
        <select name="tier" class="w-full rounded-lg border-gray-300">
            @foreach($tierOptions as $tierValue => $tierLabel)
                <option value="{{ $tierValue }}" @selected(old('tier', $planData?->tier ?? '') === $tierValue)>{{ $tierLabel }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="mb-1 block text-sm font-semibold text-gray-700">أهلية نوع مساحة العمل</label>
        <select name="workspace_type" class="w-full rounded-lg border-gray-300">
            @foreach(['individual' => 'فردي', 'company' => 'شركة', 'store' => 'متجر'] as $type => $label)
                <option value="{{ $type }}" @selected(old('workspace_type', $planData?->workspace_type ?? 'company') === $type)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="mb-1 block text-sm font-semibold text-gray-700">دورة الفوترة</label>
        <select name="billing_period" class="w-full rounded-lg border-gray-300">
            @foreach(['monthly' => 'شهري', 'yearly' => 'سنوي', 'lifetime' => 'مدى الحياة'] as $period => $label)
                <option value="{{ $period }}" @selected(old('billing_period', $planData?->billing_period ?? 'monthly') === $period)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="mb-1 block text-sm font-semibold text-gray-700">أيام التجربة (Trial)</label>
        <input type="number" min="0" name="trial_days" value="{{ old('trial_days', $planData?->trial_days ?? 14) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm font-semibold text-gray-700">العملة</label>
        <input name="currency" value="{{ old('currency', $planData?->currency ?? config('plans.currency', 'SAR')) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm font-semibold text-gray-700">السعر</label>
        <input type="number" step="0.01" name="price" value="{{ old('price', $planData?->price ?? 0) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm font-semibold text-gray-700">ترتيب العرض</label>
        <input type="number" min="0" name="sort_order" value="{{ old('sort_order', $planData?->sort_order ?? 0) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div class="flex flex-wrap items-center gap-6 mt-7">
        <label class="inline-flex items-center gap-2">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $planData?->is_active ?? true))>
            <span class="text-sm font-semibold text-gray-700">نشطة</span>
        </label>
        <label class="inline-flex items-center gap-2">
            <input type="checkbox" name="is_public" value="1" @checked(old('is_public', $planData?->is_public ?? true))>
            <span class="text-sm font-semibold text-gray-700">عامة (ظاهرة للعملاء)</span>
        </label>
        <label class="inline-flex items-center gap-2">
            <input type="checkbox" name="is_official" value="1" @checked(old('is_official', $planData?->is_official ?? false))>
            <span class="text-sm font-semibold text-gray-700">رسمية (الكتالوج الرسمي)</span>
        </label>
    </div>
</div>

<div dir="rtl">
    <label class="mb-2 block text-sm font-semibold text-gray-700">ميزات الباقة (Features) — تُحفظ في قاعدة البيانات</label>
    <div class="grid gap-2 rounded-xl border border-gray-200 bg-gray-50 p-4 md:grid-cols-3">
        @foreach($commercialFeatures as $featureKey => $featureLabel)
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="features[]" value="{{ $featureKey }}" @checked(in_array($featureKey, $selectedFeatures, true))>
                <span>{{ $featureLabel }} <span class="text-[10px] text-slate-400">({{ $featureKey }})</span></span>
            </label>
        @endforeach
    </div>
</div>

<div dir="rtl">
    <label class="mb-2 block text-sm font-semibold text-gray-700">الحدود (Limits) — قابلة للتعديل</label>
    <div class="grid gap-3 rounded-xl border border-gray-200 bg-gray-50 p-4 md:grid-cols-3">
        @foreach($limitFields as $limitKey => $limitLabel)
            <div>
                <label class="mb-1 block text-xs font-semibold text-gray-600">{{ $limitLabel }}</label>
                <input type="number" name="limits[{{ $limitKey }}]" value="{{ old('limits.'.$limitKey, $limitValues[$limitKey] ?? '') }}" class="w-full rounded-lg border-gray-300 text-sm" placeholder="فارغ = غير محدد">
            </div>
        @endforeach
    </div>
</div>

<div dir="rtl">
    <label class="mb-2 block text-sm font-semibold text-gray-700">صلاحيات الباقة (Permissions)</label>
    <div class="grid gap-2 rounded-xl border border-gray-200 bg-gray-50 p-4 md:grid-cols-2">
        @foreach($defaultPermissions as $permissionKey => $permissionLabel)
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="permissions[]" value="{{ $permissionKey }}" @checked(in_array($permissionKey, $selectedPermissions, true))>
                <span>{{ $permissionLabel }}</span>
            </label>
        @endforeach
    </div>
</div>

<div dir="rtl" class="space-y-3">
    <p class="text-xs text-slate-500">للتخصيص المتقدم فقط — إذا كانت مربعات الميزات/الحدود مملوءة فهي لها الأولوية.</p>
    <div>
        <label class="mb-1 block text-sm font-semibold text-gray-700">Features JSON</label>
        <textarea name="features_json" rows="3" class="w-full rounded-lg border-gray-300 font-mono text-xs">{{ old('features_json', $featuresJson ?? json_encode($planData?->features ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) }}</textarea>
    </div>
    <div>
        <label class="mb-1 block text-sm font-semibold text-gray-700">Permissions JSON</label>
        <textarea name="permissions_json" rows="3" class="w-full rounded-lg border-gray-300 font-mono text-xs">{{ old('permissions_json', $permissionsJson ?? json_encode($planData?->permissions ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) }}</textarea>
    </div>
    <div>
        <label class="mb-1 block text-sm font-semibold text-gray-700">Limits JSON</label>
        <textarea name="limits_json" rows="3" class="w-full rounded-lg border-gray-300 font-mono text-xs">{{ old('limits_json', $limitsJson ?? json_encode($planData?->limits ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) }}</textarea>
    </div>
    <div>
        <label class="mb-1 block text-sm font-semibold text-gray-700">Overage Rules JSON</label>
        <textarea name="overage_json" rows="3" class="w-full rounded-lg border-gray-300 font-mono text-xs">{{ old('overage_json', $overageJson ?? json_encode($planData?->overage_rules ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) }}</textarea>
    </div>
</div>
