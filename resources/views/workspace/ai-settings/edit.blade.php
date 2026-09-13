<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">إعدادات الذكاء الاصطناعي</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-4xl px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')
            <form method="POST" action="{{ route('workspace.ai-settings.update') }}" class="rounded-xl border bg-white p-6 space-y-4">
                @csrf
                @method('PUT')
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm">اسم المساعد</label>
                        <input name="name" required value="{{ old('name', $setting?->name ?? 'AI Assistant') }}" class="w-full rounded-lg border-gray-300" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Provider</label>
                        <select name="provider" class="w-full rounded-lg border-gray-300">
                            @foreach(['google_ai_studio' => 'Google AI Studio (Gemini)', 'openai' => 'OpenAI'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('provider', $setting?->provider ?? config('ai.default_provider')) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Model</label>
                        <input name="model" required value="{{ old('model', $setting?->model ?? config('ai.google_ai_studio.model')) }}" class="w-full rounded-lg border-gray-300" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Max Tokens</label>
                        <input type="number" name="max_tokens" value="{{ old('max_tokens', $setting?->max_tokens ?? 512) }}" class="w-full rounded-lg border-gray-300" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Temperature</label>
                        <input type="number" step="0.1" name="temperature" value="{{ old('temperature', $setting?->temperature ?? 0.4) }}" class="w-full rounded-lg border-gray-300" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Tone</label>
                        <input name="tone" value="{{ old('tone', $setting?->tone) }}" class="w-full rounded-lg border-gray-300" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Reply Style</label>
                        <input name="reply_style" value="{{ old('reply_style', $setting?->reply_style) }}" class="w-full rounded-lg border-gray-300" />
                    </div>
                    <label class="inline-flex items-center gap-2 mt-7">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $setting?->is_active ?? true)) />
                        <span>مفعل</span>
                    </label>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Instructions</label>
                    <textarea name="instructions" rows="4" class="w-full rounded-lg border-gray-300">{{ old('instructions', $setting?->instructions) }}</textarea>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Rules JSON</label>
                    <textarea name="rules_json" rows="5" class="w-full rounded-lg border-gray-300">{{ old('rules_json', $rulesJson) }}</textarea>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Business Information JSON</label>
                    <textarea name="business_information_json" rows="5" class="w-full rounded-lg border-gray-300">{{ old('business_information_json', $businessInfoJson) }}</textarea>
                </div>
                <button class="rounded-lg bg-[#06C2A4] px-4 py-2 text-white hover:bg-[#04a98e]">حفظ الإعدادات</button>
            </form>
        </div>
    </div>
</x-app-layout>
