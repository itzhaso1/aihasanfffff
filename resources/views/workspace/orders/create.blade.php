<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">إنشاء طلب جديد</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-4xl px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')
            <form method="POST" action="{{ route('workspace.orders.store') }}" class="rounded-xl border bg-white p-6 space-y-4">
                @csrf
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm">العميل</label>
                        <select name="customer_id" class="w-full rounded-lg border-gray-300">
                            <option value="">بدون عميل</option>
                            @foreach($customers as $customer)
                                <option value="{{ $customer->id }}" @selected(old('customer_id') == $customer->id)>{{ $customer->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">العملة</label>
                        <input name="currency" value="{{ old('currency', 'USD') }}" class="w-full rounded-lg border-gray-300" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">خصم</label>
                        <input type="number" step="0.01" name="discount_amount" value="{{ old('discount_amount', 0) }}" class="w-full rounded-lg border-gray-300" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">شحن</label>
                        <input type="number" step="0.01" name="shipping_amount" value="{{ old('shipping_amount', 0) }}" class="w-full rounded-lg border-gray-300" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">الحالة</label>
                        <select name="status" class="w-full rounded-lg border-gray-300">
                            @foreach(['confirmed','draft'] as $status)
                                <option value="{{ $status }}" @selected(old('status', 'confirmed') === $status)>{{ $status }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-sm">ملاحظات</label>
                    <textarea name="notes" rows="2" class="w-full rounded-lg border-gray-300">{{ old('notes') }}</textarea>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Items JSON</label>
                    <textarea name="items_json" rows="10" required class="w-full rounded-lg border-gray-300">{{ old('items_json', '[{"product_id":1,"product_variant_id":null,"quantity":1,"unit_price":100}]') }}</textarea>
                    <p class="mt-1 text-xs text-gray-500">يمكنك استخدام product_id و product_variant_id و quantity و unit_price.</p>
                </div>
                <button class="rounded-lg bg-blue-600 px-4 py-2 text-white">إنشاء الطلب</button>
            </form>
        </div>
    </div>
</x-app-layout>
