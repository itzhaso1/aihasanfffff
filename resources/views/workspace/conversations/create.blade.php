<x-communication-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">إنشاء محادثة</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-3xl px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')
            <form method="POST" action="{{ route('workspace.conversations.store') }}" class="rounded-xl border bg-white p-6 space-y-4">
                @csrf
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm">العميل</label>
                        <select name="customer_id" class="w-full rounded-lg border-gray-300">
                            <option value="">بدون عميل</option>
                            @foreach($customers as $customer)
                                <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">القناة</label>
                        <select name="channel" class="w-full rounded-lg border-gray-300">
                            @foreach(['whatsapp','instagram','facebook_messenger','email','web','manual'] as $channel)
                                <option value="{{ $channel }}">{{ $channel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">External ID</label>
                        <input name="external_id" class="w-full rounded-lg border-gray-300" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">الحالة</label>
                        <select name="status" class="w-full rounded-lg border-gray-300">
                            @foreach(['open','closed','archived'] as $status)
                                <option value="{{ $status }}">{{ $status }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" name="ai_enabled" value="1" checked />
                    <span>تفعيل AI</span>
                </label>
                <div>
                    <label class="mb-1 block text-sm">Metadata JSON</label>
                    <textarea name="metadata_json" rows="4" class="w-full rounded-lg border-gray-300">{}</textarea>
                </div>
                <button class="rounded-lg bg-blue-600 px-4 py-2 text-white">إنشاء</button>
            </form>
        </div>
    </div>
</x-communication-layout>
