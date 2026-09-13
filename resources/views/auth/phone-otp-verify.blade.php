<x-guest-layout>
    <h1 class="text-xl font-semibold text-gray-900 mb-2">تأكيد رمز OTP</h1>
    <p class="text-sm text-gray-600 mb-4">إذا كان الرقم مسجلاً لدينا فسيصلك رمز التحقق.</p>

    <form method="POST" action="{{ route('otp.verify') }}">
        @csrf

        <div>
            <x-input-label for="otp" value="رمز التحقق" />
            <x-text-input id="otp" class="block mt-1 w-full" type="text" name="otp" maxlength="6" required />
            <x-input-error :messages="$errors->get('otp')" class="mt-2" />
        </div>

        <x-primary-button class="mt-4 w-full justify-center">
            دخول
        </x-primary-button>
    </form>
</x-guest-layout>
