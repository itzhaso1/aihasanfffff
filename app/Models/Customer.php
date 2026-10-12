<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'name',
    'party_type',
    'phone',
    'client_reference',
    'whatsapp',
    'email',
    'vat_number',
    'commercial_registration',
    'address',
    'building_number',
    'street',
    'district',
    'city',
    'postal_code',
    'country_code',
    'additional_number',
    'payment_terms',
    'balance',
    'orders_count',
    'total_purchases',
    'last_order_at',
    'last_conversation_at',
    'notes',
    'metadata',
])]
class Customer extends WorkspaceScopedModel
{
    /** @use HasFactory<CustomerFactory> */
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    public const PARTY_TYPE_INDIVIDUAL = 'individual';

    public const PARTY_TYPE_COMPANY = 'company';

    protected function casts(): array
    {
        return [
            'total_purchases' => 'decimal:2',
            'balance' => 'decimal:2',
            'last_order_at' => 'datetime',
            'last_conversation_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function partyType(): string
    {
        $type = strtolower(trim((string) ($this->party_type ?: self::PARTY_TYPE_INDIVIDUAL)));

        return $type === self::PARTY_TYPE_COMPANY
            ? self::PARTY_TYPE_COMPANY
            : self::PARTY_TYPE_INDIVIDUAL;
    }

    public function isCompany(): bool
    {
        return $this->partyType() === self::PARTY_TYPE_COMPANY;
    }

    /**
     * Stored cache column. Not an AR ledger.
     */
    public function storedBalance(): float
    {
        return round((float) $this->balance, 2);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function channelIdentities(): HasMany
    {
        return $this->hasMany(CustomerChannelIdentity::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(CustomerTag::class, 'customer_tag_customer')
            ->withTimestamps();
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(CustomerGroup::class, 'customer_group_customer')
            ->withTimestamps();
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class);
    }
}
