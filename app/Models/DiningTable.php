<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'name',
    'status',
    'qr_token',
])]
class DiningTable extends WorkspaceScopedModel
{
    /** @use HasFactory<\Database\Factories\DiningTableFactory> */
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    public function sessions(): HasMany
    {
        return $this->hasMany(TableSession::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'dining_table_id');
    }

    public function cashierInvoices(): HasMany
    {
        return $this->hasMany(PosCashierInvoice::class, 'dining_table_id');
    }
}
