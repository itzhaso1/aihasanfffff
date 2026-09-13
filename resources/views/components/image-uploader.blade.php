@props([
    'name' => 'image',
    'label' => 'صورة',
    'currentUrl' => null,
    'altName' => null,
    'altValue' => '',
    'hint' => 'JPEG / PNG / WebP / GIF — الحد الأقصى 5 ميجابايت',
    'required' => false,
])

@php
    $altField = $altName ?: ($name.'_alt');
@endphp

<div
    x-data="{
        preview: @js($currentUrl),
        fileName: '',
        error: '',
        maxBytes: 5 * 1024 * 1024,
        accept: ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        onSelect(event) {
            this.error = '';
            const file = event.target.files?.[0];
            if (!file) return;
            if (!this.accept.includes(file.type)) {
                this.error = 'صيغة غير مدعومة. استخدم JPEG أو PNG أو WebP أو GIF.';
                event.target.value = '';
                return;
            }
            if (file.size > this.maxBytes) {
                this.error = 'حجم الملف أكبر من 5 ميجابايت.';
                event.target.value = '';
                return;
            }
            this.fileName = file.name;
            const reader = new FileReader();
            reader.onload = (e) => { this.preview = e.target.result; };
            reader.readAsDataURL(file);
        },
        replace() {
            this.$refs.input.click();
        },
        remove() {
            this.preview = null;
            this.fileName = '';
            this.error = '';
            if (this.$refs.input) this.$refs.input.value = '';
        }
    }"
    class="space-y-2"
    dir="rtl"
>
    <label class="mb-1 block text-xs font-semibold text-slate-600">{{ $label }}</label>

    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-3">
        <template x-if="preview">
            <div class="mb-3 flex items-center gap-3">
                <img :src="preview" alt="" class="h-16 w-auto max-w-[180px] rounded-lg object-contain bg-white">
                <div class="text-xs text-slate-500">
                    <p x-show="fileName" x-text="fileName"></p>
                    <p x-show="!fileName && preview">الصورة الحالية</p>
                </div>
            </div>
        </template>

        <input
            x-ref="input"
            type="file"
            name="{{ $name }}"
            accept="image/jpeg,image/png,image/webp,image/gif,.jpg,.jpeg,.png,.webp,.gif"
            class="block w-full text-sm text-slate-600 file:ml-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white"
            @change="onSelect($event)"
            @if($required) required @endif
        >

        <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" @click="replace()" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">استبدال</button>
            <button type="button" @click="remove()" class="rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-50">إزالة</button>
        </div>

        <p class="mt-2 text-[11px] text-slate-500">{{ $hint }}</p>
        <p x-show="error" x-text="error" class="mt-1 text-xs font-semibold text-rose-600"></p>
    </div>

    <div>
        <label class="mb-1 block text-xs font-semibold text-slate-600">نص بديل (اختياري)</label>
        <input
            type="text"
            name="{{ $altField }}"
            value="{{ old($altField, $altValue) }}"
            maxlength="255"
            class="w-full rounded-xl border-slate-300 text-sm"
            placeholder="وصف مختصر للصورة"
        >
    </div>
</div>
