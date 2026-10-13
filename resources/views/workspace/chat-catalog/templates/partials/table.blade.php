<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-slate-600">
            <tr>
                <th class="px-4 py-3 text-start">الاسم</th>
                <th class="px-4 py-3 text-start">Slug</th>
                <th class="px-4 py-3 text-start">النوع</th>
                <th class="px-4 py-3 text-start">الإصدار</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $template)
                <tr class="border-t border-slate-100">
                    <td class="px-4 py-3 font-medium">{{ $template->name }}</td>
                    <td class="px-4 py-3 text-slate-500">{{ $template->slug }}</td>
                    <td class="px-4 py-3">{{ $template->is_system ? 'نظام' : 'مخصص' }}</td>
                    <td class="px-4 py-3">v{{ $template->schema_version }}</td>
                    <td class="px-4 py-3 text-end">
                        <a href="{{ route('workspace.chat-catalog.templates.show', $template) }}" class="text-blue-600 hover:underline">عرض</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">لا توجد قوالب.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
