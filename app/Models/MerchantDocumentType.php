<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code',
    'name_ar',
    'name_en',
    'requires_number',
    'requires_expiry',
    'is_active',
    'sort_order',
])]
class MerchantDocumentType extends Model
{
    protected function casts(): array
    {
        return [
            'requires_number' => 'boolean',
            'requires_expiry' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(MerchantDocument::class, 'document_type_id');
    }
}
