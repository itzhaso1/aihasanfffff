<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationResponderLog extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public $timestamps = false;

    protected $table = 'conversation_responder_logs';

    protected $fillable = [
        'workspace_id',
        'conversation_id',
        'previous_responder_type',
        'previous_user_id',
        'previous_responder_name',
        'new_responder_type',
        'new_user_id',
        'new_responder_name',
        'action',
        'actor_type',
        'actor_id',
        'reason',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function previousUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'previous_user_id');
    }

    public function newUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'new_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Human-readable Arabic label for the log action.
     */
    public function actionLabel(): string
    {
        return match ($this->action) {
            'ai_started' => 'بدأ مشعل AI الرد',
            'assigned_to_human' => 'تم تحويل المحادثة إلى موظف',
            'takeover' => 'تولى الموظف الرد على المحادثة',
            'returned_to_ai' => 'تمت إعادة المحادثة إلى مشعل AI',
            'human_message_sent' => 'أرسل الموظف رسالة',
            'handoff_requested' => 'طلب تحويل إلى موظف',
            'reassigned' => 'إعادة إسناد المحادثة لموظف آخر',
            'resolved' => 'إنهاء متابعة الموظف',
            default => $this->action,
        };
    }
}
