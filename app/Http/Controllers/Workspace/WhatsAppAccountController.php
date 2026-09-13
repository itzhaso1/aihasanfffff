<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Models\WhatsAppAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WhatsAppAccountController extends Controller
{
    use InteractsWithWorkspace;

    public function index(): View
    {
        $accounts = WhatsAppAccount::query()
            ->with('phoneNumbers')
            ->latest('id')
            ->paginate(12);

        return view('workspace.whatsapp-accounts.index', compact('accounts'));
    }

    public function create(): View
    {
        return view('workspace.whatsapp-accounts.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'business_account_id' => ['required', 'string', 'max:191'],
            'app_id' => ['nullable', 'string', 'max:191'],
            'display_name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:connected,pending,disconnected,error'],
            'metadata_json' => ['nullable', 'string'],
        ]);

        $account = WhatsAppAccount::query()->create([
            'business_account_id' => $validated['business_account_id'],
            'app_id' => $validated['app_id'] ?? null,
            'display_name' => $validated['display_name'],
            'status' => $validated['status'],
            'metadata' => $this->parseJsonField($request, 'metadata_json'),
        ]);

        $this->syncPhoneNumbers($request, $account);

        return redirect()->route('workspace.whatsapp-accounts.index')->with('success', 'تم حفظ حساب واتساب.');
    }

    public function edit(WhatsAppAccount $whatsapp_account): View
    {
        return view('workspace.whatsapp-accounts.edit', [
            'account' => $whatsapp_account->load('phoneNumbers'),
            'metadataJson' => json_encode($whatsapp_account->metadata ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'phoneNumbersJson' => json_encode($whatsapp_account->phoneNumbers->map(fn ($phone) => [
                'id' => $phone->id,
                'phone_number_id' => $phone->phone_number_id,
                'display_phone_number' => $phone->display_phone_number,
                'verified_name' => $phone->verified_name,
                'status' => $phone->status,
            ])->values()->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function update(Request $request, WhatsAppAccount $whatsapp_account): RedirectResponse
    {
        $validated = $request->validate([
            'business_account_id' => ['required', 'string', 'max:191'],
            'app_id' => ['nullable', 'string', 'max:191'],
            'display_name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:connected,pending,disconnected,error'],
            'metadata_json' => ['nullable', 'string'],
        ]);

        $whatsapp_account->update([
            'business_account_id' => $validated['business_account_id'],
            'app_id' => $validated['app_id'] ?? null,
            'display_name' => $validated['display_name'],
            'status' => $validated['status'],
            'metadata' => $this->parseJsonField($request, 'metadata_json', $whatsapp_account->metadata ?? []),
        ]);

        $this->syncPhoneNumbers($request, $whatsapp_account);

        return redirect()->route('workspace.whatsapp-accounts.index')->with('success', 'تم تحديث حساب واتساب.');
    }

    public function destroy(WhatsAppAccount $whatsapp_account): RedirectResponse
    {
        $whatsapp_account->phoneNumbers()->delete();
        $whatsapp_account->delete();

        return redirect()->route('workspace.whatsapp-accounts.index')->with('success', 'تم حذف الحساب.');
    }

    private function syncPhoneNumbers(Request $request, WhatsAppAccount $account): void
    {
        $entries = $this->parseJsonField($request, 'phone_numbers_json');
        $existingIds = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || empty($entry['phone_number_id'])) {
                continue;
            }

            $phone = $account->phoneNumbers()->updateOrCreate(
                ['phone_number_id' => $entry['phone_number_id']],
                [
                    'display_phone_number' => $entry['display_phone_number'] ?? null,
                    'verified_name' => $entry['verified_name'] ?? null,
                    'status' => $entry['status'] ?? 'pending',
                ]
            );
            $existingIds[] = $phone->id;
        }

        if ($existingIds !== []) {
            $account->phoneNumbers()->whereNotIn('id', $existingIds)->delete();
        }
    }
}
