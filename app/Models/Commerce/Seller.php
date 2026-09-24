<?php

namespace App\Models\Commerce;

use App\Enums\Commerce\CommerceSellerStatus;
use App\Enums\Commerce\CommerceSellerType;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Workspace;
use App\Models\WorkspaceScopedModel;
use Database\Factories\Commerce\SellerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'name',
    'slug',
    'display_name',
    'seller_type',
    'status',
    'metadata',
])]
class Seller extends WorkspaceScopedModel
{
    /** @use HasFactory<SellerFactory> */
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    protected $table = 'commerce_sellers';

    protected function casts(): array
    {
        return [
            'seller_type' => CommerceSellerType::class,
            'status' => CommerceSellerStatus::class,
            'metadata' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function paymentAccounts(): HasMany
    {
        return $this->hasMany(SellerPaymentAccount::class, 'commerce_seller_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SellerAllocation::class, 'commerce_seller_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'commerce_seller_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'commerce_seller_id');
    }
}
