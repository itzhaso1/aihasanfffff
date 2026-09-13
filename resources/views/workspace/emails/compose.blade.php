@extends('layouts.email', ['pageTitle' => 'كتابة رسالة'])

@section('content')
    <div class="grid gap-4 lg:grid-cols-[1fr_320px]">
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-base font-bold text-slate-900">محرر الرسائل</h2>
                <form method="POST" action="{{ route('workspace.emails.compose.clear') }}">
                    @csrf
                    <input type="hidden" name="account_id" value="{{ $draft['email_account_id'] ?? '' }}">
                    <button type="submit" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-100">
                        رسالة جديدة (مسح المسودة)
                    </button>
                </form>
            </div>

            @if($accounts->isEmpty())
                <p class="rounded-lg border border-dashed border-slate-300 p-4 text-sm text-slate-500">
                    لا يوجد أي حساب بريد مضاف. أضف شركة من قسم "الشركات / حسابات البريد" ثم ارجع للإرسال.
                </p>
            @else
                <form method="POST"
                      action="{{ route('workspace.emails.messages.send') }}"
                      enctype="multipart/form-data"
                      class="space-y-4"
                      x-data="composeForm({
                        searchUrl: '{{ route('workspace.emails.contacts.search') }}',
                        initialRecipient: @js(old('recipient', $draft['recipient'] ?? '')),
                        initialSelectedContactIds: @js(old('recipient_contact_ids', $draft['recipient_contact_ids'] ?? [])),
                        initialSelectedContacts: @js($selectedContacts->map(fn ($contact) => ['id' => $contact->id, 'name' => $contact->name, 'email' => $contact->email])->values()->all()),
                      })">
                    @csrf
                    <input type="hidden" name="reply_to_message_id" value="{{ $draft['reply_to_message_id'] ?? '' }}">
                    <template x-for="contactId in selectedContactIds" :key="`contact-${contactId}`">
                        <input type="hidden" name="recipient_contact_ids[]" :value="contactId">
                    </template>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-slate-700">الإرسال من:</label>
                            <select name="email_account_id" class="w-full rounded-lg border-slate-300 text-sm" required>
                                @foreach($accounts as $account)
                                    <option value="{{ $account->id }}" @selected((int) ($draft['email_account_id'] ?? 0) === $account->id)>
                                        {{ $account->name }} — {{ $account->email }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-slate-700">اسم مرسل إضافي (اختياري)</label>
                            <input type="text" name="sender_alias" value="{{ $draft['sender_alias'] ?? '' }}" placeholder="مثل: الدعم الفني"
                                   class="w-full rounded-lg border-slate-300 text-sm">
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">إلى</label>
                        <div class="relative">
                            <input type="text"
                                   name="recipient"
                                   x-model="recipient"
                                   @focus="search"
                                   @input.debounce.250ms="search"
                                   @keydown.escape="closeSuggestions"
                                   @keydown.arrow-down.prevent="focusNext"
                                   @keydown.arrow-up.prevent="focusPrev"
                                   @keydown.enter.prevent="chooseFocused"
                                   placeholder="اكتب بريدًا واحدًا أو عدة بريدات مفصولة بفاصلة"
                                   class="w-full rounded-lg border-slate-300 text-sm">

                            <div x-cloak x-show="open && suggestions.length > 0" class="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg">
                                <template x-for="(contact, index) in suggestions" :key="contact.id">
                                    <button type="button"
                                            @mouseenter="focusedIndex = index"
                                            @click="toggleContact(contact)"
                                            class="w-full px-3 py-2 text-right text-sm transition"
                                            :class="focusedIndex === index ? 'bg-[#E8FAF6] text-[#0f7668]' : 'hover:bg-slate-50 text-slate-700'">
                                        <div class="flex items-center justify-between gap-2">
                                            <div class="font-semibold" x-text="contact.name"></div>
                                            <span x-show="isSelected(contact.id)" class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-700">محدد</span>
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            <span x-text="contact.email"></span>
                                            <span class="font-semibold text-emerald-700"> — ✓ مسجل مسبقًا</span>
                                        </div>
                                    </button>
                                </template>
                            </div>
                        </div>

                        <template x-if="recipient && selectedContactIds.length === 0">
                            <p class="mt-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                البريد غير محفوظ في جهات الاتصال حاليًا.
                                <a href="{{ route('workspace.emails.contacts.index') }}" class="font-semibold text-[#0f7668] hover:underline">إضافة كجهة اتصال</a>
                            </p>
                        </template>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <div class="mb-2 flex items-center justify-between">
                            <h4 class="text-sm font-semibold text-slate-800">إرسال جماعي من جهات الاتصال</h4>
                            <span class="rounded-full bg-white px-2 py-0.5 text-xs font-semibold text-slate-600" x-text="`${selectedContactIds.length} محدد`"></span>
                        </div>
                        <p class="mb-3 text-xs text-slate-600">اختر 1 أو أكثر من جهات الاتصال (مثال: 3 من أصل 5) لإرسال رسالة جماعية.</p>

                        <div class="max-h-40 space-y-2 overflow-auto rounded-lg border border-slate-200 bg-white p-2">
                            @foreach($contacts as $contact)
                                <label class="flex cursor-pointer items-center justify-between gap-2 rounded-md px-2 py-1 hover:bg-slate-50">
                                    <span class="min-w-0">
                                        <span class="block truncate text-xs font-semibold text-slate-800">{{ $contact->name }}</span>
                                        <span class="block truncate text-[11px] text-slate-500">{{ $contact->email }}</span>
                                    </span>
                                    <input type="checkbox"
                                           class="rounded border-slate-300 text-[#06C2A4]"
                                           :checked="isSelected({{ $contact->id }})"
                                           @change="toggleKnownContact({ id: {{ $contact->id }}, name: @js($contact->name), email: @js($contact->email) })">
                                </label>
                            @endforeach
                        </div>

                        <div x-show="selectedContactIds.length > 0" class="mt-3 flex flex-wrap gap-2">
                            <template x-for="contact in selectedContactsList" :key="`chip-${contact.id}`">
                                <span class="inline-flex items-center gap-2 rounded-full bg-emerald-100 px-2 py-1 text-xs font-semibold text-emerald-800">
                                    <span x-text="contact.name"></span>
                                    <button type="button" class="text-emerald-700" @click="removeSelected(contact.id)">×</button>
                                </span>
                            </template>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">العنوان</label>
                        <input type="text" name="subject" value="{{ $draft['subject'] ?? '' }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">نص الرسالة</label>
                        <textarea name="body" rows="12" required class="w-full rounded-lg border-slate-300 text-sm leading-7">{{ $draft['body'] ?? '' }}</textarea>
                        <p class="mt-1 text-xs text-slate-500">ملاحظة: النص يبقى محفوظًا بعد الإرسال حتى تضغط "رسالة جديدة".</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">المرفقات</label>
                        <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-center"
                             @dragover.prevent
                             @drop.prevent="handleDrop($event)">
                            <input x-ref="attachmentsInput" type="file" name="attachments[]" multiple class="hidden" @change="handleFilesChange">
                            <p class="text-xs text-slate-600">اسحب الملفات هنا أو</p>
                            <button type="button" @click="$refs.attachmentsInput.click()" class="mt-2 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                اختر ملفات
                            </button>
                            <p class="mt-2 text-[11px] text-slate-500">حد أقصى 10 ملفات — 10MB لكل ملف.</p>
                        </div>

                        <div x-show="attachments.length > 0" class="mt-3 overflow-hidden rounded-lg border border-slate-200">
                            <ul class="divide-y divide-slate-100">
                                <template x-for="(file, fileIndex) in attachments" :key="`${file.name}-${fileIndex}`">
                                    <li class="flex items-center justify-between px-3 py-2 text-xs">
                                        <div class="min-w-0">
                                            <p class="truncate font-semibold text-slate-800" x-text="file.name"></p>
                                            <p class="text-slate-500" x-text="humanSize(file.size)"></p>
                                        </div>
                                        <button type="button" class="rounded-md border border-red-200 px-2 py-1 font-semibold text-red-600 hover:bg-red-50" @click="removeAttachment(fileIndex)">
                                            إزالة
                                        </button>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </div>

                    <button type="submit" class="rounded-lg bg-[#06C2A4] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[#05ab91]">
                        إرسال الرسالة
                    </button>
                </form>
            @endif
        </section>

        <aside class="space-y-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="text-sm font-bold text-slate-900">إرشادات سريعة</h3>
                <ul class="mt-2 space-y-1 text-xs leading-6 text-slate-600">
                    <li>• اختر الشركة من قائمة "الإرسال من".</li>
                    <li>• النظام يستخدم SMTP الخاص بالحساب المختار تلقائيًا.</li>
                    <li>• عند النجاح ستظهر رسالة: "تم إرسال الرسالة بنجاح".</li>
                    <li>• عند الفشل ستظهر رسالة خطأ توضح السبب.</li>
                </ul>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="text-sm font-bold text-slate-900">إدارة الحسابات</h3>
                <p class="mt-2 text-xs leading-6 text-slate-600">لإضافة أو تعديل بيانات شركات البريد، انتقل إلى صفحة الحسابات.</p>
                <a href="{{ route('workspace.emails.accounts.index') }}" class="mt-3 inline-flex rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                    فتح الشركات / الحسابات
                </a>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="text-sm font-bold text-slate-900">دفتر العناوين</h3>
                <p class="mt-2 text-xs leading-6 text-slate-600">يمكنك اختيار شركة مباشرة من جهات الاتصال بدل كتابة البريد يدويًا.</p>
                <a href="{{ route('workspace.emails.contacts.index') }}" class="mt-3 inline-flex rounded-lg border border-[#06C2A4] px-3 py-2 text-xs font-semibold text-[#0f7668] hover:bg-[#E8FAF6]">
                    فتح جهات الاتصال
                </a>

                @if($contacts->isNotEmpty())
                    <div class="mt-3 space-y-2 border-t border-slate-100 pt-3">
                        @foreach($contacts->take(5) as $contact)
                            <a href="{{ route('workspace.emails.compose', ['recipient' => $contact->email, 'recipient_contact_id' => $contact->id]) }}"
                               class="block rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-700 hover:bg-slate-50">
                                <span class="font-semibold">{{ $contact->name }}</span>
                                <span class="text-slate-500"> — {{ $contact->email }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </aside>
    </div>

    <script>
        function composeForm({ searchUrl, initialRecipient, initialSelectedContactIds, initialSelectedContacts }) {
            const normalizedIds = Array.isArray(initialSelectedContactIds) ? initialSelectedContactIds.map(id => Number(id)).filter(Boolean) : [];
            const contactMap = {};
            (Array.isArray(initialSelectedContacts) ? initialSelectedContacts : []).forEach((contact) => {
                if (!contact || !contact.id) return;
                contactMap[Number(contact.id)] = contact;
            });

            return {
                recipient: initialRecipient || '',
                selectedContactIds: normalizedIds,
                contactsById: contactMap,
                suggestions: [],
                open: false,
                focusedIndex: -1,
                searchRequestToken: 0,
                attachments: [],
                async search() {
                    const query = (this.recipient || '').trim();
                    if (query.length < 1) {
                        this.suggestions = [];
                        this.open = false;
                        return;
                    }

                    this.searchRequestToken += 1;
                    const token = this.searchRequestToken;
                    try {
                        const url = new URL(searchUrl, window.location.origin);
                        url.searchParams.set('q', query);
                        const response = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
                        const data = await response.json();
                        if (token !== this.searchRequestToken) return;
                        this.suggestions = Array.isArray(data.contacts) ? data.contacts : [];
                        this.open = this.suggestions.length > 0;
                        this.focusedIndex = this.suggestions.length > 0 ? 0 : -1;
                    } catch (error) {
                        this.suggestions = [];
                        this.open = false;
                    }
                },
                isSelected(contactId) {
                    return this.selectedContactIds.includes(Number(contactId));
                },
                toggleContact(contact) {
                    const id = Number(contact.id);
                    if (!id) return;
                    this.contactsById[id] = contact;
                    if (this.isSelected(id)) {
                        this.removeSelected(id);
                    } else {
                        this.selectedContactIds.push(id);
                    }
                    this.closeSuggestions();
                },
                toggleKnownContact(contact) {
                    this.toggleContact(contact);
                },
                removeSelected(contactId) {
                    const id = Number(contactId);
                    this.selectedContactIds = this.selectedContactIds.filter((candidateId) => Number(candidateId) !== id);
                },
                get selectedContactsList() {
                    return this.selectedContactIds
                        .map((id) => this.contactsById[Number(id)])
                        .filter(Boolean);
                },
                closeSuggestions() {
                    this.open = false;
                    this.focusedIndex = -1;
                },
                focusNext() {
                    if (!this.open || this.suggestions.length === 0) return;
                    this.focusedIndex = (this.focusedIndex + 1) % this.suggestions.length;
                },
                focusPrev() {
                    if (!this.open || this.suggestions.length === 0) return;
                    this.focusedIndex = (this.focusedIndex - 1 + this.suggestions.length) % this.suggestions.length;
                },
                chooseFocused() {
                    if (!this.open || this.focusedIndex < 0 || !this.suggestions[this.focusedIndex]) return;
                    this.toggleContact(this.suggestions[this.focusedIndex]);
                },
                handleFilesChange(event) {
                    const files = Array.from(event.target.files || []);
                    this.attachments = files;
                },
                handleDrop(event) {
                    if (!this.$refs.attachmentsInput) return;
                    const droppedFiles = Array.from(event.dataTransfer?.files || []);
                    if (!droppedFiles.length) return;
                    const transfer = new DataTransfer();
                    droppedFiles.forEach((file) => transfer.items.add(file));
                    this.$refs.attachmentsInput.files = transfer.files;
                    this.attachments = droppedFiles;
                },
                removeAttachment(fileIndex) {
                    if (!this.$refs.attachmentsInput) return;
                    const currentFiles = Array.from(this.$refs.attachmentsInput.files || []);
                    currentFiles.splice(fileIndex, 1);
                    const transfer = new DataTransfer();
                    currentFiles.forEach((file) => transfer.items.add(file));
                    this.$refs.attachmentsInput.files = transfer.files;
                    this.attachments = currentFiles;
                },
                humanSize(bytes) {
                    if (bytes < 1024) return `${bytes} B`;
                    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
                    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
                },
            };
        }
    </script>
@endsection
