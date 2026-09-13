<x-communication-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">تعديل المنتج</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-4xl px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')
            <form method="POST" enctype="multipart/form-data" action="{{ route('workspace.products.update', $product) }}" class="rounded-xl border bg-white p-6 space-y-4">
                @csrf
                @method('PUT')
                @include('workspace.products.form', ['product' => $product, 'attributesJson' => $attributesJson, 'variantsJson' => $variantsJson])
                <button class="rounded-lg bg-blue-600 px-4 py-2 text-white">تحديث</button>
            </form>
        </div>
    </div>
</x-communication-layout>
