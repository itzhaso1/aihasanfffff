<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'user_id',
    'type',
    'caption',
    'body_text',
    'background_color',
    'media_disk',
    'media_path',
    'media_mime',
    'media_size',
    'thumbnail_path',
    'visibility',
    'selected_user_ids',
    'hidden_user_ids',
    'expires_at',
    'views_count',
    'status',
])]
class Story extends WorkspaceScopedModel
{
    use BelongsToWorkspace;
    use SoftDeletes;

    public const TYPE_TEXT = 'text';

    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    public const VISIBILITY_WORKSPACE = 'workspace';

    public const VISIBILITY_SELECTED = 'selected';

    public const VISIBILITY_HIDDEN = 'hidden';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_DELETED = 'deleted';

    protected function casts(): array
    {
        return [
            'selected_user_ids' => 'array',
            'hidden_user_ids' => 'array',
            'expires_at' => 'datetime',
            'media_size' => 'integer',
            'views_count' => 'integer',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function views(): HasMany
    {
        return $this->hasMany(StoryView::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isVisibleTo(User $user): bool
    {
        if ((int) $this->user_id === (int) $user->id) {
            return true;
        }

        if ($this->status !== self::STATUS_ACTIVE || $this->isExpired()) {
            return false;
        }

        $userId = (int) $user->id;

        return match ($this->visibility) {
            self::VISIBILITY_SELECTED => in_array($userId, array_map('intval', $this->selected_user_ids ?? []), true),
            self::VISIBILITY_HIDDEN => ! in_array($userId, array_map('intval', $this->hidden_user_ids ?? []), true),
            default => true,
        };
    }
}
