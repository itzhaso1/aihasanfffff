<x-communication-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">إضافة حساب واتساب</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-4xl px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')
            <form method="POST" action="{{ route('workspace.whatsapp-accounts.store') }}" class="rounded-xl border bg-white p-6 space-y-4">
                @csrf
                @include('workspace.whatsapp-accounts.form')
                <button class="rounded-lg bg-blue-600 px-4 py-2 text-white">حفظ</button>
            </form>
        </div>
    </div>
</x-communication-layout>
