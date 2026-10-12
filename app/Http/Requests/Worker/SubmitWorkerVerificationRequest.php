<?php

declare(strict_types=1);

namespace App\Http\Requests\Worker;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;

class SubmitWorkerVerificationRequest extends FormRequest
{
    public function authorize(): bool|Response
    {
        $user = $this->user();

        if ($user?->workerProfile === null) {
            return false;
        }

        if ($user->email_verified_at === null) {
            return Response::deny('يجب تأكيد البريد الإلكتروني قبل رفع مستندات التحقق أو إرسال الملف للمراجعة.');
        }

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
