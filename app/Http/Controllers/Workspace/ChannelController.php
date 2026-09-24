<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Models\EmailAccount;
use App\Models\WhatsAppAccount;
use App\Models\WorkspaceMetaConnection;
use App\Models\WorkspaceTelegramConnection;
use App\Models\WorkspaceTikTokConnection;
use App\Services\WhatsApp\WhatsAppEmbeddedSignupService;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Meta\MetaConnectionKind;
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
        $user = request()->user();
        $canManageChannels = $user && app(WorkspaceAccess::class)->canManageChannels($user, $workspace);
        $canUseEmail = $user && app(WorkspaceAccess::class)->canUseEmail($user, $workspace);

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

        $instagramConnected = WorkspaceMetaConnection::query()
            ->where('kind', MetaConnectionKind::INSTAGRAM_USER)
            ->where('status', 'connected')
            ->exists();

        $facebookConnection = WorkspaceMetaConnection::query()
            ->where('kind', MetaConnectionKind::FACEBOOK_PAGE)
            ->where('status', 'connected')
            ->latest('id')
            ->first();

        $facebookConnected = $facebookConnection !== null;

        $tikTokConnection = WorkspaceTikTokConnection::query()
            ->where('status', WorkspaceTikTokConnection::STATUS_CONNECTED)
            ->latest('id')
            ->first();

        $telegramConnection = WorkspaceTelegramConnection::query()
            ->where('status', WorkspaceTelegramConnection::STATUS_CONNECTED)
            ->latest('id')
            ->first();

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
                'connected' => $facebookConnected,
                'status_text' => $facebookConnected ? 'Connected' : 'Not Connected',
                'hint' => $facebookConnected
                    ? ($facebookConnection->display_name ?? 'Facebook Page connected.')
                    : 'Connect a Facebook Page to receive Messenger conversations.',
                'primary_action' => $facebookConnected ? 'Reconnect Facebook' : 'Connect Facebook',
                'connect_url' => route('workspace.channels.facebook.connect'),
                'disconnect_url' => route('workspace.channels.facebook.disconnect'),
                'manage_url' => route('workspace.conversations.index', ['channel' => 'facebook_messenger']),
            ],
            [
                'key' => 'instagram',
                'name' => 'Instagram',
                'icon' => 'instagram',
                'connected' => $instagramConnected,
                'status_text' => $instagramConnected ? 'Connected' : 'Not Connected',
                'hint' => $instagramConnected
                    ? 'Instagram Business account connected.'
                    : 'Connect via Meta Business Login.',
                'primary_action' => $instagramConnected ? 'Reconnect Instagram' : 'Connect Instagram',
                'connect_url' => route('workspace.channels.instagram.connect'),
                'manage_url' => route('workspace.conversations.index', ['channel' => 'instagram']),
            ],
            [
                'key' => 'tiktok',
                'name' => 'TikTok',
                'icon' => 'tiktok',
                'connected' => $tikTokConnection !== null,
                'status_text' => $tikTokConnection ? 'Connected' : 'Not Connected',
                'hint' => $tikTokConnection
                    ? ($tikTokConnection->display_name ?? 'TikTok Business connected.')
                    : 'Connect via TikTok Business Messaging OAuth.',
                'primary_action' => $tikTokConnection ? 'Reconnect TikTok' : 'Connect TikTok',
                'connect_url' => route('workspace.channels.tiktok.connect'),
                'manage_url' => route('workspace.conversations.index', ['channel' => 'tiktok']),
            ],
            [
                'key' => 'telegram',
                'name' => 'Telegram',
                'icon' => 'telegram',
                'connected' => $telegramConnection !== null,
                'status_text' => $telegramConnection ? 'Connected' : 'Not Connected',
                'hint' => $telegramConnection
                    ? ('Bot '.($telegramConnection->bot_username ? '@'.$telegramConnection->bot_username : $telegramConnection->bot_user_id))
                    : 'Connect a Telegram bot token (Bot API).',
                'primary_action' => $telegramConnection ? 'Manage Telegram' : 'Connect Telegram',
                'manage_url' => route('workspace.conversations.index', ['channel' => 'telegram']),
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
            'canManageChannels' => $canManageChannels,
            'canUseEmail' => $canUseEmail,
            'metaAppId' => (string) config('whatsapp.meta_app_id'),
            'metaConfigId' => (string) config('whatsapp.embedded_signup_config_id'),
            'graphApiVersion' => (string) config('whatsapp.api_version'),
        ]);
    }

    public function connectWhatsApp(Request $request, WhatsAppEmbeddedSignupService $embeddedSignupService): JsonResponse
    {
        $user = $request->user();
        $workspace = $this->currentWorkspace();
        abort_unless(
            $user && app(WorkspaceAccess::class)->canManageWhatsAppChannels($user, $workspace),
            403
        );
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
