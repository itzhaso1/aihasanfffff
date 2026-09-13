@php($gateway = $gateway ?? null)
<div>
    <label class="mb-1 block text-sm">المزوّد</label>
    <select name="provider" class="w-full rounded-lg border-gray-300">
        @foreach(['local','stripe'] as $provider)
            <option value="{{ $provider }}" @selected(old('provider', $gateway?->provider ?? 'local') === $provider)>{{ $provider }}</option>
        @endforeach
    </select>
</div>
<div>
    <label class="mb-1 block text-sm">الحالة</label>
    <select name="status" class="w-full rounded-lg border-gray-300">
        @foreach(['connected','pending','disconnected','error'] as $status)
            <option value="{{ $status }}" @selected(old('status', $gateway?->status ?? 'pending') === $status)>{{ $status }}</option>
        @endforeach
    </select>
</div>
<div>
    <label class="mb-1 block text-sm">Config JSON</label>
    <textarea name="config_json" rows="6" class="w-full rounded-lg border-gray-300">{{ old('config_json', $configJson ?? json_encode($gateway?->config ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) }}</textarea>
</div>
