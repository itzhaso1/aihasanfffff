<?php

namespace App\Notifications;

use App\Contracts\Email\CentralEmailNotification;
use App\Models\EmployeeInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class EmployeeInvitationNotification extends Notification implements ShouldQueue, CentralEmailNotification
{
    use Queueable;

    public function __construct(public readonly EmployeeInvitation $invitation) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['central_mail', 'database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toCentralEmail(object $notifiable): array
    {
        $acceptUrl = url('/employee-invitations/accept?token='.$this->invitation->token);

        return [
            'template' => 'general_notification',
            'subject' => 'دعوة انضمام إلى مساحة عمل',
            'workspace_id' => $this->invitation->workspace_id,
            'data' => [
                'headline' => 'دعوة انضمام إلى مساحة عمل',
                'intro' => 'تمت دعوتك للانضمام إلى مساحة عمل بدور: '.$this->invitation->role,
                'action_text' => 'قبول الدعوة',
                'action_url' => $acceptUrl,
                'footer' => 'تنتهي الدعوة في: '.$this->invitation->expires_at?->toDateTimeString(),
            ],
        ];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'employee_invitation',
            'invitation_id' => $this->invitation->id,
            'workspace_id' => $this->invitation->workspace_id,
            'role' => $this->invitation->role,
        ];
    }
}
