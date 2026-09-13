<?php

namespace App\Services\Email;

use App\Models\EmailContact;
use App\Models\EmailMessage;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class EmailContactService
{
    /**
     * @param  array{
     *     q?:string|null,
     *     search?:string|null,
     *     favorite?:bool|null,
     *     per_page?:int
     * }  $filters
     */
    public function list(Workspace $workspace, array $filters = []): CursorPaginator
    {
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 20)));
        $search = trim((string) ($filters['q'] ?? $filters['search'] ?? ''));
        $favoriteOnly = array_key_exists('favorite', $filters)
            ? filter_var($filters['favorite'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        return EmailContact::query()
            ->where('workspace_id', $workspace->id)
            ->when($favoriteOnly === true, fn (Builder $q) => $q->where('is_favorite', true))
            ->when($search !== '', function (Builder $q) use ($search): void {
                $q->where(function (Builder $inner) use ($search): void {
                    $inner->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('normalized_email', 'like', '%'.strtolower($search).'%')
                        ->orWhere('company', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }

    /**
     * @param  array{
     *     name:string,
     *     email:string,
     *     phone?:string|null,
     *     company?:string|null,
     *     job_title?:string|null,
     *     notes?:string|null,
     *     is_favorite?:bool
     * }  $data
     */
    public function create(Workspace $workspace, array $data): EmailContact
    {
        $normalized = $this->normalizeEmail((string) $data['email']);
        if ($normalized === '') {
            throw ValidationException::withMessages([
                'email' => ['البريد الإلكتروني غير صالح.'],
            ]);
        }

        $existing = EmailContact::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('normalized_email', $normalized)
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'email' => ['هذا البريد الإلكتروني مسجل مسبقًا.'],
            ]);
        }

        return EmailContact::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $data['name'],
            'email' => trim((string) $data['email']),
            'normalized_email' => $normalized,
            'phone' => $data['phone'] ?? null,
            'company' => $data['company'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_favorite' => (bool) ($data['is_favorite'] ?? false),
        ]);
    }

    /**
     * @param  array{
     *     name?:string,
     *     email?:string,
     *     phone?:string|null,
     *     company?:string|null,
     *     job_title?:string|null,
     *     notes?:string|null,
     *     is_favorite?:bool
     * }  $data
     */
    public function update(EmailContact $contact, array $data): EmailContact
    {
        $payload = [];

        if (array_key_exists('name', $data)) {
            $payload['name'] = $data['name'];
        }
        if (array_key_exists('phone', $data)) {
            $payload['phone'] = $data['phone'];
        }
        if (array_key_exists('company', $data)) {
            $payload['company'] = $data['company'];
        }
        if (array_key_exists('job_title', $data)) {
            $payload['job_title'] = $data['job_title'];
        }
        if (array_key_exists('notes', $data)) {
            $payload['notes'] = $data['notes'];
        }
        if (array_key_exists('is_favorite', $data)) {
            $payload['is_favorite'] = (bool) $data['is_favorite'];
        }

        if (array_key_exists('email', $data)) {
            $normalized = $this->normalizeEmail((string) $data['email']);
            if ($normalized === '') {
                throw ValidationException::withMessages([
                    'email' => ['البريد الإلكتروني غير صالح.'],
                ]);
            }

            $existing = EmailContact::withoutGlobalScopes()
                ->where('workspace_id', $contact->workspace_id)
                ->where('normalized_email', $normalized)
                ->where('id', '!=', $contact->id)
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'email' => ['هذا البريد الإلكتروني مسجل مسبقًا.'],
                ]);
            }

            $payload['email'] = trim((string) $data['email']);
            $payload['normalized_email'] = $normalized;
        }

        $contact->update($payload);

        return $contact->refresh();
    }

    public function delete(EmailContact $contact): void
    {
        $contact->delete();
    }

    public function toggleFavorite(EmailContact $contact): EmailContact
    {
        $contact->update(['is_favorite' => ! $contact->is_favorite]);

        return $contact->refresh();
    }

    /**
     * Recent outbound recipients parsed from EmailMessage.recipient (workspace scoped).
     *
     * @return Collection<int, array{email:string,name:?string,contact_id:?int}>
     */
    public function recentRecipients(Workspace $workspace, int $limit = 20): Collection
    {
        $messages = EmailMessage::query()
            ->where('workspace_id', $workspace->id)
            ->where('type', 'outbound')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['recipient']);

        $seen = [];
        $results = collect();

        foreach ($messages as $message) {
            foreach ($this->parseRecipients((string) $message->recipient) as $email) {
                $normalized = $this->normalizeEmail($email);
                if ($normalized === '' || isset($seen[$normalized])) {
                    continue;
                }

                $seen[$normalized] = true;
                $contact = EmailContact::withoutGlobalScopes()
                    ->where('workspace_id', $workspace->id)
                    ->where('normalized_email', $normalized)
                    ->first();

                $results->push([
                    'email' => $normalized,
                    'name' => $contact?->name,
                    'contact_id' => $contact?->id,
                ]);

                if ($results->count() >= $limit) {
                    return $results;
                }
            }
        }

        return $results;
    }

    public function findForWorkspace(Workspace $workspace, int $contactId): EmailContact
    {
        return EmailContact::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($contactId);
    }

    public function normalizeEmail(string $email): string
    {
        $candidate = trim($email);

        if (str_contains($candidate, '<') && str_contains($candidate, '>')) {
            $start = strpos($candidate, '<');
            $end = strrpos($candidate, '>');
            if ($start !== false && $end !== false && $end > $start) {
                $candidate = substr($candidate, $start + 1, $end - $start - 1);
            }
        }

        $candidate = strtolower(trim($candidate));

        return filter_var($candidate, FILTER_VALIDATE_EMAIL) ? $candidate : '';
    }

    /**
     * @return array<int, string>
     */
    private function parseRecipients(string $recipient): array
    {
        return collect(preg_split('/[,;]+/', $recipient) ?: [])
            ->map(fn (string $part): string => trim($part))
            ->filter()
            ->values()
            ->all();
    }
}
