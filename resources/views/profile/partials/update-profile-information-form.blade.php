@php
    $initial = $initial ?? mb_substr((string) $user->name, 0, 1);
    $avatarUrl = $avatarUrl ?? ($user->avatar_path
        ? (str_starts_with((string) $user->avatar_path, 'http')
            ? $user->avatar_path
            : \Illuminate\Support\Facades\Storage::disk('public')->url($user->avatar_path))
        : null);
@endphp

<section id="profile" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
    <div class="mb-5 flex items-start justify-between gap-3">
        <div>
            <h2 class="text-lg">بيانات الحساب</h2>
            <p class="mt-1 text-sm font-medium text-slate-600">تحديث بياناتك الأساسية وإعدادات اللغة والمنطقة الزمنية.</p>
        </div>
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#E8FAF6] text-[#06C2A4]">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
            </svg>
        </span>
    </div>

    <div class="mb-5 flex items-center gap-4">
        @if($avatarUrl)
            <img src="{{ $avatarUrl }}" alt="{{ $user->name }}" class="h-16 w-16 rounded-full object-cover ring-4 ring-[#E8FAF6]">
        @else
            <span class="flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 text-xl font-extrabold text-slate-700 ring-4 ring-[#E8FAF6]">
                {{ $initial }}
            </span>
        @endif
        <div>
            <p class="text-sm font-bold text-slate-900">صورة الحساب</p>
            <p class="mt-0.5 text-xs font-medium text-slate-600">تظهر الأحرف الأولى عند عدم وجود صورة مرفوعة.</p>
        </div>
    </div>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="space-y-4">
        @csrf
        @method('patch')

        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <x-input-label for="name" value="الاسم" class="text-slate-800" />
                <div class="relative mt-1">
                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-slate-400">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                    </span>
                    <x-text-input id="name" name="name" type="text" class="hs-input pr-10" :value="old('name', $user->name)" required autofocus autocomplete="name" />
                </div>
                <x-input-error class="mt-2" :messages="$errors->get('name')" />
            </div>

            <div>
                <x-input-label for="email" value="البريد الإلكتروني" class="text-slate-800" />
                <div class="relative mt-1">
                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-slate-400">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 6.75A1.75 1.75 0 0 1 4.75 5h14.5A1.75 1.75 0 0 1 21 6.75v10.5A1.75 1.75 0 0 1 19.25 19H4.75A1.75 1.75 0 0 1 3 17.25V6.75Zm1.75.35L12 12l7.25-4.9" /></svg>
                    </span>
                    <x-text-input id="email" name="email" type="email" class="hs-input pr-10" :value="old('email', $user->email)" required autocomplete="username" />
                </div>
                <x-input-error class="mt-2" :messages="$errors->get('email')" />

                @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                    <div class="mt-2">
                        <p class="text-sm font-medium text-slate-800">
                            {{ __('Your email address is unverified.') }}
                            <button form="send-verification" class="font-bold text-[#067e6b] underline">
                                {{ __('Click here to re-send the verification email.') }}
                            </button>
                        </p>
                        @if (session('status') === 'verification-link-sent')
                            <p class="mt-2 text-sm font-semibold text-emerald-700">
                                {{ __('A new verification link has been sent to your email address.') }}
                            </p>
                        @endif
                    </div>
                @endif
            </div>

            <div>
                <x-input-label for="phone" value="رقم الجوال" class="text-slate-800" />
                <div class="relative mt-1">
                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-slate-400">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" /></svg>
                    </span>
                    <x-text-input id="phone" name="phone" type="text" class="hs-input pr-10" :value="old('phone', $user->phone)" />
                </div>
                <x-input-error class="mt-2" :messages="$errors->get('phone')" />
            </div>

            <div>
                <x-input-label for="locale" value="اللغة" class="text-slate-800" />
                <div class="relative mt-1">
                    <span class="pointer-events-none absolute inset-y-0 right-3 z-10 flex items-center text-slate-400">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 21a9 9 0 100-18 9 9 0 000 18zM3.6 9h16.8M3.6 15h16.8M12 3c2.5 3 3.7 6 3.7 9s-1.2 6-3.7 9c-2.5-3-3.7-6-3.7-9s1.2-6 3.7-9z" /></svg>
                    </span>
                    <select id="locale" name="locale" class="hs-input appearance-none pr-10">
                        <option value="ar" @selected(old('locale', $user->locale) === 'ar')>العربية</option>
                        <option value="en" @selected(old('locale', $user->locale) === 'en')>English</option>
                    </select>
                </div>
                <x-input-error class="mt-2" :messages="$errors->get('locale')" />
            </div>

            <div class="md:col-span-2">
                <x-input-label for="timezone" value="المنطقة الزمنية" class="text-slate-800" />
                <div class="relative mt-1">
                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-slate-400">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z" /></svg>
                    </span>
                    <x-text-input id="timezone" name="timezone" type="text" class="hs-input pr-10" :value="old('timezone', $user->timezone)" />
                </div>
                <x-input-error class="mt-2" :messages="$errors->get('timezone')" />
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 pt-1">
            <button type="submit" class="hs-btn-primary">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 3H7a2 2 0 00-2 2v14l7-3 7 3V5a2 2 0 00-2-2z" /></svg>
                حفظ التغييرات
            </button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm font-semibold text-emerald-700"
                >تم الحفظ.</p>
            @endif
        </div>
    </form>
</section>
