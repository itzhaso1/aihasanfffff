<section id="password" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
    <div class="mb-5 flex items-start justify-between gap-3">
        <div>
            <h2 class="text-lg">تغيير كلمة المرور</h2>
            <p class="mt-1 text-sm font-medium text-slate-600">استخدم كلمة مرور قوية لحماية حسابك.</p>
        </div>
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#E8FAF6] text-[#06C2A4]">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16.5 10.5V8.25a4.5 4.5 0 10-9 0v2.25m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
            </svg>
        </span>
    </div>

    <form method="post" action="{{ route('password.update') }}" class="space-y-4">
        @csrf
        @method('put')

        <div class="grid gap-4 md:grid-cols-3">
            <div x-data="{ show: false }">
                <x-input-label for="update_password_current_password" value="كلمة المرور الحالية" class="text-slate-800" />
                <div class="relative mt-1">
                    <x-text-input id="update_password_current_password" name="current_password" type="password" class="hs-input pl-10" autocomplete="current-password" x-bind:type="show ? 'text' : 'password'" />
                    <button type="button" @click="show = !show" class="absolute inset-y-0 left-3 flex items-center text-slate-400 hover:text-slate-600" aria-label="إظهار كلمة المرور">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z" /><circle cx="12" cy="12" r="3" stroke-width="1.8" /></svg>
                    </button>
                </div>
                <x-input-error :messages="$errors->updatePassword->get('current_password')" class="mt-2" />
            </div>

            <div x-data="{ show: false }">
                <x-input-label for="update_password_password" value="كلمة المرور الجديدة" class="text-slate-800" />
                <div class="relative mt-1">
                    <x-text-input id="update_password_password" name="password" type="password" class="hs-input pl-10" autocomplete="new-password" x-bind:type="show ? 'text' : 'password'" />
                    <button type="button" @click="show = !show" class="absolute inset-y-0 left-3 flex items-center text-slate-400 hover:text-slate-600" aria-label="إظهار كلمة المرور">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z" /><circle cx="12" cy="12" r="3" stroke-width="1.8" /></svg>
                    </button>
                </div>
                <x-input-error :messages="$errors->updatePassword->get('password')" class="mt-2" />
            </div>

            <div x-data="{ show: false }">
                <x-input-label for="update_password_password_confirmation" value="تأكيد كلمة المرور" class="text-slate-800" />
                <div class="relative mt-1">
                    <x-text-input id="update_password_password_confirmation" name="password_confirmation" type="password" class="hs-input pl-10" autocomplete="new-password" x-bind:type="show ? 'text' : 'password'" />
                    <button type="button" @click="show = !show" class="absolute inset-y-0 left-3 flex items-center text-slate-400 hover:text-slate-600" aria-label="إظهار كلمة المرور">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z" /><circle cx="12" cy="12" r="3" stroke-width="1.8" /></svg>
                    </button>
                </div>
                <x-input-error :messages="$errors->updatePassword->get('password_confirmation')" class="mt-2" />
            </div>
        </div>

        <div class="flex items-start gap-2 rounded-xl bg-slate-50 px-4 py-3 text-sm font-medium text-slate-700">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-[#06C2A4]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 3 5 6v5c0 5 3.4 8.5 7 10 3.6-1.5 7-5 7-10V6l-7-3Z" />
            </svg>
            <p>نصيحة لكلمة مرور قوية: استخدم مزيجًا من الأحرف الكبيرة والصغيرة والأرقام والرموز.</p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="hs-btn-primary">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16.5 10.5V8.25a4.5 4.5 0 10-9 0v2.25m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                تحديث كلمة المرور
            </button>

            @if (session('status') === 'password-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm font-semibold text-emerald-700"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
