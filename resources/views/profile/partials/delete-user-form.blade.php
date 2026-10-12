<section id="account" class="rounded-2xl border border-rose-200 bg-rose-50/70 p-5 shadow-sm sm:p-6">
    <div class="mb-4 flex items-start justify-between gap-3">
        <div>
            <h2 class="text-lg text-rose-900">حذف الحساب</h2>
            <p class="mt-1 text-sm font-medium text-rose-800">بمجرد حذف حسابك تُرْفَع بياناته وموارده بشكل دائم.</p>
        </div>
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-white text-rose-600">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 6h18M8 6V4h8v2m-1 0v14a2 2 0 01-2 2H9a2 2 0 01-2-2V6h10z" />
            </svg>
        </span>
    </div>

    <div class="mb-4 flex items-start gap-2 rounded-xl border border-rose-200 bg-white px-4 py-3 text-sm font-medium text-rose-800">
        <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v4m0 4h.01M10.29 4.86 1.82 19a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 4.86a2 2 0 00-3.42 0Z" />
        </svg>
        <p>تحذير مهم: لا يمكن التراجع عن هذا الإجراء. تأكد من تنزيل أي بيانات أو ملفات ترغب بالاحتفاظ بها قبل حذف الحساب.</p>
    </div>

    <x-danger-button
        x-data=""
        x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')"
        class="rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-bold normal-case tracking-normal"
    >
        حذف الحساب
    </x-danger-button>

    <x-modal name="confirm-user-deletion" :show="$errors->userDeletion->isNotEmpty()" focusable>
        <form method="post" action="{{ route('profile.destroy') }}" class="p-6">
            @csrf
            @method('delete')

            <h2 class="text-lg font-extrabold text-slate-900">
                {{ __('Are you sure you want to delete your account?') }}
            </h2>

            <p class="mt-2 text-sm font-medium text-slate-700">
                {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
            </p>

            <div class="mt-6">
                <x-input-label for="password" value="كلمة المرور" class="text-slate-800" />

                <x-text-input
                    id="password"
                    name="password"
                    type="password"
                    class="hs-input mt-1 block w-3/4"
                    placeholder="{{ __('Password') }}"
                />

                <x-input-error :messages="$errors->userDeletion->get('password')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end gap-2">
                <x-secondary-button x-on:click="$dispatch('close')">
                    {{ __('Cancel') }}
                </x-secondary-button>

                <x-danger-button class="ms-3">
                    {{ __('Delete Account') }}
                </x-danger-button>
            </div>
        </form>
    </x-modal>
</section>
