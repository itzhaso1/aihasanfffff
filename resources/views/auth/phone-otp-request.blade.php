<x-guest-layout>
    <h1 class="text-xl font-semibold text-gray-900 mb-4">تسجيل الدخول عبر OTP</h1>
    <form method="POST" action="{{ route('otp.request') }}">
        @csrf
        <div>
            <x-input-label for="phone" value="رقم الجوال" />
            <x-text-input id="phone" class="block mt-1 w-full" type="text" name="phone" :value="old('phone')" required autofocus />
            <x-input-error :messages="$errors->get('phone')" class="mt-2" />
        </div>

        <x-primary-button class="mt-4 w-full justify-center">
            إرسال رمز التحقق
        </x-primary-button>
    </form>
</x-guest-layout>
