<?php

declare(strict_types=1);

namespace App\Http\Controllers\Worker;

use App\Enums\ConversationHandoffStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Worker\StoreWorkerInboxMessageRequest;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Workspace;
use App\Services\Worker\WorkerEngagementAuthorization;
use App\Services\Worker\WorkerInboxException;
use App\Services\Worker\WorkerInboxService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

final class WorkerInboxController extends Controller
{
    public function __construct(
        private readonly WorkerInboxService $inbox,
        private readonly WorkerEngagementAuthorization $authorization,
    ) {}

    public function home(Request $request): View
    {
        $worker = $request->user();

        if (! $worker instanceof User) {
            return $this->view($request, null, null, new Collection, null);
        }

        $workspace = $this->chatWorkspace($worker);

        if ($workspace === null) {
            return $this->view($request, $worker, null, new Collection, null);
        }

        try {
            $conversations = $this->inbox->conversations($worker, $workspace);
        } catch (ModelNotFoundException) {
            $conversations = new Collection;
        }

        return $this->view($request, $worker, $workspace, $conversations, null);
    }

    public function index(Request $request, Workspace $workspace): View
    {
        try {
            $conversations = $this->inbox->conversations($request->user(), $workspace);
        } catch (ModelNotFoundException) {
            $conversations = new Collection;
        }
        $activeConversation = null;
        $conversationId = $request->integer('conversation');

        if ($conversationId > 0) {
            try {
                $activeConversation = $this->inbox->conversation(
                    $request->user(),
                    $workspace,
                    $conversationId,
                );
            } catch (ModelNotFoundException) {
                $activeConversation = null;
            }
        }

        return $this->view($request, $request->user(), $workspace, $conversations, $activeConversation);
    }

    public function show(
        Request $request,
        Workspace $workspace,
        int $conversation,
    ): View {
        $conversations = $this->inbox->conversations($request->user(), $workspace);
        $activeConversation = $this->inbox->conversation(
            $request->user(),
            $workspace,
            $conversation,
        );

        return $this->view($request, $request->user(), $workspace, $conversations, $activeConversation);
    }

    public function storeMessage(
        StoreWorkerInboxMessageRequest $request,
        Workspace $workspace,
        int $conversation,
    ): RedirectResponse {
        try {
            $this->inbox->sendMessage(
                $request->user(),
                $workspace,
                $conversation,
                $request->validated(),
                $request,
            );
        } catch (WorkerInboxException $exception) {
            return back()->withErrors(['message' => $exception->getMessage()]);
        }

        return redirect()
            ->route('worker.inbox.show', [$workspace, $conversation])
            ->with('success', 'تم إرسال الرسالة.');
    }

    /**
     * @param  Collection<int, Conversation>  $conversations
     */
    private function view(
        Request $request,
        ?User $worker,
        ?Workspace $workspace,
        Collection $conversations,
        ?Conversation $activeConversation,
    ): View {
        if ($workspace instanceof Workspace) {
            app(WorkspaceContext::class)->set($workspace);
        }

        $canSend = $workspace instanceof Workspace
            && $this->canSendMessage($worker, $workspace, $activeConversation);
        $activeConversation = $workspace instanceof Workspace
            ? $this->prepareActiveConversation($request, $workspace, $activeConversation)
            : null;

        return view('workspace.conversations.index', [
            'conversations' => $this->paginateConversations($request, $workspace, $conversations),
            'activeConversation' => $activeConversation,
            'channelFilter' => $this->normalizeChannelFilter($request->string('channel')->toString()),
            'availableChannels' => [
                'whatsapp' => 'WhatsApp',
                'instagram' => 'Instagram',
                'facebook_messenger' => 'Facebook Messenger',
                'email' => 'Email',
                'web' => 'Web',
                'manual' => 'Manual',
            ],
            'inboxIndexRoute' => $workspace instanceof Workspace ? 'worker.inbox.index' : 'worker.inbox.home',
            'inboxRouteParameters' => $workspace instanceof Workspace ? ['workspace' => $workspace->id] : [],
            'inboxAllowsCompanyActions' => false,
            'inboxCanSend' => $canSend,
            'inboxMessageAction' => $activeConversation instanceof Conversation && $workspace instanceof Workspace
                ? route('worker.inbox.messages.store', [$workspace, $activeConversation->id])
                : null,
            'inboxRealtimeEnabled' => false,
        ]);
    }

