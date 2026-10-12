@php
    $label = function (string $value): string {
        return [
            'sales_agent' => 'مندوب مبيعات',
            'customer_support' => 'دعم العملاء',
            'booking_agent' => 'منسق حجوزات',
            'receptionist' => 'موظف استقبال',
            'product_advisor' => 'مستشار منتجات',
            'general_business_assistant' => 'مساعد أعمال عام',
            'friendly' => 'ودود',
            'professional' => 'محترف',
            'confident' => 'واثق',
            'empathetic' => 'متعاطف',
            'energetic' => 'نشيط',
            'calm' => 'هادئ',
            'concise' => 'مختصر',
            'helpful' => 'متعاون',
            'auto' => 'تلقائي',
            'ar' => 'العربية',
            'en' => 'الإنجليزية',
            'neutral' => 'محايد',
            'jordanian' => 'أردنية',
            'saudi' => 'سعودية',
            'gulf' => 'خليجية',
            'egyptian' => 'مصرية',
            'short' => 'قصير',
            'medium' => 'متوسط',
            'long' => 'طويل',
            'casual' => 'عفوي',
            'casual_professional' => 'عفوي مهني',
            'formal' => 'رسمي',
            'warm' => 'دافئ',
            'none' => 'بدون',
            'minimal' => 'قليل',
            'moderate' => 'متوسط',
        ][$value] ?? str_replace('_', ' ', $value);
    };
    $persona = $selected?->persona;
    $model = $selected?->modelSettings;
    $temperature = old('model.temperature', $model?->temperature ?? 0.4);
    $responseLength = old('model.response_length', $model?->response_length?->value ?? 'short');
    $traits = old('persona.traits', $persona?->traits ?? []);
@endphp

