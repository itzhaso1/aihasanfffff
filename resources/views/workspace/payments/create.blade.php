<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">إنشاء رابط دفع</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-3xl px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')
            <form method="POST" action="{{ route('workspace.payments.store') }}" class="rounded-xl border bg-white p-6 space-y-4">
                @csrf
                <div>
                    <label class="mb-1 block text-sm">الطلب</label>
                    <select name="order_id" class="w-full rounded-lg border-gray-300">
                        @foreach($orders as $order)
                            <option value="{{ $order->id }}">{{ $order->order_number }} - {{ number_format((float)$order->total_amount,2) }} {{ $order->currency }} ({{ $order->payment_status }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm">بوابة الدفع (اختياري)</label>
                    <select name="payment_gateway_id" class="w-full rounded-lg border-gray-300">
                        <option value="">تلقائي</option>
                        @foreach($gateways as $gateway)
                            <option value="{{ $gateway->id }}">{{ $gateway->provider }} ({{ $gateway->status }})</option>
                        @endforeach
                    </select>
                </div>
                <button class="rounded-lg bg-blue-600 px-4 py-2 text-white">إنشاء الرابط</button>
            </form>
        </div>
    </div>
</x-app-layout>
