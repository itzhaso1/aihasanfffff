<dl class="hs-legal-contact mt-4 grid gap-4 sm:grid-cols-2">
    <div>
        <dt>اسم الشركة</dt>
        <dd>{{ $legal['company_name'] }}</dd>
    </div>
    <div>
        <dt>رقم التواصل</dt>
        <dd><a class="text-[#0f7668] hover:underline" href="tel:{{ $legal['phone_href'] }}">{{ $legal['phone'] }}</a></dd>
    </div>
    <div>
        <dt>السجل التجاري</dt>
        <dd>{{ $legal['commercial_registration'] }}</dd>
    </div>
    <div>
        <dt>العنوان</dt>
        <dd>{{ $legal['address'] }}</dd>
    </div>
    @if (filled($legal['email'] ?? null))
        <div class="sm:col-span-2">
            <dt>البريد الإلكتروني</dt>
            <dd><a class="text-[#0f7668] hover:underline" href="mailto:{{ $legal['email'] }}">{{ $legal['email'] }}</a></dd>
        </div>
    @endif
</dl>
