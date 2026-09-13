<?php

namespace App\Services\WhatsApp;

use App\Exceptions\FeatureNotAvailableException;
use App\Jobs\SendWhatsAppMessage;
use App\Models\WhatsAppOutboundMessage;
use App\Models\WhatsAppPhoneNumber;
use App\Models\Workspace;
use App\Services\Feature\FeatureAccessService;

class WhatsAppOutboundService
{
    public function __construct(
        private readonly FeatureAccessService $featureAccess,
    ) {}

    /**
     * Queue a plain-text WhatsApp outbound message.
     *
     * Controllers (and AI reply wiring) should call this rather than dispatching
     * SendWhatsAppMessage directly so entitlements and outbound rows stay consistent.
     *
     * @param  string  $phoneNumberId  Meta Graph phone_number_id (not the local PK)
     */
    public function sendText(
        Workspace $workspace,
        string $phoneNumberId,
        string $to,
        string $body,
        ?int $conversationId = null,
        ?int $messageId = null,
    ): WhatsAppOutboundMessage {
        $this->assertWhatsAppEntitlement($workspace);

        $phone = WhatsAppPhoneNumber::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('phone_number_id', $phoneNumberId)
            ->first();

        if (! $phone) {
            throw new \InvalidArgumentException('WhatsApp phone_number_id is not registered for this workspace.');
        }

        $this->featureAccess->consumeUsage($workspace, 'whatsapp_messages', 1, enforce: true);

        $outbound = WhatsAppOutboundMessage::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'whats_app_phone_number_id' => $phone->id,
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'to' => $to,
            'type' => 'text',
            'body' => $body,
            'status' => WhatsAppOutboundMessage::STATUS_QUEUED,
            'attempts' => 0,
            'payload' => [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => ['body' => $body],
            ],
        ]);

        SendWhatsAppMessage::dispatch(
            phoneNumberId: $phoneNumberId,
            to: $to,
            message: $body,
            outboundMessageId: $outbound->id,
            workspaceId: (int) $workspace->id,
        );

        return $outbound;
    }

    private function assertWhatsAppEntitlement(Workspace $workspace): void
    {
        $user = auth()->user();

        if ($user) {
            $this->featureAccess->assertFeature($user, $workspace, 'whatsapp');

            return;
        }

        if (! $this->featureAccess->workspaceHasFeature($workspace, 'whatsapp')) {
            throw new FeatureNotAvailableException(
                feature: 'whatsapp',
                requiredPlan: $this->featureAccess->suggestedPlanForFeature('whatsapp'),
                message: __('ميزة واتساب غير متاحة في باقتك الحالية.'),
            );
        }
    }
}
