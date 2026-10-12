<?php

declare(strict_types=1);

namespace App\Models\Worker;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'worker_profile_id',
    'legal_name',
    'date_of_birth',
    'identity_number',
    'address',
])]
class WorkerIdentityPrivate extends Model
{
    protected $table = 'worker_identity_private';

    protected function casts(): array
    {
        return [
            'legal_name' => 'encrypted',
            'date_of_birth' => 'date',
            'identity_number' => 'encrypted',
            'address' => 'encrypted',
        ];
    }

    public function workerProfile(): BelongsTo
    {
        return $this->belongsTo(WorkerProfile::class);
    }

    public function isComplete(): bool
    {
        return filled($this->legal_name)
            && $this->date_of_birth !== null
            && filled($this->identity_number);
    }
}
