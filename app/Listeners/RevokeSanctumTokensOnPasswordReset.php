<?php

namespace App\Listeners;

use App\Services\Auth\UserSanctumTokenRevocationService;
use Illuminate\Auth\Events\PasswordReset;

class RevokeSanctumTokensOnPasswordReset
{
    public function __construct(
        private readonly UserSanctumTokenRevocationService $tokenRevocationService,
    ) {}

    public function handle(PasswordReset $event): void
    {
        $this->tokenRevocationService->revokeAllAfterReset($event->user);
    }
}
