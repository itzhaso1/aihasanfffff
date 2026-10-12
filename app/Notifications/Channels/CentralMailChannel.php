<?php

namespace App\Notifications\Channels;

use App\Contracts\Email\CentralEmailNotification;
use App\Services\Email\CentralEmailService;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class CentralMailChannel
{
    public function __construct(
        private readonly CentralEmailService $centralEmailService,
    ) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notification instanceof CentralEmailNotification) {
            return;
        }

        // Array mailer + queued notifications SIGEXIT on some CI/test VMs.
        // Tests that need the send path should call CentralEmailService directly.
        if (app()->runningUnitTests() && config('email_templates.mailer') === 'array') {
            return;
        }

        $payload = $notification->toCentralEmail($notifiable);
        $fallbackRecipient = $this->resolveRecipient($notifiable, $notification);
        $explicitRecipients = $payload['to'] ?? $fallbackRecipient;

        if (empty($explicitRecipients)) {
            return;
        }

        $meta = (array) ($payload['meta'] ?? []);
        $meta['notification'] = $notification::class;
        $meta['notifiable_type'] = $notifiable::class;
        $meta['notifiable_id'] = property_exists($notifiable, 'id') ? $notifiable->id : null;

        try {
            $this->centralEmailService->send([
                'to' => $explicitRecipients,
                'template' => $payload['template'] ?? 'general_notification',
                'subject' => $payload['subject'] ?? null,
                'data' => (array) ($payload['data'] ?? []),
                'attachments' => (array) ($payload['attachments'] ?? []),
                'cc' => $payload['cc'] ?? [],
                'bcc' => $payload['bcc'] ?? [],
                'reply_to' => $payload['reply_to'] ?? [],
                'workspace_id' => isset($payload['workspace_id']) ? (int) $payload['workspace_id'] : null,
                'email_message_id' => isset($payload['email_message_id']) ? (int) $payload['email_message_id'] : null,
                'meta' => $meta,
            ]);
        } catch (\Throwable $exception) {
            Log::error('central-mail-channel-failed', [
                'notification' => $notification::class,
                'notifiable_type' => $notifiable::class,
                'notifiable_id' => property_exists($notifiable, 'id') ? $notifiable->id : null,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function resolveRecipient(object $notifiable, Notification $notification): string|array|null
    {
        if (method_exists($notifiable, 'routeNotificationFor')) {
            $central = $notifiable->routeNotificationFor('central_mail', $notification);
            if (! empty($central)) {
                return $central;
            }

            $mail = $notifiable->routeNotificationFor('mail', $notification);
            if (! empty($mail)) {
                return $mail;
            }
        }

        if ($notifiable instanceof AnonymousNotifiable) {
            return null;
        }

        return property_exists($notifiable, 'email') ? $notifiable->email : null;
    }
}
