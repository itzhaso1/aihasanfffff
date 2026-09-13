<?php

namespace App\Policies;

use App\Models\PosCashierInvoice;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceMembership;

class PosCashierInvoicePolicy
{
    use ChecksWorkspaceMembership;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PosCashierInvoice $invoice): bool
    {
        return $this->hasMembership($user, $invoice->workspace);
    }

    public function update(User $user, PosCashierInvoice $invoice): bool
    {
        return $this->hasMembership($user, $invoice->workspace);
    }
}
