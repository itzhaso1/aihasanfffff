<?php

namespace App\Contracts\Email;

interface CentralEmailNotification
{
    /**
     * @return array{
     *     template?: string,
     *     subject?: string|null,
     *     data?: array<string, mixed>,
     *     to?: string|array<int, string>,
     *     cc?: string|array<int, string>,
     *     bcc?: string|array<int, string>,
     *     reply_to?: string|array<int, string>|null,
     *     attachments?: array<int, array<string, mixed>>,
     *     workspace_id?: int|null,
     *     email_message_id?: int|null,
     *     meta?: array<string, mixed>
     * }
     */
    public function toCentralEmail(object $notifiable): array;
}
