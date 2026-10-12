<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">تعديل بوابة الدفع</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-3xl px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')
            <form method="POST" action="{{ route('workspace.payment-gateways.update', $gateway) }}" class="rounded-xl border bg-white p-6 space-y-4">
                @csrf @method('PUT')
                @include('workspace.payment-gateways.form', ['gateway' => $gateway, 'configJson' => $configJson])
                <button class="rounded-lg bg-blue-600 px-4 py-2 text-white">تحديث</button>
            </form>
        </div>
    </div>
</x-app-layout>
