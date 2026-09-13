<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Models\EmailAccount;
use App\Models\WhatsAppAccount;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;

class ChannelController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function index(): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);

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

        $embeddedSignupReady = filled(config('whatsapp.meta_app_id'))
            && filled(config('whatsapp.embedded_signup_config_id'));

        $facebookConnected = (bool) ($workspaceChannelSettings['facebook_messenger']['connected'] ?? false);
        $instagramConnected = (bool) ($workspaceChannelSettings['instagram']['connected'] ?? false);

        $channels = [
            $this->channelPayload(
                key: 'whatsapp',
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
                canConnectInApp: $embeddedSignupReady,
            ),
            $this->channelPayload(
                key: 'facebook_messenger',
                name: 'Facebook Messenger',
                icon: 'messenger',
                connected: $facebookConnected,
                status: $facebookConnected ? 'connected' : 'needs_setup',
                hint: 'إعدادات الربط جاهزة للتكامل المستقبلي.',
                manageUrl: route('workspace.conversations.index', ['channel' => 'facebook_messenger']),
                canConnectInApp: false,
            ),
            $this->channelPayload(
                key: 'instagram',
                name: 'Instagram',
                icon: 'instagram',
                connected: $instagramConnected,
                status: $instagramConnected ? 'connected' : 'needs_setup',
                hint: 'إعدادات الربط جاهزة للتكامل المستقبلي.',
                manageUrl: route('workspace.conversations.index', ['channel' => 'instagram']),
                canConnectInApp: false,
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
}
