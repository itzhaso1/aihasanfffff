<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Models\EmailAccount;
use App\Models\WhatsAppAccount;
use App\Services\WhatsApp\WhatsAppEmbeddedSignupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class ChannelController extends Controller
{
    use InteractsWithWorkspace;

    public function index(): View
    {
        $workspace = $this->currentWorkspace();
        $latestWhatsAppAccount = WhatsAppAccount::query()
            ->with('phoneNumbers')
            ->latest('id')
            ->first();

        $whatsAppConnected = $latestWhatsAppAccount?->status === 'connected'
            && $latestWhatsAppAccount->phoneNumbers->isNotEmpty();

        $emailConnected = EmailAccount::query()->exists();

        $workspaceChannelSettings = is_array($workspace->settings['channels'] ?? null)
            ? $workspace->settings['channels']
            : [];

        $channels = [
            [
                'key' => 'whatsapp',
                'name' => 'WhatsApp',
                'icon' => 'whatsapp',
                'connected' => $whatsAppConnected,
                'status_text' => $whatsAppConnected ? 'Connected' : 'Not Connected',
                'hint' => $whatsAppConnected
                    ? (($latestWhatsAppAccount->display_name ?? 'Connected account').' · '.$latestWhatsAppAccount->phoneNumbers->count().' number(s)')
                    : 'Connect via Meta Embedded Signup',
                'primary_action' => $whatsAppConnected ? 'Reconnect WhatsApp' : 'Connect WhatsApp',
                'manage_url' => route('workspace.whatsapp-accounts.index'),
            ],
            [
                'key' => 'facebook_messenger',
                'name' => 'Facebook Messenger',
                'icon' => 'messenger',
                'connected' => (bool) ($workspaceChannelSettings['facebook_messenger']['connected'] ?? false),
                'status_text' => (bool) ($workspaceChannelSettings['facebook_messenger']['connected'] ?? false) ? 'Connected' : 'Not Connected',
                'hint' => 'Connection settings are ready for future integration.',
                'primary_action' => 'Manage channel',
                'manage_url' => route('workspace.conversations.index', ['channel' => 'facebook_messenger']),
            ],
            [
                'key' => 'instagram',
                'name' => 'Instagram',
                'icon' => 'instagram',
                'connected' => (bool) ($workspaceChannelSettings['instagram']['connected'] ?? false),
                'status_text' => (bool) ($workspaceChannelSettings['instagram']['connected'] ?? false) ? 'Connected' : 'Not Connected',
                'hint' => 'Connection settings are ready for future integration.',
                'primary_action' => 'Manage channel',
                'manage_url' => route('workspace.conversations.index', ['channel' => 'instagram']),
            ],
            [
                'key' => 'email',
                'name' => 'Email',
                'icon' => 'email',
                'connected' => $emailConnected,
                'status_text' => $emailConnected ? 'Connected' : 'Not Connected',
                'hint' => $emailConnected ? 'Email account configured and ready.' : 'No email account configured yet.',
                'primary_action' => $emailConnected ? 'Manage Email' : 'Connect Email',
                'manage_url' => route('workspace.emails.accounts.index'),
            ],
        ];

        return view('workspace.channels.index', [
            'channels' => $channels,
            'metaAppId' => (string) config('whatsapp.meta_app_id'),
            'metaConfigId' => (string) config('whatsapp.embedded_signup_config_id'),
            'graphApiVersion' => (string) config('whatsapp.api_version'),
        ]);
    }

    public function connectWhatsApp(Request $request, WhatsAppEmbeddedSignupService $embeddedSignupService): JsonResponse
    {
        $workspace = $this->currentWorkspace();
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:1024'],
            'session_info' => ['nullable', 'array'],
            'session_info.waba_id' => ['nullable', 'string', 'max:191'],
            'session_info.phone_number_id' => ['nullable', 'string', 'max:191'],
            'session_info.business_id' => ['nullable', 'string', 'max:191'],
        ]);

        try {
            $result = $embeddedSignupService->connectWorkspace($workspace, $validated);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'WhatsApp connected successfully.',
            'account' => [
                'id' => $result['account']->id,
                'business_account_id' => $result['account']->business_account_id,
                'display_name' => $result['account']->display_name,
                'status' => $result['account']->status,
            ],
            'phone_numbers' => collect($result['account']->phoneNumbers)->map(fn ($phone) => [
                'id' => $phone->id,
                'phone_number_id' => $phone->phone_number_id,
                'display_phone_number' => $phone->display_phone_number,
                'status' => $phone->status,
            ])->values(),
        ]);
    }
}
