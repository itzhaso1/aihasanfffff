<x-guest-layout>
    <div class="mb-5 text-center">
        <h1 class="text-2xl font-bold text-gray-900">دخول منصة الإدارة</h1>
        <p class="mt-1 text-sm text-gray-500">تسجيل دخول منفصل لمدير المنصة</p>
    </div>

    @include('partials.flash')

    <form method="POST" action="{{ route('platform.login.store') }}" class="space-y-4">
        @csrf
        <div>
            <x-input-label for="email" value="البريد الإلكتروني" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" required />
        </div>
        <div>
            <x-input-label for="password" value="كلمة المرور" />
            <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" required />
        </div>
        <x-primary-button class="w-full justify-center">تسجيل الدخول</x-primary-button>
    </form>
</x-guest-layout>
