<?php

namespace App\Services\Mobile;

enum FcmDeliveryStatus
{
    case Sent;
    case InvalidToken;
    case RetryableFailure;
    case PermanentFailure;
}
