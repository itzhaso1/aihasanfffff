@php($account = $account ?? null)
<div class="grid gap-4 md:grid-cols-2">
    <div>
        <label class="mb-1 block text-sm">Display Name</label>
        <input name="display_name" required value="{{ old('display_name', $account?->display_name) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm">Business Account ID</label>
        <input name="business_account_id" required value="{{ old('business_account_id', $account?->business_account_id) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm">App ID</label>
        <input name="app_id" value="{{ old('app_id', $account?->app_id) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm">Status</label>
        <select name="status" class="w-full rounded-lg border-gray-300">
            @foreach(['connected','pending','disconnected','error'] as $status)
                <option value="{{ $status }}" @selected(old('status', $account?->status ?? 'pending') === $status)>{{ $status }}</option>
            @endforeach
        </select>
    </div>
</div>
<div>
    <label class="mb-1 block text-sm">Metadata JSON</label>
    <textarea name="metadata_json" rows="4" class="w-full rounded-lg border-gray-300">{{ old('metadata_json', $metadataJson ?? json_encode($account?->metadata ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) }}</textarea>
</div>
<div>
    <label class="mb-1 block text-sm">Phone Numbers JSON</label>
    <textarea name="phone_numbers_json" rows="6" class="w-full rounded-lg border-gray-300">{{ old('phone_numbers_json', $phoneNumbersJson ?? '[]') }}</textarea>
    <p class="mt-1 text-xs text-gray-500">مثال: [{"phone_number_id":"123","display_phone_number":"+9665...","verified_name":"Store","status":"connected"}]</p>
</div>
