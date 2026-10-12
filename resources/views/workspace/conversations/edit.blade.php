<x-communication-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">المحادثة #{{ $conversation->id }}</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-6xl px-4 space-y-6">
            @include('workspace.partials.nav')
            @include('partials.flash')
            <form method="POST" action="{{ route('workspace.conversations.update', $conversation) }}" class="rounded-xl border bg-white p-6 space-y-4">
                @csrf
                @method('PUT')
                <div class="grid gap-4 md:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm">الحالة</label>
                        <select name="status" class="w-full rounded-lg border-gray-300">
                            @foreach(['open','closed','archived'] as $status)
                                <option value="{{ $status }}" @selected($conversation->status === $status)>{{ $status }}</option>
                            @endforeach
                        </select>
                    </div>
                    <label class="inline-flex items-center gap-2 mt-7">
                        <input type="checkbox" name="ai_enabled" value="1" @checked($conversation->ai_enabled)>
                        <span>AI Enabled</span>
                    </label>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Metadata JSON</label>
                    <textarea name="metadata_json" rows="4" class="w-full rounded-lg border-gray-300">{{ $metadataJson }}</textarea>
                </div>
                <button class="rounded-lg bg-blue-600 px-4 py-2 text-white">تحديث المحادثة</button>
            </form>

            <form method="POST" action="{{ route('workspace.conversations.messages.store', $conversation) }}" class="rounded-xl border bg-white p-6 space-y-4">
                @csrf
                <h3 class="font-semibold">إرسال رسالة</h3>
                <input type="hidden" name="conversation_id" value="{{ $conversation->id }}">
                <input type="hidden" name="customer_id" value="{{ $conversation->customer_id }}">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm">الاتجاه</label>
                        <select name="direction" class="w-full rounded-lg border-gray-300">
                            @foreach(['outbound','internal_note'] as $direction)
                                <option value="{{ $direction }}">{{ $direction }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">نوع الرسالة</label>
                        <select name="message_type" class="w-full rounded-lg border-gray-300">
                            @foreach(['text','image','file','system'] as $type)
                                <option value="{{ $type }}">{{ $type }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-sm">المحتوى</label>
                    <textarea name="content" rows="3" class="w-full rounded-lg border-gray-300"></textarea>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Metadata JSON</label>
                    <textarea name="metadata_json" rows="2" class="w-full rounded-lg border-gray-300">{}</textarea>
                </div>
                <button class="rounded-lg bg-green-600 px-4 py-2 text-white">إرسال</button>
            </form>

            <div class="rounded-xl border bg-white p-6">
                <h3 class="mb-4 font-semibold">سجل الرسائل</h3>
                <div class="space-y-3">
                    @forelse($conversation->messages as $message)
                        <div class="rounded-lg border border-gray-200 p-3">
                            <div class="mb-1 text-xs text-gray-500">{{ $message->direction }} • {{ $message->created_at }}</div>
                            <div class="text-sm text-gray-800">{{ $message->content ?? '-' }}</div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">لا توجد رسائل بعد.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-communication-layout>
