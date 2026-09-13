<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'whats_app_account_id',
    'phone_number_id',
    'display_phone_number',
    'verified_name',
    'status',
])]
class WhatsAppPhoneNumber extends WorkspaceScopedModel
{
    /** @use HasFactory<\Database\Factories\WhatsAppPhoneNumberFactory> */
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whats_app_account_id');
    }
}
