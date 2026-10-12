<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Models\EmailAccount;
use App\Models\WhatsAppAccount;
use App\Models\WorkspaceMetaConnection;
use App\Models\WorkspaceTelegramConnection;
use App\Models\WorkspaceTikTokConnection;
use App\Services\Channel\ChannelConnectionService;
use App\Services\Feature\FeatureAccessService;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Meta\MetaConnectionKind;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;

class ChannelController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly WorkspaceAccess $workspaceAccess,
        private readonly FeatureAccessService $featureAccessService,
    ) {}

    public function index(): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = request()->user();
        $canManage = $user && $this->workspaceAccess->canManageChannels($user, $workspace);
        $featureEnabled = $user && $this->featureAccessService->hasFeature($user, $workspace, 'whatsapp');
        $canConnectNative = $canManage && $featureEnabled;

        $latestWhatsAppAccount = WhatsAppAccount::query()
            ->with('phoneNumbers')
            ->latest('id')
            ->first();

        $whatsAppConnected = $latestWhatsAppAccount?->status === 'connected'
            && $latestWhatsAppAccount->phoneNumbers->isNotEmpty();

        $emailConnected = EmailAccount::query()->exists();

        $facebookConnection = WorkspaceMetaConnection::query()
            ->where('kind', MetaConnectionKind::FACEBOOK_PAGE)
            ->where('status', 'connected')
            ->latest('id')
            ->first();

        $instagramConnection = WorkspaceMetaConnection::query()
            ->where('kind', MetaConnectionKind::INSTAGRAM_USER)
            ->where('status', 'connected')
            ->latest('id')
            ->first();

        $tikTokConnection = WorkspaceTikTokConnection::query()
            ->where('status', WorkspaceTikTokConnection::STATUS_CONNECTED)
            ->latest('id')
            ->first();

        $telegramConnection = WorkspaceTelegramConnection::query()
            ->where('status', WorkspaceTelegramConnection::STATUS_CONNECTED)
            ->latest('id')
            ->first();

        $embeddedSignupReady = filled(config('whatsapp.meta_app_id'))
            && filled(config('whatsapp.embedded_signup_config_id'));
        $facebookReady = filled(config('meta.app_id')) && $this->facebookScopesConfigured();
        $instagramReady = filled(config('meta.app_id')) && $this->instagramScopesConfigured();
        $tikTokReady = filled(config('tiktok.client_key'))
            && filled(config('tiktok.redirect_uri'))
            && is_array(config('tiktok.oauth_scopes'))
            && config('tiktok.oauth_scopes') !== [];

        $channels = [
            $this->channelPayload(
                key: ChannelConnectionService::CHANNEL_WHATSAPP,
                name: 'WhatsApp',
                icon: 'whatsapp',
                connected: $whatsAppConnected,
                status: $whatsAppConnected
                    ? 'connected'
                    : ($latestWhatsAppAccount ? 'needs_setup' : 'disconnected'),
                hint: $whatsAppConnected
                    ? (($latestWhatsAppAccount->display_name ?? 'حساب متصل').' · '.$latestWhatsAppAccount->phoneNumbers->count().' رقم')
                    : 'اربط عبر Meta Embedded Signup',
                manageUrl: route('workspace.whatsapp-accounts.index'),
                canConnectInApp: $canConnectNative && $embeddedSignupReady,
                canManage: $canManage,
                canDisconnect: $canConnectNative && $whatsAppConnected,
                connectionMode: 'embedded_signup',
                displayName: $latestWhatsAppAccount?->display_name,
                phoneNumber: $latestWhatsAppAccount?->phoneNumbers->first()?->display_phone_number,
            ),
            $this->channelPayload(
                key: ChannelConnectionService::CHANNEL_FACEBOOK,
                name: 'Facebook Messenger',
                icon: 'messenger',
                connected: $facebookConnection !== null,
                status: $facebookConnection ? 'connected' : 'disconnected',
                hint: $facebookConnection
                    ? ($facebookConnection->display_name ?? 'صفحة Facebook متصلة.')
                    : 'اربط صفحة Facebook لاستقبال Messenger.',
                manageUrl: route('workspace.conversations.index', ['channel' => 'facebook_messenger']),
                canConnectInApp: $canConnectNative && $facebookReady,
                canManage: $canManage,
                canDisconnect: $canConnectNative && $facebookConnection !== null,
                connectionMode: 'oauth',
                displayName: $facebookConnection?->display_name,
                phoneNumber: null,
            ),
            $this->channelPayload(
                key: ChannelConnectionService::CHANNEL_INSTAGRAM,
                name: 'Instagram',
                icon: 'instagram',
                connected: $instagramConnection !== null,
                status: $instagramConnection ? 'connected' : 'disconnected',
                hint: $instagramConnection
                    ? ($instagramConnection->display_name ?? 'حساب Instagram متصل.')
                    : 'اربط عبر Meta Business Login.',
                manageUrl: route('workspace.conversations.index', ['channel' => 'instagram']),
                canConnectInApp: $canConnectNative && $instagramReady,
                canManage: $canManage,
                canDisconnect: $canConnectNative && $instagramConnection !== null,
                connectionMode: 'oauth',
                displayName: $instagramConnection?->display_name,
                phoneNumber: null,
            ),
            $this->channelPayload(
                key: ChannelConnectionService::CHANNEL_TIKTOK,
                name: 'TikTok',
                icon: 'tiktok',
                connected: $tikTokConnection !== null,
                status: $tikTokConnection ? 'connected' : 'disconnected',
                hint: $tikTokConnection
                    ? ($tikTokConnection->display_name ?? 'حساب TikTok متصل.')
                    : 'اربط عبر TikTok Business OAuth.',
                manageUrl: route('workspace.conversations.index', ['channel' => 'tiktok']),
                canConnectInApp: $canConnectNative && $tikTokReady,
                canManage: $canManage,
                canDisconnect: $canConnectNative && $tikTokConnection !== null,
                connectionMode: 'oauth',
                displayName: $tikTokConnection?->display_name,
                phoneNumber: null,
            ),
            $this->channelPayload(
                key: ChannelConnectionService::CHANNEL_TELEGRAM,
                name: 'Telegram',
                icon: 'telegram',
                connected: $telegramConnection !== null,
                status: $telegramConnection ? 'connected' : 'disconnected',
                hint: $telegramConnection
                    ? ('Bot '.($telegramConnection->bot_username ? '@'.$telegramConnection->bot_username : $telegramConnection->bot_user_id))
                    : 'أدخل رمز البوت من BotFather.',
                manageUrl: route('workspace.conversations.index', ['channel' => 'telegram']),
                canConnectInApp: $canConnectNative,
                canManage: $canManage,
                canDisconnect: $canConnectNative && $telegramConnection !== null,
                connectionMode: 'bot_token',
                displayName: $telegramConnection?->bot_username ? '@'.$telegramConnection->bot_username : $telegramConnection?->bot_user_id,
                phoneNumber: null,
            ),
            $this->channelPayload(
                key: 'email',
                name: 'Email',
                icon: 'email',
                connected: $emailConnected,
                status: $emailConnected ? 'connected' : 'disconnected',
                hint: $emailConnected ? 'حساب البريد مُعد وجاهز.' : 'لم يتم إعداد حساب بريد بعد.',
                manageUrl: route('workspace.emails.accounts.index'),
                canConnectInApp: false,
                canManage: $canManage,
                canDisconnect: false,
                connectionMode: 'web_only',
                displayName: null,
                phoneNumber: null,
            ),
        ];

        return $this->ok($channels);
    }

    /**
     * @return array<string, mixed>
     */
    private function channelPayload(
        string $key,
        string $name,
        string $icon,
        bool $connected,
        string $status,
        string $hint,
        string $manageUrl,
        bool $canConnectInApp,
        bool $canManage,
        bool $canDisconnect,
        string $connectionMode,
        ?string $displayName,
        ?string $phoneNumber,
    ): array {
        return [
            'key' => $key,
            'name' => $name,
            'icon' => $icon,
            'connected' => $connected,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'hint' => $hint,
            'manage_url' => $manageUrl,
            'can_connect_in_app' => $canConnectInApp,
            'can_manage' => $canManage,
            'can_disconnect' => $canDisconnect,
            'connection_mode' => $connectionMode,
            'display_name' => $displayName,
            'phone_number' => $phoneNumber,
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'connected' => 'متصل',
            'needs_setup' => 'يحتاج إعداد',
            default => 'غير متصل',
        };
    }

    private function facebookScopesConfigured(): bool
    {
        $raw = config('meta.facebook_oauth_scopes');
        if (is_array($raw)) {
            return array_filter(array_map('strval', $raw)) !== [];
        }

        return trim((string) $raw) !== '';
    }

    private function instagramScopesConfigured(): bool
    {
        $raw = config('meta.instagram_oauth_scopes');
        if (is_array($raw)) {
            return array_filter(array_map('strval', $raw)) !== [];
        }

        return trim((string) $raw) !== '';
    }
}
