<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">الإشعارات</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white border border-gray-100 rounded-xl shadow-sm">
                @forelse($notifications as $notification)
                    <div class="p-4 border-b border-gray-100 flex items-start justify-between gap-4">
                        <div>
                            <p class="font-medium text-gray-900">{{ $notification->data['type'] ?? 'notification' }}</p>
                            <pre class="text-sm text-gray-600 whitespace-pre-wrap">{{ json_encode($notification->data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                            <p class="text-xs text-gray-500 mt-2">{{ $notification->created_at }}</p>
                        </div>
                        @if(!$notification->read_at)
                            <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                                @csrf
                                <button class="px-3 py-2 rounded-md bg-blue-600 text-white text-sm">تحديد كمقروء</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <div class="p-6 text-gray-600">لا توجد إشعارات حالياً.</div>
                @endforelse
            </div>
            <div class="mt-4">
                {{ $notifications->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
