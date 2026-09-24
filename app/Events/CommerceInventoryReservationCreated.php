<?php

namespace App\Events;

use App\Models\Commerce\CommerceInventoryReservation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CommerceInventoryReservationCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly CommerceInventoryReservation $reservation) {}
}
