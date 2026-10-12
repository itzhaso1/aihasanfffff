<?php

namespace App\Services\Stories;

use App\Exceptions\MissingWorkspaceContextException;
use App\Models\Story;
use App\Models\StoryView;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class StoryService
{
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    private const VIDEO_MIMES = ['video/mp4', 'video/quicktime'];

    private const IMAGE_MAX_BYTES = 5 * 1024 * 1024;

    private const VIDEO_MAX_BYTES = 30 * 1024 * 1024;

    /**
     * @param  array{
     *     type:string,
     *     caption?:string|null,
     *     body_text?:string|null,
     *     background_color?:string|null,
     *     visibility?:string,
     *     selected_user_ids?:array<int,int>|null,
     *     hidden_user_ids?:array<int,int>|null,
     *     expires_in_hours?:int|null,
     *     file?:UploadedFile|null
     * }  $data
     */
    public function create(Workspace $workspace, User $author, array $data): Story
    {
        $type = (string) $data['type'];
        $visibility = (string) ($data['visibility'] ?? Story::VISIBILITY_WORKSPACE);
        $expiresInHours = max(1, min(168, (int) ($data['expires_in_hours'] ?? config('hasim.stories.default_expires_hours', 24))));

        $mediaDisk = null;
        $mediaPath = null;
        $mediaMime = null;
        $mediaSize = null;

        if ($type === Story::TYPE_TEXT) {
            if (trim((string) ($data['body_text'] ?? '')) === '') {
                throw new InvalidArgumentException('نص القصة مطلوب.');
            }
        } elseif (in_array($type, [Story::TYPE_IMAGE, Story::TYPE_VIDEO], true)) {
            $file = $data['file'] ?? null;
            if (! $file instanceof UploadedFile) {
                throw new InvalidArgumentException('ملف الوسائط مطلوب.');
            }

            [$mediaDisk, $mediaPath, $mediaMime, $mediaSize] = $this->storeMedia(
                $workspace,
                $file,
                $type,
            );
        } else {
            throw new InvalidArgumentException('نوع القصة غير مدعوم.');
        }

        if (! in_array($visibility, [
            Story::VISIBILITY_WORKSPACE,
            Story::VISIBILITY_SELECTED,
            Story::VISIBILITY_HIDDEN,
        ], true)) {
            throw new InvalidArgumentException('إعدادات الظهور غير صالحة.');
        }

        $selectedUserIds = $visibility === Story::VISIBILITY_SELECTED
            ? $this->resolveWorkspaceMemberIds($workspace, $data['selected_user_ids'] ?? [])
            : null;
        $hiddenUserIds = $visibility === Story::VISIBILITY_HIDDEN
            ? $this->resolveWorkspaceMemberIds($workspace, $data['hidden_user_ids'] ?? [])
            : null;

        return Story::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $author->id,
            'type' => $type,
            'caption' => $data['caption'] ?? null,
            'body_text' => $data['body_text'] ?? null,
            'background_color' => $data['background_color'] ?? null,
            'media_disk' => $mediaDisk,
            'media_path' => $mediaPath,
            'media_mime' => $mediaMime,
            'media_size' => $mediaSize,
            'visibility' => $visibility,
            'selected_user_ids' => $selectedUserIds,
            'hidden_user_ids' => $hiddenUserIds,
            'expires_at' => now()->addHours($expiresInHours),
            'views_count' => 0,
            'status' => Story::STATUS_ACTIVE,
        ]);
    }

    /**
     * @return Collection<int, Story>
     */
    public function listVisibleForUser(Workspace $workspace, User $user): Collection
    {
        $this->expireOldStories($workspace);

        return Story::query()
            ->with([
                'author:id,name,avatar_path',
                'views' => fn ($query) => $query->where('user_id', $user->id),
            ])
            ->where('workspace_id', $workspace->id)
            ->where('status', Story::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Story $story): bool => $story->isVisibleTo($user))
            ->values();
    }

    public function findVisible(Workspace $workspace, User $user, int $storyId): Story
    {
        $story = Story::query()
            ->with([
                'author:id,name,avatar_path',
                'views' => fn ($query) => $query->where('user_id', $user->id),
            ])
            ->where('workspace_id', $workspace->id)
            ->findOrFail($storyId);

        if (! $story->isVisibleTo($user)) {
            throw new RuntimeException('القصة غير متاحة.');
        }

        return $story;
    }

    public function markViewed(Story $story, User $user): StoryView
    {
        if ((int) $story->user_id === (int) $user->id) {
            return StoryView::query()->firstOrNew([
                'story_id' => $story->id,
                'user_id' => $user->id,
            ]);
        }

        return DB::transaction(function () use ($story, $user): StoryView {
            $view = StoryView::query()->firstOrCreate(
                [
                    'story_id' => $story->id,
                    'user_id' => $user->id,
                ],
                [
                    'viewed_at' => now(),
                ],
            );

            if ($view->wasRecentlyCreated) {
                $story->increment('views_count');
            }

            return $view;
        });
    }

    public function delete(Story $story, User $actor): void
    {
        if ((int) $story->user_id !== (int) $actor->id) {
            throw new RuntimeException('يمكنك حذف قصصك فقط.');
        }

        $story->forceFill([
            'status' => Story::STATUS_DELETED,
        ])->save();

        $story->delete();
    }

    public function expireOldStories(?Workspace $workspace = null): int
    {
        $context = app(WorkspaceContext::class);
        if ($workspace === null && ! $context->isBypassingTenantScope()) {
            throw MissingWorkspaceContextException::forWrite(Story::class, 'expire');
        }

        $query = Story::withoutGlobalScopes()
            ->where('status', Story::STATUS_ACTIVE)
            ->where('expires_at', '<', now());

        if ($workspace !== null) {
            $query->where('workspace_id', $workspace->id);
        }

        return $query->update(['status' => Story::STATUS_EXPIRED]);
    }

    /**
     * @return Collection<int, StoryView>
     */
    public function viewers(Story $story, User $actor): Collection
    {
        if ((int) $story->user_id !== (int) $actor->id) {
            throw new RuntimeException('عرض المشاهدين متاح للكاتب فقط.');
        }

        return $story->views()
            ->with(['user:id,name,avatar_path'])
            ->orderByDesc('viewed_at')
            ->get();
    }

    /**
     * @return array{0:string,1:string,2:string,3:int}
     */
    private function storeMedia(Workspace $workspace, UploadedFile $file, string $type): array
    {
        $mime = (string) $file->getMimeType();
        $size = (int) $file->getSize();

        if ($type === Story::TYPE_IMAGE) {
            if (! in_array($mime, self::IMAGE_MIMES, true)) {
                throw new InvalidArgumentException('صيغة الصورة غير مدعومة.');
            }
            if ($size > self::IMAGE_MAX_BYTES) {
                throw new InvalidArgumentException('حجم الصورة يتجاوز 5 ميجابايت.');
            }
        }

        if ($type === Story::TYPE_VIDEO) {
            if (! in_array($mime, self::VIDEO_MIMES, true)) {
                throw new InvalidArgumentException('صيغة الفيديو غير مدعومة.');
            }
            if ($size > self::VIDEO_MAX_BYTES) {
                throw new InvalidArgumentException('حجم الفيديو يتجاوز 30 ميجابايت.');
            }
        }

        $filename = Str::uuid()->toString().'.'.$file->guessExtension();
        $directory = "stories/{$workspace->id}";
        $path = $file->storeAs($directory, $filename, 'public');

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('تعذر حفظ الوسائط.');
        }

        return ['public', $path, $mime, $size];
    }

    /**
     * @param  array<int, mixed>  $userIds
     * @return list<int>
     */
    private function resolveWorkspaceMemberIds(Workspace $workspace, array $userIds): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            fn ($id): int => (int) $id,
            $userIds,
        ), fn (int $id): bool => $id > 0)));

        if ($normalized === []) {
            return [];
        }

        $memberIds = $workspace->users()
            ->wherePivot('status', 'active')
            ->whereIn('users.id', $normalized)
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $foreign = array_diff($normalized, $memberIds);
        if ($foreign !== []) {
            throw new InvalidArgumentException('Some audience user IDs do not belong to this workspace.');
        }

        return $memberIds;
    }
}
