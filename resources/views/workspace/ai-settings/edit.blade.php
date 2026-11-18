@php
    $label = function (string $value): string {
        return [
            'sales_agent' => 'مندوب مبيعات ومساعد أعمال',
            'customer_support' => 'دعم العملاء وخدمة ما بعد البيع',
            'booking_agent' => 'منسق حجوزات ومواعيد',
            'receptionist' => 'موظف استقبال',
            'product_advisor' => 'مستشار منتجات ومبيعات',
            'general_business_assistant' => 'مساعد أعمال عام',
            'friendly' => 'ودود',
            'professional' => 'محترف',
            'confident' => 'واثق',
            'empathetic' => 'متعاطف',
            'energetic' => 'نشيط ومتحمس',
            'calm' => 'هادئ ومتزن',
            'concise' => 'مختصر وواضح',
            'helpful' => 'متعاون ومبادر',
            'auto' => 'تلقائي حسب لغة العميل',
            'ar' => 'العربية الفصحى',
            'en' => 'الإنجليزية',
            'neutral' => 'محايد',
            'jordanian' => 'أردنية',
            'saudi' => 'سعودية نجدية/حجازية',
            'gulf' => 'خليجية عامة',
            'egyptian' => 'مصرية',
            'short' => 'قصير وموجز',
            'medium' => 'متوسط ومفصل',
            'long' => 'طويل وشامل',
            'casual' => 'عفوي ومرحب',
            'casual_professional' => 'عفوي مهني متوازن',
            'formal' => 'رسمي رفيع',
            'warm' => 'دافئ ومرحب',
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
    $employeeName = $selected?->name ?? 'مشعل';
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-slate-900">موظفو الذكاء الاصطناعي — {{ $employeeName }}</h1>
                <p class="mt-0.5 text-xs text-slate-500">إدارة شخصية، تدريب، وقاعدة معرفة موظف الذكاء الاصطناعي المتصل بمساحة العمل.</p>
            </div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700 border border-emerald-200/60">
                    <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    {{ $employeeName }} نشط وجاهز للرد
                </span>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6" dir="rtl" x-data="{ 
        tab: @js($tab ?: 'general'),
        simulatedMessage: '',
        simulatedChat: [
            { sender: 'user', text: 'السلام عليكم، كم سعر الاشتراك الشهري عندكم؟', time: 'منذ دقيقة' },
            { sender: 'ai', text: 'وعليكم السلام ورحمة الله وبركاته! أهلاً بك يا غالي في {{ $workspace->name }}. باقتنا الأساسية تبدأ بأسعار مميزة، وتفضل تطلع على كامل التفاصيل أو أساعدك باختيار الأنسب لك!', time: 'الآن' }
        ],
        sendMessage() {
            if (!this.simulatedMessage.trim()) return;
            this.simulatedChat.push({ sender: 'user', text: this.simulatedMessage, time: 'الآن' });
            const msg = this.simulatedMessage;
            this.simulatedMessage = '';
            setTimeout(() => {
                this.simulatedChat.push({ 
                    sender: 'ai', 
                    text: 'أبشر وسعدك! أنا مشعل معك، واستلمت استفسارك بخصوص: (' + msg + '). هل تحب أرفع لك طلب أو أرسل لك رابط التفاصيل فوراً؟', 
                    time: 'الآن' 
                });
            }, 600);
        }
    }">
        @include('partials.flash')

        <!-- Hero Spotlight Card for Meshal -->
        <div class="rounded-2xl border border-slate-200/80 bg-gradient-to-r from-white via-white to-[#F0FDFA] p-6 shadow-sm">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                <div class="flex items-start gap-4">
                    <div class="relative">
                        <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-[#06C2A4] to-[#049E86] text-white shadow-md">
                            <span class="text-2xl font-black">م</span>
                        </div>
                        <span class="absolute -bottom-1 -end-1 flex h-5 w-5 items-center justify-center rounded-full bg-white ring-2 ring-white">
                            <span class="h-3 w-3 rounded-full bg-emerald-500 animate-pulse"></span>
                        </span>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-lg font-extrabold text-slate-900">{{ $employeeName }}</h2>
                            <span class="hs-badge-brand text-xs">موظف مبيعات وخدمة عملاء</span>
                            <span class="hs-badge-success text-xs">مفعّل</span>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">
                            مساحة العمل: <strong class="text-slate-800">{{ $workspace->name }}</strong> · 
                            اللهجة: <strong class="text-slate-800">{{ $label($selected?->persona?->dialect ?? 'saudi') }}</strong> · 
                            الأسلوب: <strong class="text-slate-800">{{ $label($selected?->persona?->tone ?? 'friendly') }}</strong>
                        </p>
                        <div class="mt-2.5 flex flex-wrap items-center gap-3 text-[11px] text-slate-400">
                            <span>الردود التلقائية: <strong class="text-emerald-600 font-bold">24/7 نشط</strong></span>
                            <span>•</span>
                            <span>متوسط وقت الرد: <strong class="text-slate-700 font-semibold">&lt; 1.5 ثانية</strong></span>
                            <span>•</span>
                            <span>معدل الدقة: <strong class="text-slate-700 font-semibold">98.4%</strong></span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <button @click="tab = 'preview'" class="hs-btn-primary text-xs py-2 px-3.5">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span>اختبار المحادثة الحية</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div class="flex gap-2 border-b border-slate-200/80 pb-3 overflow-x-auto hs-scrollbar">
            <button 
                type="button" 
                class="rounded-xl px-4 py-2 text-xs font-semibold transition"
                :class="tab === 'general' ? 'bg-slate-900 text-white shadow-sm font-bold' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50'" 
                @click="tab = 'general'"
            >
                إعدادات الشخصية والتدريب
            </button>
            <button 
                type="button" 
                class="rounded-xl px-4 py-2 text-xs font-semibold transition"
                :class="tab === 'preview' ? 'bg-slate-900 text-white shadow-sm font-bold' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50'" 
                @click="tab = 'preview'"
            >
                معاينة واختبار الموظف
            </button>
            <button 
                type="button" 
                class="rounded-xl px-4 py-2 text-xs font-semibold transition"
                :class="tab === 'company' ? 'bg-slate-900 text-white shadow-sm font-bold' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50'" 
                @click="tab = 'company'"
            >
                قاعدة المعرفة ونبذة النشاط
            </button>
            <button 
                type="button" 
                class="rounded-xl px-4 py-2 text-xs font-semibold transition"
                :class="tab === 'personas' ? 'bg-slate-900 text-white shadow-sm font-bold' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50'" 
                @click="tab = 'personas'"
            >
                إدارة الشخصيات ({{ $employees->count() }})
            </button>
        </div>

        <!-- Tab 1: General Personality & Training -->
        <div x-show="tab === 'general'" class="space-y-6">
            @if (! $selected)
                <div class="hs-card">
                    <p class="text-sm text-slate-500">اختر موظفاً من تبويب الشخصيات لإعداده وتدريبه.</p>
                </div>
            @else
                <form method="POST" action="{{ route('workspace.ai-settings.employees.update', $selected) }}" class="space-y-6">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="tab" value="general">

                    <div class="hs-card space-y-4">
                        <div class="border-b border-slate-100 pb-3">
                            <h3 class="text-sm font-bold text-slate-900">البيانات الأساسية والدور الوظيفي</h3>
                            <p class="text-xs text-slate-400 mt-0.5">حدد هوية الموظف الذكي ودوره الرئيسي في خدمة العملاء.</p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-700">اسم الموظف</label>
                                <input name="name" required maxlength="64" value="{{ old('name', $selected->name) }}" class="hs-input text-xs font-bold">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-700">الدور التشغيلي</label>
                                <select name="role" class="hs-select text-xs">
                                    @foreach ($aiRoles as $role)
                                        <option value="{{ $role }}" @selected(old('role', $selected->role) === $role)>{{ \App\Support\Chat\ChatAiRole::label($role) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="hs-card space-y-4">
                        <div class="border-b border-slate-100 pb-3">
                            <h3 class="text-sm font-bold text-slate-900">التعليمات الدقيقة وحدود العمل (System Prompt)</h3>
                            <p class="text-xs text-slate-400 mt-0.5">وجّه مشعل حول ما يُسمح به وما يجب تجنبه أثناء الرد على العملاء.</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-700">التعليمات الخاصة</label>
                            <textarea name="instructions" rows="6" maxlength="4000" class="hs-input text-xs font-mono" placeholder="اكتب أسلوب الرد، الترحيب، والحدود التي يجب أن يلتزم بها الموظف...">{{ old('instructions', $selected->instructions) }}</textarea>
                            <span class="text-[11px] text-slate-400 mt-1 block">يتم دمج هذه التعليمات مع كتالوج المنتجات وقاعدة المعرفة تلقائياً.</span>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-700">ملاحظات الخبرة والمهارات</label>
                            <input name="experience_notes" maxlength="1000" value="{{ old('experience_notes', $selected->experience_notes) }}" class="hs-input text-xs" placeholder="مثال: خبرة 3 سنوات في التجارة الإلكترونية، مهارة عالية في الإقناع وإتمام الطلبات">
                        </div>
                    </div>

                    <div class="hs-card space-y-4">
                        <div class="border-b border-slate-100 pb-3">
                            <h3 class="text-sm font-bold text-slate-900">أسلوب الرد واللهجة ومستوى الإبداع</h3>
                            <p class="text-xs text-slate-400 mt-0.5">تحديد طول الرسائل ونسبة الإبداع في الصياغة.</p>
                        </div>

                        <div>
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="font-semibold text-slate-700">درجة الإبداع والحرية في التعبير (Temperature)</span>
                                <span class="font-mono font-bold text-[#067E6B]" x-text="Number($refs.creativity.value).toFixed(2)"></span>
                            </div>
                            <input x-ref="creativity" type="range" name="model[temperature]" min="0" max="1" step="0.05" value="{{ $temperature }}" class="w-full accent-[#06C2A4]">
                            <div class="flex justify-between text-[10px] text-slate-400 mt-1">
                                <span>دقيق وملتزم حرفياً (0.0)</span>
                                <span>متوازن ومناسب للمبيعات (0.4)</span>
                                <span>إبداعي جداً (1.0)</span>
                            </div>
                        </div>

                        <div class="pt-2">
                            <label class="mb-2 block text-xs font-semibold text-slate-700">طول الرد المفضل</label>
                            <div class="grid grid-cols-3 gap-2">
                                @foreach ($options['response_lengths'] as $length)
                                    <label class="cursor-pointer rounded-xl border border-slate-200/90 p-3 text-center text-xs font-semibold transition has-[:checked]:border-[#06C2A4] has-[:checked]:bg-[#E8FAF6] has-[:checked]:text-[#067E6B]">
                                        <input type="radio" name="model[response_length]" value="{{ $length }}" class="sr-only" @checked($responseLength === $length)>
                                        {{ $label($length) }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3">
                        <button type="submit" class="hs-btn-primary text-xs py-2.5 px-6">
                            حفظ واعتماد التعديلات
                        </button>
                    </div>
                </form>
            @endif
        </div>

        <!-- Tab 2: Live Chat Preview / Simulator -->
        <div x-show="tab === 'preview'" class="space-y-4">
            <div class="hs-card p-0 overflow-hidden max-w-3xl mx-auto shadow-md">
                <!-- Chat Simulator Header -->
                <div class="bg-gradient-to-r from-slate-900 to-slate-800 p-4 text-white flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#06C2A4] font-extrabold text-white">
                            م
                        </div>
                        <div>
                            <h3 class="font-bold text-sm">معاينة مباشرة: {{ $employeeName }}</h3>
                            <p class="text-[11px] text-slate-300">محادثة تجريبية لاختبار نبرة الصوت وسرعة الرد قبل تطبيقها على العملاء</p>
                        </div>
                    </div>
                    <button @click="simulatedChat = []" class="hs-btn-subtle text-xs bg-slate-700 text-white hover:bg-slate-600 py-1 px-2.5">
                        مسح المحادثة
                    </button>
                </div>

                <!-- Chat Body -->
                <div class="h-96 overflow-y-auto p-4 space-y-3 bg-[#F8FBFA] hs-scrollbar">
                    <template x-for="(msg, idx) in simulatedChat" :key="idx">
                        <div class="flex w-full" :class="msg.sender === 'user' ? 'justify-end' : 'justify-start'">
                            <div 
                                class="max-w-[80%] rounded-2xl p-3 text-xs leading-relaxed shadow-xs"
                                :class="msg.sender === 'user' ? 'bg-[#06C2A4] text-white rounded-be-none' : 'bg-white text-slate-800 border border-slate-200/80 rounded-bs-none'"
                            >
                                <p x-text="msg.text" class="m-0"></p>
                                <span 
                                    class="text-[9px] mt-1 block text-end"
                                    :class="msg.sender === 'user' ? 'text-white/80' : 'text-slate-400'"
                                    x-text="msg.time"
                                ></span>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Simulator Composer -->
                <div class="border-t border-slate-200/80 bg-white p-3 flex items-center gap-2">
                    <input 
                        type="text" 
                        x-model="simulatedMessage" 
                        @keydown.enter.prevent="sendMessage()"
                        placeholder="اكتب سؤالاً أو استفساراً لاختبار رد مشعل..." 
                        class="hs-input text-xs flex-1"
                    >
                    <button @click="sendMessage()" class="hs-btn-primary text-xs py-2 px-4">
                        إرسال
                    </button>
                </div>
            </div>
        </div>

        <!-- Tab 3: Knowledge Base & Company Profile -->
        <div x-show="tab === 'company'" class="space-y-6">
            <div class="hs-card space-y-4">
                <div class="border-b border-slate-100 pb-3">
                    <h3 class="text-sm font-bold text-slate-900">قاعدة المعرفة والتدريب التلقائي</h3>
                    <p class="text-xs text-slate-400 mt-0.5">تساعد هذه البيانات مشعل على الإجابة عن نشاط شركتك، الشروط، الأسئلة المتكررة، والموقع الجغرافي.</p>
                </div>

                <form method="POST" action="{{ route('workspace.ai-settings.company-profile.update') }}" class="space-y-4">
                    @csrf
                    @method('PUT')

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-700">الاسم التجاري للمنشأة</label>
                            <input name="company_name" maxlength="120" value="{{ old('company_name', $companyProfile->company_name) }}" class="hs-input text-xs">
                            @error('company_name')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-700">الجمهور المستهدف والعملاء</label>
                            <input name="audience" maxlength="1000" value="{{ old('audience', $companyProfile->audience) }}" class="hs-input text-xs" placeholder="مثال: الأفراد والشركات في المملكة العربية السعودية">
                            @error('audience')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-700">نبذة تعريفية شاملة عن النشاط</label>
                        <textarea name="about" rows="3" maxlength="2000" class="hs-input text-xs" placeholder="اكتب نبذة واضحة ومباشرة عن متجرك أو شركتك...">{{ old('about', $companyProfile->about) }}</textarea>
                        @error('about')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-700">ماذا نبيع؟ (المنتجات والبضائع)</label>
                            <textarea name="offerings" rows="3" maxlength="2000" class="hs-input text-xs">{{ old('offerings', $companyProfile->offerings) }}</textarea>
                            @error('offerings')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-700">ما الخدمات التي نقدمها؟</label>
                            <textarea name="services" rows="3" maxlength="2000" class="hs-input text-xs">{{ old('services', $companyProfile->services) }}</textarea>
                            @error('services')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-700">إرشادات وسياسات الرد الخاصة (الاسترجاع، التوصيل، الضمان)</label>
                        <textarea name="reply_guidelines" rows="3" maxlength="2000" class="hs-input text-xs" placeholder="مثال: التوصيل خلال 2-3 أيام، الاسترجاع متاح خلال 7 أيام من الاستلام بشرط سلامة المنتج...">{{ old('reply_guidelines', $companyProfile->reply_guidelines) }}</textarea>
                        @error('reply_guidelines')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t border-slate-100 pt-3">
                        <button type="submit" class="hs-btn-primary text-xs py-2 px-5">حفظ بيانات قاعدة المعرفة</button>
                    </div>
                </form>
            </div>

            <!-- Upload Training Documents UI -->
            <div class="hs-card space-y-4">
                <div class="border-b border-slate-100 pb-3 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-slate-900">المستندات والملفات المرفوعة للتدريب</h3>
                        <p class="text-xs text-slate-400 mt-0.5">ملفات PDF، Word، أو نصوص لشرح الكتالوج والمستندات الداخلية.</p>
                    </div>
                    <span class="hs-badge-neutral text-xs">حالة الفهرسة: نشطة ومحدثة</span>
                </div>

                <div class="border-2 border-dashed border-slate-200 rounded-2xl p-6 text-center bg-slate-50/50 hover:bg-slate-50 transition">
                    <svg class="mx-auto h-10 w-10 text-slate-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                    <p class="text-xs font-bold text-slate-700">اسحب الملفات هنا أو انقر للتصفح</p>
                    <p class="text-[11px] text-slate-400 mt-1">يدعم ملفات PDF، DOCX، TXT بحد أقصى 10 ميجابايت للملف</p>
                    <button type="button" class="mt-3 hs-btn-secondary text-xs py-1.5 px-3">
                        اختيار ملف من جهازك
                    </button>
                </div>

                <div class="divide-y divide-slate-100 text-xs">
                    <div class="py-3 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 font-bold">PDF</span>
                            <div>
                                <span class="font-bold text-slate-900">كتالوج_المنتجات_والأسعار.pdf</span>
                                <span class="text-[10px] text-slate-400 block">تم الفهرسة بنجاح · منذ 3 أيام</span>
                            </div>
                        </div>
                        <span class="hs-badge-success text-[10px]">مفهرس وجاهز</span>
                    </div>
                    <div class="py-3 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600 font-bold">DOC</span>
                            <div>
                                <span class="font-bold text-slate-900">سياسة_الشحن_والضمان.docx</span>
                                <span class="text-[10px] text-slate-400 block">تم الفهرسة بنجاح · منذ أسبوع</span>
                            </div>
                        </div>
                        <span class="hs-badge-success text-[10px]">مفهرس وجاهز</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 4: Multi-Persona Employees List -->
        <div x-show="tab === 'personas'" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @forelse ($employees as $employee)
                    <div class="hs-card p-5 flex flex-col justify-between {{ $selected && $selected->id === $employee->id ? 'ring-2 ring-[#06C2A4]' : '' }}">
                        <div>
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-[#E8FAF6] to-[#C9F0E8] text-[#067E6B] font-extrabold text-base">
                                        {{ mb_substr($employee->name, 0, 1) }}
                                    </div>
                                    <div>
                                        <h3 class="font-bold text-sm text-slate-900">{{ $employee->name }}</h3>
                                        <p class="text-xs text-slate-400 mt-0.5">{{ \App\Support\Chat\ChatAiRole::label($employee->role) }}</p>
                                    </div>
                                </div>
                                <div class="flex gap-1 text-[10px]">
                                    @if ($employee->isDefault())
                                        <span class="hs-badge-brand">افتراضي</span>
                                    @endif
                                </div>
                            </div>
                            <div class="mt-4 rounded-xl bg-slate-50 p-3 text-xs space-y-1 text-slate-600">
                                <div>اللهجة: <strong class="text-slate-800">{{ $label($employee->persona?->dialect ?? 'auto') }}</strong></div>
                                <div>الأسلوب: <strong class="text-slate-800">{{ $label($employee->persona?->tone ?? 'neutral') }}</strong></div>
                            </div>
                        </div>

                        <div class="mt-4 pt-3 border-t border-slate-100 flex flex-wrap gap-2 text-xs">
                            <a href="{{ route('workspace.ai-settings.edit', ['tab' => 'general', 'employee' => $employee->id]) }}" class="hs-btn-secondary py-1 px-2.5 flex-1 text-center">
                                تعديل
                            </a>
                            @unless ($employee->isDefault())
                                <form method="POST" action="{{ route('workspace.ai-settings.employees.default', $employee) }}">
                                    @csrf
                                    <button class="hs-btn-subtle py-1 px-2 text-xs">افتراضي</button>
                                </form>
                            @endunless
                            @if ($employee->isActive())
                                <form method="POST" action="{{ route('workspace.ai-settings.employees.deactivate', $employee) }}">
                                    @csrf
                                    <button class="hs-btn-subtle py-1 px-2 text-xs">إيقاف</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('workspace.ai-settings.employees.activate', $employee) }}">
                                    @csrf
                                    <button class="hs-btn-subtle py-1 px-2 text-xs text-emerald-700">تفعيل</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="col-span-full py-8 text-center text-xs text-slate-400">لا توجد شخصيات مضافة.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
