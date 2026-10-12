<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">إضافة بوابة دفع</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-3xl px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')
            <form method="POST" action="{{ route('workspace.payment-gateways.store') }}" class="rounded-xl border bg-white p-6 space-y-4">
                @csrf
                @include('workspace.payment-gateways.form')
                <button class="rounded-lg bg-blue-600 px-4 py-2 text-white">حفظ</button>
            </form>
        </div>
    </div>
</x-app-layout>
