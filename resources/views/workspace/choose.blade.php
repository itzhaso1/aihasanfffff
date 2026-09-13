<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            اختر مساحة العمل
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm rounded-xl border border-gray-100 p-6">
                @if(($workspaces?->count() ?? 0) === 0)
                    <p class="text-gray-600">لا توجد مساحات عمل مرتبطة بهذا الحساب حالياً.</p>
                @else
                    <div class="grid md:grid-cols-3 gap-4">
                        @foreach($workspaces as $workspace)
                            <form method="POST" action="{{ route('workspace.switch', $workspace) }}" class="border border-gray-200 rounded-lg p-4 bg-gray-50">
                                @csrf
                                <h3 class="text-lg font-semibold text-gray-900">{{ $workspace->name }}</h3>
                                <p class="text-sm text-gray-600 mt-1">النوع: {{ ucfirst($workspace->type) }}</p>
                                <p class="text-sm text-gray-600">الحالة: {{ $workspace->status }}</p>
                                <button class="mt-4 inline-flex items-center px-4 py-2 rounded-md bg-blue-600 text-white hover:bg-blue-700">
                                    دخول المساحة
                                </button>
                            </form>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
