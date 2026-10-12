<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">دعوة موظف جديد</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-3xl px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')
            <form method="POST" action="{{ route('workspace.employees.store') }}" class="rounded-xl border bg-white p-6 space-y-4">
                @csrf
                <div>
                    <label class="mb-1 block text-sm">البريد الإلكتروني</label>
                    <input type="email" name="email" required class="w-full rounded-lg border-gray-300" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">الدور</label>
                    <select name="role" class="w-full rounded-lg border-gray-300">
                        @foreach(['admin','manager','agent'] as $role)
                            <option value="{{ $role }}">{{ $role }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="rounded-lg bg-blue-600 px-4 py-2 text-white">إرسال الدعوة</button>
            </form>
        </div>
    </div>
</x-app-layout>
