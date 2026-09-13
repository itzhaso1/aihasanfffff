@extends('platform.layout')

@section('content')
    <div class="py-8">
        <div class="mx-auto max-w-3xl px-4">
            @include('platform.partials.nav')
            @include('partials.flash')
            <form method="POST" action="{{ route('platform.workspaces.update', $workspace) }}" class="rounded-xl border bg-white p-6 space-y-4">
                @csrf @method('PUT')
                <div><label class="mb-1 block text-sm">الاسم</label><input name="name" value="{{ old('name', $workspace->name) }}" class="w-full rounded-lg border-gray-300" /></div>
                <div><label class="mb-1 block text-sm">Slug</label><input name="slug" value="{{ old('slug', $workspace->slug) }}" class="w-full rounded-lg border-gray-300" /></div>
                <div>
                    <label class="mb-1 block text-sm">النوع</label>
                    <select name="type" class="w-full rounded-lg border-gray-300">
                        @foreach(['individual','company','store'] as $type)
                            <option value="{{ $type }}" @selected(old('type', $workspace->type) === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm">الحالة</label>
                    <select name="status" class="w-full rounded-lg border-gray-300">
                        @foreach(['active','inactive','suspended'] as $status)
                            <option value="{{ $status }}" @selected(old('status', $workspace->status) === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="rounded-lg bg-blue-600 px-4 py-2 text-white">حفظ</button>
            </form>
        </div>
    </div>
@endsection
