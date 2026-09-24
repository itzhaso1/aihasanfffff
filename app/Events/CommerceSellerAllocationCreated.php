<?php

namespace App\Events;

use App\Models\Commerce\SellerAllocation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CommerceSellerAllocationCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly SellerAllocation $allocation) {}
}
