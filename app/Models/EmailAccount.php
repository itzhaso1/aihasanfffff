<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Support\Email\CustomerMailboxEncryption;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'name',
    'email',
    'password',
    'imap_host',
    'imap_port',
    'imap_encryption',
    'imap_username',
    'smtp_host',
    'smtp_port',
    'smtp_encryption',
    'smtp_username',
    'smtp_status',
    'smtp_last_tested_at',
    'logo_path',
    'brand_color',
    'aliases',
])]
class EmailAccount extends WorkspaceScopedModel
{
    use BelongsToWorkspace, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'aliases' => 'array',
            'imap_port' => 'integer',
            'smtp_port' => 'integer',
            'smtp_last_tested_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    public function smtpUsername(): string
    {
        $username = trim((string) $this->smtp_username);

        return $username !== '' ? $username : (string) $this->email;
    }

    public function imapUsername(): string
    {
        $username = trim((string) $this->imap_username);

        return $username !== '' ? $username : (string) $this->email;
    }

    public function mailboxPassword(): string
    {
        return (string) $this->password;
    }

    public function resolvedSmtpEncryption(): string
    {
        return CustomerMailboxEncryption::resolveSmtp($this->smtp_encryption, (int) $this->smtp_port);
    }

    public function resolvedImapEncryption(): string
    {
        return CustomerMailboxEncryption::resolveImap($this->imap_encryption, (int) $this->imap_port);
    }

    public function imapMailbox(): string
    {
        $flags = match ($this->resolvedImapEncryption()) {
            CustomerMailboxEncryption::SSL => '/imap/ssl',
            CustomerMailboxEncryption::TLS => '/imap/tls',
            default => '/imap/notls',
        };

        return sprintf('{%s:%d%s}INBOX', $this->imap_host, $this->imap_port, $flags);
    }

    public function fromDisplayName(?string $requestedAlias = null): string
    {
        $alias = trim((string) $requestedAlias);
        if ($alias === '') {
            return (string) $this->name;
        }

        if (preg_match('/^(.*)<([^>]+)>$/', $alias, $matches) === 1) {
            $alias = trim($matches[1], " \t\"'");
        }

        if ($alias !== '' && filter_var($alias, FILTER_VALIDATE_EMAIL)) {
            return (string) $this->name;
        }

        return $alias !== '' ? $alias : (string) $this->name;
    }

    public function senderDisplayName(?string $sender = null): string
    {
        return $this->fromDisplayName($sender);
    }

    public function markSmtpTest(string $status): void
    {
        if (! $this->exists || ! $this->id) {
            return;
        }

        $testedAt = now();
        static::withoutGlobalScopes()
            ->whereKey($this->id)
            ->where('workspace_id', $this->workspace_id)
            ->update([
                'smtp_status' => $status,
                'smtp_last_tested_at' => $testedAt,
            ]);

        $this->smtp_status = $status;
        $this->smtp_last_tested_at = $testedAt;
    }
}
