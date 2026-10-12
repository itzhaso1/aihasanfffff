<div class="mb-6 rounded-xl border border-gray-200 bg-white p-4">
    <div class="flex flex-wrap gap-2 text-sm">
        <a href="{{ route('platform.dashboard') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('platform.dashboard') ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700' }}">الرئيسية</a>
        <a href="{{ route('platform.site.edit') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('platform.site.*') ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700' }}">الموقع</a>
        <a href="{{ route('platform.ai.edit') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('platform.ai.*') ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700' }}">الذكاء الاصطناعي</a>
        <a href="{{ route('platform.users.index') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('platform.users.*') ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700' }}">المستخدمون</a>
        <a href="{{ route('platform.workspaces.index') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('platform.workspaces.*') ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700' }}">مساحات العمل</a>
        <a href="{{ route('platform.plans.index') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('platform.plans.*') ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700' }}">الخطط</a>
        <a href="{{ route('platform.subscriptions.index') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('platform.subscriptions.*') ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700' }}">الاشتراكات</a>
        <a href="{{ route('platform.merchant-verifications.index') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('platform.merchant-verifications.*') ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700' }}">توثيق التجار</a>
        <a href="{{ route('platform.worker-operations.index') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('platform.worker-operations.*') ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700' }}">عمليات العمال</a>
        <a href="{{ route('platform.worker-contract-rates.index') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('platform.worker-contract-rates.*') ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700' }}">أسعار تعاقد العمال</a>
        <a href="{{ route('platform.worker-verifications.index') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('platform.worker-verifications.*') ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700' }}">تحقق العمال</a>
        <a href="{{ route('platform.worker-payouts.index') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('platform.worker-payouts.*') ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700' }}">صرف العمال</a>
        <a href="{{ route('platform.worker-disputes.index') }}" class="px-3 py-2 rounded-lg {{ request()->routeIs('platform.worker-disputes.*') ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700' }}">نزاعات العمال</a>
    </div>
</div>
