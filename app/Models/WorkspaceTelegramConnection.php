<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Services\Meta\MetaTokenVault;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'bot_user_id',
    'bot_username',
    'public_webhook_key',
    'integration_mode',
    'status',
    'metadata',
    'connected_by_user_id',
])]
class WorkspaceTelegramConnection extends WorkspaceScopedModel
{
    use BelongsToWorkspace, SoftDeletes;

    public const MODE_BOT = 'bot';

    public const MODE_BUSINESS = 'business';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_ERROR = 'error';

    public const STATUS_DISCONNECTED = 'disconnected';

    /**
     * @var list<string>
     */
    protected $hidden = [
        'bot_token_ciphertext',
        'webhook_secret_ciphertext',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    public function storeBotToken(string $token): void
    {
        $this->bot_token_ciphertext = app(MetaTokenVault::class)->encrypt($token);
    }

    public function botToken(): ?string
    {
        return app(MetaTokenVault::class)->decrypt($this->bot_token_ciphertext);
    }

    public function storeWebhookSecret(?string $secret): void
    {
        $this->webhook_secret_ciphertext = app(MetaTokenVault::class)->encrypt($secret);
    }

    public function webhookSecret(): ?string
    {
        return app(MetaTokenVault::class)->decrypt($this->webhook_secret_ciphertext);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        unset($array['bot_token_ciphertext'], $array['webhook_secret_ciphertext']);

        return $array;
    }
}
