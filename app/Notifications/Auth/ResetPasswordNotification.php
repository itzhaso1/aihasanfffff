<?php

namespace App\Notifications\Auth;

use App\Contracts\Email\CentralEmailNotification;
use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;

class ResetPasswordNotification extends BaseResetPassword implements CentralEmailNotification
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
            'template' => 'password_reset',
            'subject' => 'إعادة تعيين كلمة المرور',
            'data' => [
                'headline' => 'إعادة تعيين كلمة المرور',
                'intro' => 'تلقينا طلبًا لإعادة تعيين كلمة المرور الخاصة بك.',
                'action_text' => 'إعادة تعيين كلمة المرور',
                'action_url' => $this->resetUrl($notifiable),
                'footer' => 'إذا لم تطلب ذلك فلا حاجة لأي إجراء إضافي.',
            ],
        ];
    }
}
