<?php

namespace App\Services\Finance\Assistant;

use RuntimeException;

final class FinanceAssistantAiUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('ميزة الذكاء الاصطناعي غير متاحة لباقتك.');
    }
}
