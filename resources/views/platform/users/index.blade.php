@extends('platform.layout')

@section('content')
    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4">
            @include('platform.partials.nav')
            @include('partials.flash')
            <form method="GET" class="mb-4 flex gap-2">
                <input name="search" value="{{ request('search') }}" class="rounded-lg border-gray-300 text-sm" placeholder="بحث..." />
                <button class="rounded-lg bg-gray-800 px-3 py-2 text-sm text-white">بحث</button>
            </form>
            <div class="overflow-x-auto rounded-xl border bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-right">الاسم</th>
                            <th class="px-4 py-3 text-right">البريد</th>
                            <th class="px-4 py-3 text-right">الهاتف</th>
                            <th class="px-4 py-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($users as $user)
                            <tr>
                                <td class="px-4 py-3">{{ $user->name }}</td>
                                <td class="px-4 py-3">{{ $user->email }}</td>
                                <td class="px-4 py-3">{{ $user->phone ?? '-' }}</td>
                                <td class="px-4 py-3 text-left"><a href="{{ route('platform.users.edit', $user) }}" class="text-blue-600">تعديل</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">لا يوجد مستخدمون.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $users->links() }}</div>
        </div>
    </div>
@endsection