    /**
     * @param  Collection<int, Conversation>  $conversations
     */
    private function paginateConversations(
        Request $request,
        ?Workspace $workspace,
        Collection $conversations,
    ): LengthAwarePaginator {
        $channelFilter = $this->normalizeChannelFilter($request->string('channel')->toString());
        $status = $request->string('status')->toString();
        $handoff = $request->string('handoff')->toString();
        $search = mb_strtolower(trim($request->string('search')->toString()));

        $filtered = $conversations
            ->map(function (Conversation $conversation): Conversation {
                $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
                $conversation->setAttribute('display_channel', $this->displayChannel($conversation));
                $conversation->setAttribute('unread_count', (int) ($metadata['unread_count'] ?? 0));

                return $conversation;
            })
            ->filter(function (Conversation $conversation) use ($request, $channelFilter, $status, $handoff, $search): bool {
                if ($channelFilter !== null && $conversation->display_channel !== $channelFilter) {
                    return false;
                }

                if (in_array($status, ['open', 'closed', 'archived'], true) && $conversation->status !== $status) {
                    return false;
                }

                if ($request->boolean('unread') && (int) $conversation->unread_count < 1) {
                    return false;
                }

                if (! $this->matchesHandoffFilter($conversation, $handoff, $request->user()?->id)) {
                    return false;
                }

                if ($search === '') {
                    return true;
                }

                $customerName = mb_strtolower((string) $conversation->customer?->name);
                $externalId = mb_strtolower((string) $conversation->external_id);

                return str_contains($customerName, $search) || str_contains($externalId, $search);
            })
            ->values();

        $perPage = 12;
        $page = max(1, $request->integer('page', 1));

        return new LengthAwarePaginator(
            $filtered->slice(($page - 1) * $perPage, $perPage)->values(),
            $filtered->count(),
            $perPage,
            $page,
            [
                'path' => $workspace instanceof Workspace
                    ? route('worker.inbox.index', $workspace)
                    : route('worker.inbox.home'),
                'query' => $request->except('page'),
            ],
        );
    }

    private function prepareActiveConversation(
        Request $request,
        Workspace $workspace,
        ?Conversation $activeConversation,
    ): ?Conversation {
        if (! $activeConversation instanceof Conversation) {
            return null;
        }

        $metadata = is_array($activeConversation->metadata) ? $activeConversation->metadata : [];
        if ((int) ($metadata['unread_count'] ?? 0) > 0) {
            $metadata['unread_count'] = 0;
            Conversation::query()
                ->whereKey($activeConversation->id)
                ->update(['metadata' => $metadata]);
            $activeConversation->setAttribute('metadata', $metadata);
            $activeConversation->syncOriginalAttribute('metadata');
        }

        $activeConversation->setAttribute('display_channel', $this->displayChannel($activeConversation));
        $activeConversation->setRelation(
            'messages',
            $activeConversation->messages->sortBy('created_at')->values(),
        );

        $customer = $activeConversation->customer;
        if ($customer !== null) {
            $customer->setRelation('notes', new Collection);
            $customer->setRelation('tags', new Collection);
            $customer->setRelation('orders', new Collection);
        }

        $request->session()->put('inbox.last_conversation.'.$workspace->id, $activeConversation->id);

        return $activeConversation;
    }

    private function matchesHandoffFilter(Conversation $conversation, string $handoff, ?int $userId): bool
    {
        $status = $conversation->handoff_status instanceof ConversationHandoffStatus
            ? $conversation->handoff_status->value
            : (string) ($conversation->handoff_status ?? 'none');

        return match ($handoff) {
            'ai' => (bool) $conversation->ai_enabled && $status === 'none',
            'handoff' => in_array($status, ['requested', 'queued', 'assigned', 'active'], true),
            'queued' => $status === 'queued',
            'assigned_me' => $userId !== null
                && (int) $conversation->assigned_user_id === $userId
                && in_array($status, ['assigned', 'active'], true),
            'active' => $status === 'active',
            'resolved' => $status === 'resolved',
            default => true,
        };
    }

    private function displayChannel(Conversation $conversation): string
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $channel = $metadata['channel_source'] ?? $conversation->channel ?? 'manual';
        $normalized = strtolower(trim((string) $channel));

        return match ($normalized) {
            'facebook', 'messenger', 'facebook-messenger' => 'facebook_messenger',
            'ig' => 'instagram',
            default => $normalized !== '' ? $normalized : 'manual',
        };
    }

    private function normalizeChannelFilter(string $channel): ?string
    {
        $normalized = strtolower(trim($channel));

        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'facebook', 'messenger', 'facebook-messenger' => 'facebook_messenger',
            'ig' => 'instagram',
            default => $normalized,
        };
    }

    private function chatWorkspace(?User $worker): ?Workspace
    {
        if (! $worker instanceof User) {
            return null;
        }

        $workspaceId = WorkerEngagement::withoutGlobalScopes()
            ->whereHas('workerProfile', function (Builder $query) use ($worker): void {
                $query->where('user_id', $worker->id);
            })
            ->latest('id')
            ->value('workspace_id');

        if ($workspaceId === null) {
            return null;
        }

        return Workspace::query()->find($workspaceId);
    }

    private function canSendMessage(
        ?User $worker,
        Workspace $workspace,
        ?Conversation $conversation,
    ): bool {
        if (! $worker instanceof User || $conversation === null) {
            return false;
        }

        return $this->authorization->workerCanInWorkspace(
            $worker,
            $workspace,
            WorkerInboxService::SEND_CAPABILITY,
        );
    }
}
