<x-guest-layout variant="split" title="تسجيل عامل مستقل | {{ $publicSite->nameAr() }}">
    @include('partials.auth-split-styles')

    <div class="hs-login-page">
        <section class="hs-login-form-col" aria-labelledby="worker-register-title">
            <div class="hs-login-card hs-login-fade">
                <div class="mb-5 text-center">
                    <a href="{{ url('/') }}" class="inline-flex flex-col items-center gap-2">
                        <x-hasim-logo variant="mark" size="lg" class="h-12 w-12" />
                        @include('partials.site-brand-lockup', ['align' => 'center'])
                    </a>
                    <h1 id="worker-register-title" class="mt-4 text-2xl font-extrabold text-[#0f172a]">تسجيل عامل مستقل</h1>
                    <p class="mt-1.5 text-sm font-medium leading-7 text-slate-500">أنشئ حسابك على مستوى المنصة، ثم أكمل ملفك المهني.</p>
                </div>

                <form method="POST" action="{{ route('worker.register.store') }}" class="space-y-3.5">
                    @csrf
                    <div>
                        <x-input-label for="name" value="الاسم المعروض" />
                        <x-text-input id="name" class="mt-1 block w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="email" value="البريد الإلكتروني" />
                        <x-text-input id="email" class="mt-1 block w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="phone" value="رقم الجوال (اختياري)" />
                        <x-text-input id="phone" class="mt-1 block w-full" type="text" name="phone" :value="old('phone')" />
                        <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="password" value="كلمة المرور" />
                        <x-text-input id="password" class="mt-1 block w-full" type="password" name="password" required autocomplete="new-password" />
                        <x-input-error :messages="$errors->get('password')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="password_confirmation" value="تأكيد كلمة المرور" />
                        <x-text-input id="password_confirmation" class="mt-1 block w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
                    </div>
                    <button type="submit" class="hs-btn-primary mt-1 w-full rounded-xl px-4 py-3 text-sm">إنشاء حساب عامل</button>
                </form>

                <p class="mt-5 text-center text-sm font-medium text-slate-500">
                    لديك حساب؟ <a href="{{ route('login') }}" class="font-extrabold text-[#06C2A4]">تسجيل الدخول</a>
                </p>
            </div>
        </section>
    </div>
</x-guest-layout>
