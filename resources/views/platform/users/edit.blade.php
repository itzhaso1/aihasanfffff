@extends('platform.layout')

@section('content')
    <div class="py-8">
        <div class="mx-auto max-w-3xl px-4">
            @include('platform.partials.nav')
            @include('partials.flash')
            <form method="POST" action="{{ route('platform.users.update', $user) }}" class="rounded-xl border bg-white p-6 space-y-4">
                @csrf @method('PUT')
                <div><label class="mb-1 block text-sm">الاسم</label><input name="name" value="{{ old('name', $user->name) }}" class="w-full rounded-lg border-gray-300" /></div>
                <div><label class="mb-1 block text-sm">البريد</label><input type="email" name="email" value="{{ old('email', $user->email) }}" class="w-full rounded-lg border-gray-300" /></div>
                <div><label class="mb-1 block text-sm">الهاتف</label><input name="phone" value="{{ old('phone', $user->phone) }}" class="w-full rounded-lg border-gray-300" /></div>
                <div><label class="mb-1 block text-sm">اللغة</label><input name="locale" value="{{ old('locale', $user->locale) }}" class="w-full rounded-lg border-gray-300" /></div>
                <div><label class="mb-1 block text-sm">المنطقة الزمنية</label><input name="timezone" value="{{ old('timezone', $user->timezone) }}" class="w-full rounded-lg border-gray-300" /></div>
                <button class="rounded-lg bg-blue-600 px-4 py-2 text-white">حفظ</button>
            </form>
        </div>
    </div>
@endsection
