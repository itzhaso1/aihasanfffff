<?php

namespace App\Notifications\Auth;

use App\Contracts\Email\CentralEmailNotification;
use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;

class VerifyEmailNotification extends BaseVerifyEmail implements CentralEmailNotification
{
    /**
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        return ['central_mail'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toCentralEmail(object $notifiable): array
    {
        return [
            'template' => 'email_verification',
            'subject' => 'تأكيد البريد الإلكتروني',
            'data' => [
                'headline' => 'تأكيد البريد الإلكتروني',
                'intro' => 'يرجى تأكيد بريدك الإلكتروني لإكمال تفعيل حسابك.',
                'action_text' => 'تأكيد البريد',
                'action_url' => $this->verificationUrl($notifiable),
                'footer' => 'إذا لم تقم بإنشاء الحساب يمكنك تجاهل هذه الرسالة.',
            ],
        ];
    }
}
