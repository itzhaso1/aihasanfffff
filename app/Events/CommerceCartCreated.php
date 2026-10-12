<?php

namespace App\Events;

use App\Models\Commerce\CommerceCart;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CommerceCartCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly CommerceCart $cart) {}
}
