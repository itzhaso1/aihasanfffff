<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppPhoneNumber;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WhatsAppController extends Controller
{
    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly WorkspaceAccess $workspaceAccess,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeWhatsApp($request);

        $accounts = WhatsAppAccount::query()
            ->with('phoneNumbers')
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => $accounts]);
    }

    public function storeAccount(Request $request): JsonResponse
    {
        $this->authorizeWhatsApp($request);

        $validated = $request->validate([
            'business_account_id' => ['required', 'string', 'max:255'],
            'app_id' => ['nullable', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:pending,connected,disconnected,error'],
            'metadata' => ['nullable', 'array'],
        ]);

        $account = WhatsAppAccount::query()->create($validated);

        return response()->json(['data' => $account], 201);
    }

    public function storePhoneNumber(Request $request): JsonResponse
    {
        $this->authorizeWhatsApp($request);
        $workspace = $this->workspaceContext->workspace();
        abort_unless($workspace, 422, 'Workspace not resolved.');

        $validated = $request->validate([
            'whats_app_account_id' => [
                'required',
                'integer',
                Rule::exists('whats_app_accounts', 'id')->where(
                    fn ($query) => $query->where('workspace_id', $workspace->id)
                ),
            ],
            'phone_number_id' => ['required', 'string', 'max:255'],
            'display_phone_number' => ['required', 'string', 'max:255'],
            'verified_name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:connected,pending,disconnected'],
        ]);

        $phone = WhatsAppPhoneNumber::query()->create($validated);

        return response()->json(['data' => $phone], 201);
    }

    private function authorizeWhatsApp(Request $request): void
    {
        $user = $request->user();
        $workspace = $this->workspaceContext->workspace();
        abort_unless($user && $workspace, 403);
        abort_unless(
            $this->workspaceAccess->hasAnyMembershipRole($user, $workspace, [
                WorkspaceAccess::ROLE_OWNER,
                WorkspaceAccess::ROLE_ADMIN,
                WorkspaceAccess::ROLE_MANAGER,
            ]) || $user->can('whatsapp.manage'),
            403
        );
    }
}
