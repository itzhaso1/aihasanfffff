<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Resources\Mobile\StoryResource;
use App\Models\Story;
use App\Services\Stories\StoryService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;

class StoryController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly StoryService $storyService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $stories = $this->storyService->listVisibleForUser($workspace, $request->user());

        return $this->ok(StoryResource::collection($stories));
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);

        $validated = $request->validate([
            'type' => ['required', Rule::in(['text', 'image', 'video'])],
            'caption' => ['nullable', 'string', 'max:2000'],
            'body_text' => ['nullable', 'string', 'max:5000'],
            'background_color' => ['nullable', 'string', 'max:32'],
            'visibility' => ['nullable', Rule::in(['workspace', 'selected', 'hidden'])],
            'selected_user_ids' => ['nullable', 'array'],
            'selected_user_ids.*' => ['integer'],
            'hidden_user_ids' => ['nullable', 'array'],
            'hidden_user_ids.*' => ['integer'],
            'expires_in_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'file' => ['nullable', 'file'],
        ]);

        try {
            $story = $this->storyService->create($workspace, $request->user(), [
                ...$validated,
                'file' => $request->file('file'),
            ]);
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 500);
        }

        return $this->ok(
            new StoryResource($story->load('author:id,name,avatar_path')),
            message: 'تم نشر القصة بنجاح.',
            status: 201,
        );
    }

    public function show(Request $request, Story $story): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);

        try {
            $story = $this->storyService->findVisible($workspace, $request->user(), $story->id);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 403);
        }

        return $this->ok(new StoryResource($story));
    }

    public function view(Request $request, Story $story): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);

        try {
            $story = $this->storyService->findVisible($workspace, $request->user(), $story->id);
            $this->storyService->markViewed($story, $request->user());
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 403);
        }

        return $this->ok(
            new StoryResource($story->fresh()->load([
                'author:id,name,avatar_path',
                'views' => fn ($query) => $query->where('user_id', $request->user()->id),
            ])),
            message: 'تم تسجيل المشاهدة.',
        );
    }

    public function destroy(Request $request, Story $story): JsonResponse
    {
        $this->requireWorkspace($this->workspaceContext);

        try {
            $this->storyService->delete($story, $request->user());
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 403);
        }

        return $this->ok(null, message: 'تم حذف القصة.');
    }

    public function viewers(Request $request, Story $story): JsonResponse
    {
        $this->requireWorkspace($this->workspaceContext);

        try {
            $views = $this->storyService->viewers($story, $request->user());
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 403);
        }

        $data = $views->map(fn ($view) => [
            'user_id' => $view->user_id,
            'viewed_at' => optional($view->viewed_at)?->toIso8601String(),
            'user' => $view->user ? [
                'id' => $view->user->id,
                'name' => $view->user->name,
                'avatar_path' => $view->user->avatar_path ?? null,
            ] : null,
        ])->values();

        return $this->ok($data);
    }
}
