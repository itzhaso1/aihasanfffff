<?php

namespace App\Services\EInvoicing\Security;

/**
 * Future Finance API / workspace web onboarding actions must authorize with Spatie permission:
 * {@see PERMISSION} (same as {@see \App\Http\Controllers\Api\Finance\V1\SettingsController}).
 */
final class EgsOnboardingAuthorization
{
    public const PERMISSION = 'finance.settings';
}
