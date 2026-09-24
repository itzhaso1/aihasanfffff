<?php

namespace App\Models\Commerce;

use App\Enums\Commerce\CommerceSellerPaymentAccountStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Workspace;
use App\Models\WorkspaceScopedModel;
use Database\Factories\Commerce\SellerPaymentAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'commerce_seller_id',
    'provider',
    'provider_reference',
    'status',
    'metadata',
])]
class SellerPaymentAccount extends WorkspaceScopedModel
{
    /** @use HasFactory<SellerPaymentAccountFactory> */
    use BelongsToWorkspace, HasFactory;

    protected $table = 'commerce_seller_payment_accounts';

    protected function casts(): array
    {
        return [
            'status' => CommerceSellerPaymentAccountStatus::class,
            'metadata' => 'array',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'commerce_seller_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
