<x-guest-layout variant="split" title="تسجيل الدخول | حاسم الذكي">
    @include('partials.auth-split-styles')

    <div class="hs-login-page hs-login-page--login">
        <section class="hs-login-form-col" aria-labelledby="hs-login-title">
            <div class="hs-login-card hs-login-fade">
                <div class="mb-6 text-center">
                    <a href="{{ url('/') }}" class="inline-flex flex-col items-center gap-2">
                        <x-hasim-logo variant="mark" size="lg" class="h-12 w-12" />
                        <span>
                            <span class="block text-sm font-extrabold leading-none text-[#0f172a]">حاسم الذكي</span>
                            <span class="mt-1 block text-[10px] font-bold tracking-[0.18em] text-slate-500">HASIM SMART</span>
                        </span>
                    </a>
                    <h1 id="hs-login-title" class="mt-5 text-2xl font-extrabold text-[#0f172a]">مرحبًا بك في حاسم</h1>
                    <p class="mt-1.5 text-sm font-medium leading-7 text-slate-500">سجّل الدخول لإدارة أعمالك من لوحة واحدة</p>
                </div>

                <x-auth-session-status class="mb-4 rounded-xl bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700" :status="session('status')" />

                <form method="POST" action="{{ route('login') }}" class="space-y-4">
                    @csrf

                    <div>
                        <x-input-label for="email_or_phone" value="البريد الإلكتروني أو رقم الجوال" class="text-sm font-bold text-[#0f172a]" />
                        <div class="relative mt-1.5">
                            <span class="hs-field-icon" aria-hidden="true">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M3 6.75A1.75 1.75 0 0 1 4.75 5h14.5A1.75 1.75 0 0 1 21 6.75v10.5A1.75 1.75 0 0 1 19.25 19H4.75A1.75 1.75 0 0 1 3 17.25V6.75Zm1.75.35L12 12l7.25-4.9"/></svg>
                            </span>
                            <x-text-input
                                id="email_or_phone"
                                class="hs-login-input has-icon block {{ $errors->has('email_or_phone') ? 'is-invalid' : '' }}"
                                type="text"
                                name="email_or_phone"
                                :value="old('email_or_phone')"
                                required
                                autofocus
                                autocomplete="username"
                            />
                        </div>
                        <x-input-error :messages="$errors->get('email_or_phone')" class="mt-2" />
                    </div>

                    <div x-data="{ showPassword: false }">
                        <x-input-label for="password" value="كلمة المرور" class="text-sm font-bold text-[#0f172a]" />
                        <div class="relative mt-1.5">
                            <span class="hs-field-icon" aria-hidden="true">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M7.5 10.5V8.25a4.5 4.5 0 1 1 9 0V10.5M6.75 10.5h10.5A1.75 1.75 0 0 1 19 12.25v6A1.75 1.75 0 0 1 17.25 20H6.75A1.75 1.75 0 0 1 5 18.25v-6A1.75 1.75 0 0 1 6.75 10.5Z"/></svg>
                            </span>
                            <x-text-input
                                id="password"
                                class="hs-login-input has-icon block pe-11 {{ $errors->has('password') ? 'is-invalid' : '' }}"
                                type="password"
                                x-bind:type="showPassword ? 'text' : 'password'"
                                name="password"
                                required
                                autocomplete="current-password"
                            />
                            <button
                                type="button"
                                class="hs-login-toggle"
                                @click="showPassword = !showPassword"
                                :aria-pressed="showPassword.toString()"
                                :aria-label="showPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'"
                            >
                                <svg x-show="!showPassword" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M2.1 12s3.4-7 9.9-7 9.9 7 9.9 7-3.4 7-9.9 7-9.9-7-9.9-7Z" />
                                    <circle cx="12" cy="12" r="2.6" stroke-width="1.7" />
                                </svg>
                                <svg x-show="showPassword" x-cloak class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M3 3l18 18M10.5 10.7a2.6 2.6 0 003.8 3.6M6.1 6.4C4 8 2.1 12 2.1 12s3.4 7 9.9 7c2 0 3.8-.5 5.3-1.3M17.7 15.3C19.8 13.7 21.9 12 21.9 12s-3.4-7-9.9-7c-.8 0-1.6.1-2.3.3" />
                                </svg>
                            </button>
                        </div>
                        <x-input-error :messages="$errors->get('password')" class="mt-2" />
                    </div>

                    <div class="flex items-center justify-between gap-2 pt-0.5">
                        <label for="remember_me" class="inline-flex items-center gap-2 text-sm font-medium text-slate-600">
                            <input id="remember_me" type="checkbox" class="rounded border-slate-300 text-[#06C2A4] shadow-sm focus:ring-[#06C2A4]" name="remember">
                            <span>تذكرني</span>
                        </label>

                        @if (Route::has('password.request'))
                            <a class="text-sm font-bold text-[#0f7668] transition hover:text-[#06C2A4]" href="{{ route('password.request') }}">
                                نسيت كلمة المرور؟
                            </a>
                        @endif
                    </div>

                    <button type="submit" class="hs-btn-primary mt-1 w-full rounded-xl px-4 py-3 text-sm">
                        تسجيل الدخول
                    </button>
                </form>

                <div class="my-5 flex items-center gap-3">
                    <div class="h-px flex-1 bg-slate-200"></div>
                    <span class="text-xs font-bold text-slate-400">أو</span>
                    <div class="h-px flex-1 bg-slate-200"></div>
                </div>

                <div class="space-y-2">
                    <a href="{{ route('social.redirect', 'google') }}" class="hs-login-social">
                        <svg class="h-4 w-4" viewBox="0 0 48 48" aria-hidden="true">
                            <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3 0 5.7 1.1 7.8 3l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.2-.1-2.4-.4-3.5z"/>
                            <path fill="#FF3D00" d="M6.3 14.7l6.6 4.9C14.7 16 19 12 24 12c3 0 5.7 1.1 7.8 3l5.7-5.7C34 6.1 29.3 4 24 4c-7.7 0-14.3 4.3-17.7 10.7z"/>
                            <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.1 35.1 26.7 36 24 36c-5.1 0-9.5-3.3-11.1-8l-6.6 5.1C9.7 39.6 16.4 44 24 44z"/>
                            <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-1.1 3-3.4 5.3-6.1 6.8l6.2 5.2C38.8 36.8 44 31 44 24c0-1.2-.1-2.4-.4-3.5z"/>
                        </svg>
                        المتابعة عبر Google
                    </a>

                    <a href="{{ route('social.redirect', 'facebook') }}" class="hs-login-social">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" aria-hidden="true">
                            <path fill="#1877F2" d="M24 12a12 12 0 10-13.875 11.85V15.47H7.078V12h3.047V9.356c0-3.007 1.792-4.669 4.533-4.669 1.313 0 2.686.235 2.686.235v2.953h-1.513c-1.49 0-1.956.925-1.956 1.874V12h3.328l-.532 3.47h-2.796v8.38A12.003 12.003 0 0024 12z"/>
                        </svg>
                        المتابعة عبر Facebook
                    </a>

                    <a href="{{ route('otp.login') }}" class="hs-login-social">
                        <svg class="h-4 w-4 text-[#06C2A4]" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M16 2H8a2 2 0 00-2 2v16a2 2 0 002 2h8a2 2 0 002-2V4a2 2 0 00-2-2zm-4 18h.01"/>
                        </svg>
                        المتابعة عبر رمز التحقق (OTP)
                    </a>
                </div>

                @if (Route::has('register'))
                    <p class="mt-6 text-center text-sm font-medium text-slate-500">
                        ليس لديك حساب؟
                        <a href="{{ route('register') }}" class="font-extrabold text-[#06C2A4] transition hover:text-[#049e86]">إنشاء حساب جديد</a>
                    </p>
                @endif
                <p class="mt-4 text-center text-[11px] font-semibold text-slate-400">
                    <a href="{{ route('legal.terms') }}" class="hover:text-[#0f7668] hover:underline">الشروط والأحكام</a>
                    <span class="mx-1.5 text-slate-300">|</span>
                    <a href="{{ route('legal.privacy') }}" class="hover:text-[#0f7668] hover:underline">سياسة الخصوصية</a>
                </p>
            </div>
        </section>

        <aside class="hs-login-showcase hs-login-fade hs-login-fade-delay" aria-label="عرض هوية حاسم">
            <div class="hs-login-showcase-inner">
                <a href="{{ url('/') }}" class="mb-6 inline-flex items-center gap-2.5">
                    <x-hasim-logo variant="mark" size="md" />
                    <span>
                        <span class="block text-sm font-extrabold leading-none text-[#0f172a]">حاسم الذكي</span>
                        <span class="mt-1 block text-[10px] font-bold tracking-[0.18em] text-slate-500">HASIM SMART</span>
                    </span>
                </a>

                <h2 class="text-3xl font-extrabold leading-[1.35] text-[#0f172a] sm:text-4xl">
                    كل أعمالك
                    <span class="block text-[#06C2A4]">في مكان واحد</span>
                </h2>
                <p class="mt-3 max-w-lg text-sm font-medium leading-7 text-slate-600 sm:text-[15px]">
                    إدارة المحادثات، الفوترة، المبيعات، الكاشير والعملاء من منصة واحدة سهلة ومتكاملة.
                </p>

                <div class="hs-login-features mt-6">
                    <article class="hs-login-feature">
                        <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-2xl bg-[#F7FCFB] text-[#06C2A4] shadow-sm">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7.5 17.25H4.875A1.875 1.875 0 0 1 3 15.375V5.625A1.875 1.875 0 0 1 4.875 3.75h14.25A1.875 1.875 0 0 1 21 5.625v9.75a1.875 1.875 0 0 1-1.875 1.875H12l-4.5 3v-3Z"/></svg>
                        </span>
                        <h3>المحادثات</h3>
                        <p>كل قنواتك في مكان واحد</p>
                    </article>
                    <article class="hs-login-feature">
                        <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-2xl bg-[#F7FCFB] text-[#06C2A4] shadow-sm">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7h8M8 11h8M8 15h4M6.75 4.5h10.5A1.75 1.75 0 0 1 19 6.25v13l-2.6-1.5-2.6 1.5-2.55-1.5-2.55 1.5-2.6-1.5-2.6 1.5V6.25A1.75 1.75 0 0 1 6.75 4.5Z"/></svg>
                        </span>
                        <h3>الفوترة</h3>
                        <p>إدارة مالية بسهولة واحترافية</p>
                    </article>
                    <article class="hs-login-feature">
                        <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-2xl bg-[#F7FCFB] text-[#06C2A4] shadow-sm">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4.5 16.5V8.25A1.75 1.75 0 0 1 6.25 6.5h11.5A1.75 1.75 0 0 1 19.5 8.25V16.5M3 16.5h18M8 20h8"/></svg>
                        </span>
                        <h3>الكاشير</h3>
                        <p>مبيعات أسرع وتجربة أفضل</p>
                    </article>
                </div>

                <div class="hs-login-devices">
                    <div class="hs-login-laptop">
                        <div class="hs-login-laptop-screen">
                            <img src="{{ asset('images/landing-inbox.jpg') }}" alt="واجهة المحادثات في حاسم على الحاسوب">
                        </div>
                        <div class="hs-login-laptop-base"></div>
                    </div>
                    <div class="hs-login-phone">
                        <span class="hs-login-phone-notch" aria-hidden="true"></span>
                        <img src="{{ asset('images/landing-inbox.jpg') }}" alt="واجهة المحادثات في حاسم على الجوال">
                    </div>
                </div>
            </div>
        </aside>
    </div>
</x-guest-layout>
