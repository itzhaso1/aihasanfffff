<?php

namespace App\Services\Email;

use App\Models\EmailContact;
use App\Models\EmailContactGroup;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class EmailContactGroupService
{
    /**
     * @return Collection<int, EmailContactGroup>
     */
    public function list(Workspace $workspace): Collection
    {
        return EmailContactGroup::query()
            ->where('workspace_id', $workspace->id)
            ->withCount('contacts')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array{name:string,description?:string|null}  $data
     */
    public function create(Workspace $workspace, array $data): EmailContactGroup
    {
        $this->assertUniqueName($workspace, (string) $data['name']);

        return EmailContactGroup::query()->create([
            'workspace_id' => $workspace->id,
            'name' => trim((string) $data['name']),
            'description' => $data['description'] ?? null,
        ]);
    }

    /**
     * @param  array{name?:string,description?:string|null}  $data
     */
    public function update(EmailContactGroup $group, array $data): EmailContactGroup
    {
        if (array_key_exists('name', $data)) {
            $this->assertUniqueName(
                $group->workspace ?? Workspace::query()->findOrFail($group->workspace_id),
                (string) $data['name'],
                $group->id,
            );
            $group->name = trim((string) $data['name']);
        }

        if (array_key_exists('description', $data)) {
            $group->description = $data['description'];
        }

        $group->save();

        return $group->refresh()->loadCount('contacts');
    }

    public function delete(EmailContactGroup $group): void
    {
        // Detach only — do not delete contacts.
        $group->contacts()->detach();
        $group->delete();
    }

    /**
     * @param  array<int, int>  $contactIds
     */
    public function syncMembers(EmailContactGroup $group, array $contactIds): EmailContactGroup
    {
        $validIds = EmailContact::query()
            ->where('workspace_id', $group->workspace_id)
            ->whereIn('id', $contactIds)
            ->pluck('id')
            ->all();

        $group->contacts()->sync($validIds);

        return $group->refresh()->load(['contacts'])->loadCount('contacts');
    }

    public function findForWorkspace(Workspace $workspace, int $groupId): EmailContactGroup
    {
        return EmailContactGroup::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($groupId);
    }

    private function assertUniqueName(Workspace $workspace, string $name, ?int $exceptId = null): void
    {
        $exists = EmailContactGroup::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('name', trim($name))
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => ['اسم المجموعة مستخدم مسبقاً.'],
            ]);
        }
    }
}
