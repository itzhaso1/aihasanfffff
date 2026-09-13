<x-communication-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">إضافة تصنيف</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-3xl px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')
            <form method="POST" action="{{ route('workspace.categories.store') }}" class="rounded-xl border bg-white p-6 space-y-4">
                @csrf
                @include('workspace.categories.form')
                <button class="rounded-lg bg-blue-600 px-4 py-2 text-white">حفظ</button>
            </form>
        </div>
    </div>
</x-communication-layout>
