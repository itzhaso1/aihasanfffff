<?php

namespace App\Services\Channel;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceTelegramConnection;
use App\Services\Meta\Facebook\FacebookConnectService;
use App\Services\Meta\Instagram\InstagramConnectService;
use App\Services\Telegram\TelegramConnectService;
use App\Services\TikTok\TikTokConnectService;
use App\Services\WhatsApp\WhatsAppEmbeddedSignupService;
use App\Support\ChannelConnect\ChannelConnectAttemptStore;
use App\Support\Meta\MetaOAuthState;
use RuntimeException;

class ChannelConnectionService
{
    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_FACEBOOK = 'facebook_messenger';

    public const CHANNEL_INSTAGRAM = 'instagram';

    public const CHANNEL_TIKTOK = 'tiktok';

    public const CHANNEL_TELEGRAM = 'telegram';

    public function __construct(
        private readonly FacebookConnectService $facebookConnectService,
        private readonly InstagramConnectService $instagramConnectService,
        private readonly TikTokConnectService $tikTokConnectService,
        private readonly TelegramConnectService $telegramConnectService,
        private readonly WhatsAppEmbeddedSignupService $whatsAppEmbeddedSignupService,
        private readonly ChannelConnectAttemptStore $attemptStore,
    ) {}

    /**
     * @return list<string>
     */
    public static function nativeChannels(): array
    {
        return [
            self::CHANNEL_WHATSAPP,
            self::CHANNEL_FACEBOOK,
            self::CHANNEL_INSTAGRAM,
            self::CHANNEL_TIKTOK,
            self::CHANNEL_TELEGRAM,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function start(Workspace $workspace, User $user, string $channel): array
    {
        return match ($channel) {
            self::CHANNEL_WHATSAPP => $this->startWhatsApp($workspace, $user),
            self::CHANNEL_FACEBOOK => $this->startOAuth(
                $channel,
                'oauth',
                $this->facebookConnectService->beginAuthorizationUrl($workspace, $user, MetaOAuthState::RETURN_MOBILE),
            ),
            self::CHANNEL_INSTAGRAM => $this->startOAuth(
                $channel,
                'oauth',
                $this->instagramConnectService->beginAuthorizationUrl($workspace, $user, MetaOAuthState::RETURN_MOBILE),
            ),
            self::CHANNEL_TIKTOK => $this->startOAuth(
                $channel,
                'oauth',
                $this->tikTokConnectService->beginAuthorizationUrl($workspace, $user, MetaOAuthState::RETURN_MOBILE),
            ),
            self::CHANNEL_TELEGRAM => throw new RuntimeException('Telegram requires a bot token. POST bot_token to connect.'),
            default => throw new RuntimeException('Unsupported channel.'),
        };
    }

    public function connectTelegram(Workspace $workspace, User $user, string $botToken): WorkspaceTelegramConnection
    {
        return $this->telegramConnectService->connectBotToken($workspace, $user, $botToken);
    }

    public function disconnect(Workspace $workspace, string $channel): void
    {
        match ($channel) {
            self::CHANNEL_WHATSAPP => $this->whatsAppEmbeddedSignupService->disconnectWorkspace($workspace),
            self::CHANNEL_FACEBOOK => $this->facebookConnectService->disconnect($workspace),
            self::CHANNEL_INSTAGRAM => $this->instagramConnectService->disconnect($workspace),
            self::CHANNEL_TIKTOK => $this->tikTokConnectService->disconnect($workspace),
            self::CHANNEL_TELEGRAM => $this->telegramConnectService->disconnect($workspace),
            default => throw new RuntimeException('Unsupported channel.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function startWhatsApp(Workspace $workspace, User $user): array
    {
        if (! filled(config('whatsapp.meta_app_id')) || ! filled(config('whatsapp.embedded_signup_config_id'))) {
            throw new RuntimeException('WhatsApp Embedded Signup is not configured.');
        }

        $token = $this->attemptStore->issue('whatsapp_signup', [
            'workspace_id' => (int) $workspace->id,
            'user_id' => (int) $user->id,
        ]);

        return [
            'channel' => self::CHANNEL_WHATSAPP,
            'mode' => 'embedded_signup',
            'authorization_url' => route('mobile.channels.whatsapp.signup', ['token' => $token], true),
            'callback_scheme' => (string) config('channel_connect.mobile_scheme', 'hasim'),
            'expires_at' => now()->addMinutes((int) config('channel_connect.attempt_ttl_minutes', 15))->toISOString(),
        ];
    }

    /**
     * @param  array{authorization_url: string, state: string}  $begin
     * @return array<string, mixed>
     */
    private function startOAuth(string $channel, string $mode, array $begin): array
    {
        return [
            'channel' => $channel,
            'mode' => $mode,
            'authorization_url' => $begin['authorization_url'],
            'callback_scheme' => (string) config('channel_connect.mobile_scheme', 'hasim'),
            'expires_at' => now()->addMinutes(15)->toISOString(),
        ];
    }
}
