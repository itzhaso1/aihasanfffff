<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\MobileController;
use App\Support\Inbox\InboxBroadcastConfig;
use Illuminate\Http\JsonResponse;

class RealtimeController extends MobileController
{
    public function config(): JsonResponse
    {
        return $this->ok(InboxBroadcastConfig::clientPayload());
    }
}
