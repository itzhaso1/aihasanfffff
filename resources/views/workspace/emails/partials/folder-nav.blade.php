<nav class="space-y-2 px-4 py-4">
    <a href="{{ route('workspace.emails.inbox', array_filter(['account_id' => request('account_id')])) }}"
       class="{{ request()->routeIs('workspace.emails.inbox') || (request()->routeIs('workspace.emails.messages.show') && request('return_to', 'inbox') === 'inbox') ? 'bg-[#06C2A4] text-white' : 'text-slate-700 hover:bg-slate-100' }} block rounded-lg px-3 py-2 text-sm font-semibold transition">
        الوارد
    </a>
    <a href="{{ route('workspace.emails.sent', array_filter(['account_id' => request('account_id')])) }}"
       class="{{ request()->routeIs('workspace.emails.sent') || (request()->routeIs('workspace.emails.messages.show') && request('return_to') === 'sent') ? 'bg-[#06C2A4] text-white' : 'text-slate-700 hover:bg-slate-100' }} block rounded-lg px-3 py-2 text-sm font-semibold transition">
        الصادر
    </a>
    <a href="{{ route('workspace.emails.compose', array_filter(['account_id' => request('account_id')])) }}"
       class="{{ request()->routeIs('workspace.emails.compose') ? 'bg-[#06C2A4] text-white' : 'text-slate-700 hover:bg-slate-100' }} block rounded-lg px-3 py-2 text-sm font-semibold transition">
        كتابة رسالة
    </a>
    <a href="{{ route('workspace.emails.templates.index') }}"
       class="{{ request()->routeIs('workspace.emails.templates.*') ? 'bg-[#06C2A4] text-white' : 'text-slate-700 hover:bg-slate-100' }} block rounded-lg px-3 py-2 text-sm font-semibold transition">
        قوالب البريد
    </a>
    <a href="{{ route('workspace.emails.branding.edit') }}"
       class="{{ request()->routeIs('workspace.emails.branding.*') ? 'bg-[#06C2A4] text-white' : 'text-slate-700 hover:bg-slate-100' }} block rounded-lg px-3 py-2 text-sm font-semibold transition">
        هوية البريد الإلكتروني
    </a>
    <a href="{{ route('workspace.emails.accounts.index', array_filter(['account_id' => request('account_id')])) }}"
       class="{{ request()->routeIs('workspace.emails.accounts.*') ? 'bg-[#06C2A4] text-white' : 'text-slate-700 hover:bg-slate-100' }} block rounded-lg px-3 py-2 text-sm font-semibold transition">
        الشركات / حسابات البريد
    </a>
    <a href="{{ route('workspace.emails.contacts.index') }}"
       class="{{ request()->routeIs('workspace.emails.contacts.*') ? 'bg-[#06C2A4] text-white' : 'text-slate-700 hover:bg-slate-100' }} block rounded-lg px-3 py-2 text-sm font-semibold transition">
        جهات الاتصال
    </a>
    <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">إعدادات البريد ضمن صفحة الحسابات.</p>
</nav>