<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">الذكاء الاصطناعي</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-4xl px-4" x-data="{ tab: @js($tab) }">
            @include('workspace.partials.nav')
            @include('partials.flash')

            <div class="mb-4 flex gap-6 border-b border-[#E5EAEA]">
                <button type="button" class="pb-3 text-sm font-semibold" :class="tab === 'general' ? 'border-b-2 border-[#00BFAE] text-[#17212B]' : 'text-[#7A858A]'" @click="tab = 'general'">عام</button>
                <button type="button" class="pb-3 text-sm font-semibold" :class="tab === 'personas' ? 'border-b-2 border-[#00BFAE] text-[#17212B]' : 'text-[#7A858A]'" @click="tab = 'personas'">الشخصيات</button>
            </div>

            <div x-show="tab === 'general'" class="space-y-4">
                @if (! $selected)
                    <section class="rounded-2xl border border-[#E5EAEA] bg-white p-5">
                        <h3 class="mb-2 text-sm font-semibold text-[#17212B]">الموظف</h3>
                        <p class="text-sm text-[#7A858A]">اختر شخصية من تبويب الشخصيات ليظهر هنا.</p>
                    </section>
                @else
                    <form method="POST" action="{{ route('workspace.ai-settings.employees.update', $selected) }}" class="space-y-4">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="tab" value="general">
                        <section class="rounded-2xl border border-[#E5EAEA] bg-white p-5">
                            <h3 class="mb-3 text-sm font-semibold text-[#17212B]">{{ $selected->name }}</h3>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-sm text-[#17212B]">اسم الموظف</label>
                                    <input name="name" required maxlength="64" value="{{ old('name', $selected->name) }}" class="w-full rounded-xl border-[#E5EAEA]">
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm text-[#17212B]">الدور</label>
                                    <select name="role" class="w-full rounded-xl border-[#E5EAEA]">
                                        @foreach ($aiRoles as $role)
                                            <option value="{{ $role }}" @selected(old('role', $selected->role) === $role)>{{ \App\Support\Chat\ChatAiRole::label($role) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </section>
                        <section class="rounded-2xl border border-[#E5EAEA] bg-white p-5">
                            <h3 class="mb-3 text-sm font-semibold text-[#17212B]">التعليمات</h3>
                            <label class="mb-1 block text-sm text-[#17212B]">تعليمات الموظف</label>
                            <textarea name="instructions" rows="5" maxlength="4000" class="mb-4 w-full rounded-xl border-[#E5EAEA]" placeholder="اكتب أسلوب الرد والحدود التي يجب أن يلتزم بها الموظف.">{{ old('instructions', $selected->instructions) }}</textarea>
                            <label class="mb-1 block text-sm text-[#17212B]">الخبرة</label>
                            <input name="experience_notes" maxlength="1000" value="{{ old('experience_notes', $selected->experience_notes) }}" class="w-full rounded-xl border-[#E5EAEA]" placeholder="خبرة 3 سنوات في المبيعات وخدمة العملاء">
                        </section>
                        <section class="rounded-2xl border border-[#E5EAEA] bg-white p-5">
                            <h3 class="mb-3 text-sm font-semibold text-[#17212B]">أسلوب الرد</h3>
                            <label class="mb-1 flex items-center justify-between text-sm text-[#17212B]">
                                <span>درجة الإبداع</span>
                                <span x-text="Number($refs.creativity.value).toFixed(2)"></span>
                            </label>
                            <input x-ref="creativity" type="range" name="model[temperature]" min="0" max="1" step="0.05" value="{{ $temperature }}" class="w-full accent-[#00BFAE]">
                            <p class="mb-3 mt-4 text-sm text-[#17212B]">طول الرد</p>
                            <div class="flex gap-2">
                                @foreach ($options['response_lengths'] as $length)
                                    <label class="flex-1 cursor-pointer rounded-xl border border-[#E5EAEA] px-3 py-2 text-center text-sm has-[:checked]:border-[#00BFAE] has-[:checked]:bg-[#E8F8F5]">
                                        <input type="radio" name="model[response_length]" value="{{ $length }}" class="sr-only" @checked($responseLength === $length)>
                                        {{ $label($length) }}
                                    </label>
                                @endforeach
                            </div>
                        </section>
                        <button class="h-12 w-full rounded-2xl bg-[#00BFAE] text-white">حفظ التغييرات</button>
                    </form>
                @endif
            </div>

            <div x-show="tab === 'personas'" x-cloak class="space-y-4">
                <p class="text-sm leading-7 text-[#7A858A]">كل شخصية لها اسمها وأسلوبها. الشخصية الافتراضية هي من ترد في المحادثات.</p>
                @forelse ($employees as $employee)
                    <article class="rounded-2xl border border-[#E5EAEA] bg-white p-5 {{ $selected && $selected->id === $employee->id ? 'ring-1 ring-[#00BFAE]' : '' }}">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-semibold text-[#17212B]">{{ $employee->name }}</h3>
                                <p class="mt-1 text-xs text-[#7A858A]">{{ \App\Support\Chat\ChatAiRole::label($employee->role) }} @if($employee->job_title) · {{ $employee->job_title }} @endif</p>
                            </div>
                            <div class="flex gap-2 text-xs">
                                @if ($employee->isDefault())
                                    <span class="rounded-full bg-[#E8F8F5] px-2 py-1 text-[#067E6B]">افتراضي</span>
                                @endif
                                <span class="rounded-full px-2 py-1 {{ $employee->isActive() ? 'bg-[#E8F8F5] text-[#067E6B]' : 'bg-[#F4F6F6] text-[#7A858A]' }}">{{ $employee->isActive() ? 'مفعّل' : 'متوقف' }}</span>
                            </div>
                        </div>
                        <p class="mt-3 text-xs text-[#7A858A]">
                            {{ $label($employee->persona?->dialect ?? 'auto') }} · {{ $label($employee->persona?->tone ?? 'neutral') }}
                        </p>
                        <div class="mt-4 flex flex-wrap gap-2 text-sm">
                            <a href="{{ route('workspace.ai-settings.edit', ['tab' => 'general', 'employee' => $employee->id]) }}" class="rounded-xl border border-[#00BFAE] px-3 py-2 text-[#00BFAE]">تعديل في عام</a>
                            @unless ($employee->isDefault())
                                <form method="POST" action="{{ route('workspace.ai-settings.employees.default', $employee) }}">@csrf<button class="rounded-xl border border-[#E5EAEA] px-3 py-2">تعيين افتراضي</button></form>
                            @endunless
                            @if ($employee->isActive())
                                <form method="POST" action="{{ route('workspace.ai-settings.employees.deactivate', $employee) }}">@csrf<button class="rounded-xl border border-[#E5EAEA] px-3 py-2">إيقاف</button></form>
                            @else
                                <form method="POST" action="{{ route('workspace.ai-settings.employees.activate', $employee) }}">@csrf<button class="rounded-xl border border-[#E5EAEA] px-3 py-2">تفعيل</button></form>
                            @endif
                            @unless ($employee->isDefault())
                                <form method="POST" action="{{ route('workspace.ai-settings.employees.destroy', $employee) }}" onsubmit="return confirm('حذف هذه الشخصية؟')">@csrf @method('DELETE')<button class="rounded-xl px-3 py-2 text-[#EF4444]">حذف</button></form>
                            @endunless
                        </div>
                    </article>
                @empty
                    <section class="rounded-2xl border border-[#E5EAEA] bg-white p-5 text-sm text-[#17212B]">لا توجد شخصيات بعد. أضف شخصية من قالب.</section>
                @endforelse

                @if ($selected)
                    <form method="POST" action="{{ route('workspace.ai-settings.employees.update', $selected) }}" class="rounded-2xl border border-[#E5EAEA] bg-white p-5">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="tab" value="personas">
                        <h3 class="mb-3 text-sm font-semibold text-[#17212B]">شخصية {{ $selected->name }}</h3>
                        <div class="grid gap-4 md:grid-cols-2">
                            @foreach (['language' => 'اللغة', 'dialect' => 'اللهجة', 'formality' => 'الرسمية', 'tone' => 'النبرة', 'emoji_policy' => 'الإيموجي'] as $field => $title)
                                @php
                                    $choices = match ($field) {
                                        'language' => $options['languages'],
                                        'dialect' => $options['dialects'],
                                        'formality' => $options['formality'],
                                        'tone' => $options['tones'],
                                        default => $options['emoji_policies'],
                                    };
                                    $current = old('persona.'.$field, $persona?->{$field} ?? 'auto');
                                @endphp
                                <div>
                                    <label class="mb-1 block text-sm">{{ $title }}</label>
                                    <select name="persona[{{ $field }}]" class="w-full rounded-xl border-[#E5EAEA]">
                                        @foreach ($choices as $choice)
                                            <option value="{{ $choice }}" @selected($current === $choice)>{{ $label($choice) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endforeach
                        </div>
                        <p class="mb-2 mt-4 text-sm text-[#17212B]">السمات</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($options['traits'] as $trait)
                                <label class="cursor-pointer rounded-full border border-[#E5EAEA] px-3 py-1.5 text-sm has-[:checked]:border-[#00BFAE] has-[:checked]:bg-[#E8F8F5]">
                                    <input type="checkbox" name="persona[traits][]" value="{{ $trait }}" class="sr-only" @checked(in_array($trait, $traits, true))>
                                    {{ $label($trait) }}
                                </label>
                            @endforeach
                        </div>
                        <button class="mt-4 rounded-xl bg-[#00BFAE] px-4 py-2 text-white">حفظ الشخصية</button>
                    </form>
                @endif

                @if ($presets !== [])
                    <form method="POST" action="{{ route('workspace.ai-settings.employees.store') }}" class="flex flex-wrap items-end gap-3 rounded-2xl border border-[#E5EAEA] bg-white p-5">
                        @csrf
                        <div class="min-w-56 flex-1">
                            <label class="mb-1 block text-sm">إضافة من قالب</label>
                            <select name="preset_key" class="w-full rounded-xl border-[#E5EAEA]">
                                @foreach ($presets as $preset)
                                    <option value="{{ $preset->key }}">{{ $preset->displayName }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button class="rounded-xl border border-[#00BFAE] px-4 py-2 text-[#00BFAE]">إضافة</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
