<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-right font-semibold text-slate-600">{{ $type === 'inbound' ? 'المرسل' : 'المستلم' }}</th>
                    <th class="px-4 py-3 text-right font-semibold text-slate-600">العنوان</th>
                    <th class="px-4 py-3 text-right font-semibold text-slate-600">التاريخ والوقت</th>
                    <th class="px-4 py-3 text-right font-semibold text-slate-600">الحالة</th>
                    <th class="px-4 py-3 text-left font-semibold text-slate-600">إجراءات</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($messages as $message)
                    @php
                        $status = $message->delivery_status ?? ($type === 'inbound' ? 'received' : 'sent');
                        $statusLabel = match ($status) {
                            'sending' => 'جارٍ الإرسال',
                            'failed' => 'فشل',
                            'received' => 'واردة',
                            default => 'تم الإرسال',
                        };
                        $statusClass = match ($status) {
                            'sending' => 'bg-amber-50 text-amber-700 border-amber-200',
                            'failed' => 'bg-red-50 text-red-700 border-red-200',
                            default => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                        };
                    @endphp
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 text-slate-700">{{ $type === 'inbound' ? $message->sender : $message->recipient }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('workspace.emails.messages.show', array_filter([
                                'emailMessage' => $message,
                                'account_id' => $currentAccount?->id,
                                'search' => $search ?: null,
                                'return_to' => $type === 'inbound' ? 'inbox' : 'sent',
                            ])) }}" class="font-semibold text-slate-800 hover:text-[#06C2A4]">
                                {{ $message->subject ?: '(بدون عنوان)' }}
                            </a>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $message->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold {{ $statusClass }}">
                                {{ $statusLabel }}
                            </span>
                            @if($status === 'failed' && $message->delivery_error)
                                <p class="mt-1 max-w-xs truncate text-[11px] text-red-600" title="{{ $message->delivery_error }}">{{ $message->delivery_error }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <a href="{{ route('workspace.emails.messages.show', array_filter([
                                    'emailMessage' => $message,
                                    'account_id' => $currentAccount?->id,
                                    'search' => $search ?: null,
                                    'return_to' => $type === 'inbound' ? 'inbox' : 'sent',
                                ])) }}" class="rounded-md border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                    فتح
                                </a>
                                <form method="POST" action="{{ route('workspace.emails.messages.destroy', $message) }}" onsubmit="return confirm('هل تريد حذف الرسالة مع مرفقاتها؟');">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="account_id" value="{{ $currentAccount?->id }}">
                                    <input type="hidden" name="search" value="{{ $search }}">
                                    <input type="hidden" name="return_to" value="{{ $type === 'inbound' ? 'inbox' : 'sent' }}">
                                    <button type="submit" class="rounded-md border border-red-200 px-2 py-1 text-xs font-semibold text-red-600 hover:bg-red-50">
                                        حذف
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-slate-500">
                            لا توجد رسائل في هذا القسم حالياً.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="border-t border-slate-100 px-4 py-3">
        {{ $messages->links() }}
    </div>
</div>
