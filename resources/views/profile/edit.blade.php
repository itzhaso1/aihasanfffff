<x-communication-layout page-title="الملف الشخصي" :show-secondary="false">
    @php
        $initial = mb_substr((string) $user->name, 0, 1);
        $avatarUrl = $user->avatar_path
            ? (str_starts_with((string) $user->avatar_path, 'http')
                ? $user->avatar_path
                : \Illuminate\Support\Facades\Storage::disk('public')->url($user->avatar_path))
            : null;
    @endphp

    <div
        x-data="{ section: (window.location.hash || '#profile').replace('#', '') }"
        class="hs-settings mx-auto max-w-6xl"
    >
        <div class="mb-5 flex items-start justify-between gap-4">
            <div>
                <p class="text-sm font-semibold text-slate-500">
                    <a href="{{ route('workspace.dashboard') }}" class="hover:text-[#067e6b]">الرئيسية</a>
                    <span class="mx-1">/</span>
                    الملف الشخصي
                </p>
                <h1 class="mt-2 text-2xl leading-tight">الملف الشخصي</h1>
                <p class="mt-1 text-sm font-medium text-slate-600">إدارة بيانات حسابك وإعدادات المنطقة الزمنية.</p>
            </div>
            <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-[#E8FAF6] text-[#06C2A4]">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
            </span>
        </div>

        <div class="flex flex-col gap-5 lg:flex-row">
            @include('profile.partials.settings-nav')

            <div class="min-w-0 flex-1 space-y-5">
                @include('profile.partials.update-profile-information-form')
                @include('profile.partials.update-password-form')

                <section id="security" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                    <div class="mb-4 flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg">الأمان</h2>
                            <p class="mt-1 text-sm font-medium text-slate-600">حماية الحساب تتم عبر كلمة المرور الحالية.</p>
                        </div>
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#E8FAF6] text-[#06C2A4]">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 3 5 6v5c0 5 3.4 8.5 7 10 3.6-1.5 7-5 7-10V6l-7-3Z" />
                            </svg>
                        </span>
                    </div>
                    <div class="grid gap-3 text-sm font-medium text-slate-700 sm:grid-cols-2">
                        <div class="rounded-xl bg-slate-50 px-4 py-3">
                            <p class="text-xs font-bold text-slate-500">آخر تسجيل دخول</p>
                            <p class="mt-1">{{ $user->last_login_at?->format('Y-m-d H:i') ?? 'لا توجد بيانات مسجّلة' }}</p>
                        </div>
                        <div class="rounded-xl bg-slate-50 px-4 py-3">
                            <p class="text-xs font-bold text-slate-500">عنوان آخر دخول</p>
                            <p class="mt-1">{{ $user->last_login_ip ?: 'غير متوفر' }}</p>
                        </div>
                    </div>
                    <a href="#password" @click="section = 'password'" class="mt-4 inline-flex text-sm font-bold text-[#067e6b] hover:underline">
                        الانتقال إلى تغيير كلمة المرور
                    </a>
                </section>

                @include('profile.partials.delete-user-form')
            </div>
        </div>
    </div>
</x-communication-layout>
