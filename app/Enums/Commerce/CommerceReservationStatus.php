<?php

namespace App\Enums\Commerce;

enum CommerceReservationStatus: string
{
    case Reserved = 'reserved';
    case Released = 'released';
    case Consumed = 'consumed';
}
