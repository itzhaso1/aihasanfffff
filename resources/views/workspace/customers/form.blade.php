@php($customer = $customer ?? null)
<div class="grid gap-4 md:grid-cols-2">
    <div>
        <label class="mb-1 block text-sm">الاسم</label>
        <input name="name" required value="{{ old('name', $customer?->name) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm">نوع العميل</label>
        <select name="party_type" class="w-full rounded-lg border-gray-300">
            <option value="individual" @selected(old('party_type', $customer?->party_type ?: 'individual') === 'individual')>فرد</option>
            <option value="company" @selected(old('party_type', $customer?->party_type) === 'company')>شركة</option>
        </select>
    </div>
    <div>
        <label class="mb-1 block text-sm">الهاتف</label>
        <input name="phone" required value="{{ old('phone', $customer?->phone) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm">واتساب</label>
        <input name="whatsapp" value="{{ old('whatsapp', $customer?->whatsapp) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm">البريد الإلكتروني</label>
        <input type="email" name="email" value="{{ old('email', $customer?->email) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm">الرقم الضريبي (VAT)</label>
        <input name="vat_number" value="{{ old('vat_number', $customer?->vat_number) }}" class="w-full rounded-lg border-gray-300" maxlength="32" />
    </div>
    <div>
        <label class="mb-1 block text-sm">السجل التجاري</label>
        <input name="commercial_registration" value="{{ old('commercial_registration', $customer?->commercial_registration) }}" class="w-full rounded-lg border-gray-300" maxlength="32" />
    </div>
    <div>
        <label class="mb-1 block text-sm">شروط الدفع</label>
        <input name="payment_terms" value="{{ old('payment_terms', $customer?->payment_terms) }}" class="w-full rounded-lg border-gray-300" />
    </div>
</div>
<div>
    <label class="mb-1 block text-sm">العنوان</label>
    <textarea name="address" rows="2" class="w-full rounded-lg border-gray-300">{{ old('address', $customer?->address) }}</textarea>
</div>
<div class="grid gap-4 md:grid-cols-3">
    <div>
        <label class="mb-1 block text-sm">رقم المبنى</label>
        <input name="building_number" value="{{ old('building_number', $customer?->building_number) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm">الشارع</label>
        <input name="street" value="{{ old('street', $customer?->street) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm">الحي</label>
        <input name="district" value="{{ old('district', $customer?->district) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm">المدينة</label>
        <input name="city" value="{{ old('city', $customer?->city) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm">الرمز البريدي</label>
        <input name="postal_code" value="{{ old('postal_code', $customer?->postal_code) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm">الدولة</label>
        <input name="country_code" value="{{ old('country_code', $customer?->country_code ?: 'SA') }}" maxlength="2" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm">الرقم الإضافي</label>
        <input name="additional_number" value="{{ old('additional_number', $customer?->additional_number) }}" class="w-full rounded-lg border-gray-300" />
    </div>
</div>
<div>
    <label class="mb-1 block text-sm">ملاحظات</label>
    <textarea name="notes" rows="3" class="w-full rounded-lg border-gray-300">{{ old('notes', $customer?->notes) }}</textarea>
</div>
<div>
    <label class="mb-1 block text-sm">Metadata JSON</label>
    <textarea name="metadata_json" rows="5" class="w-full rounded-lg border-gray-300">{{ old('metadata_json', $metadataJson ?? json_encode($customer?->metadata ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) }}</textarea>
</div>
