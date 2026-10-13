<?php

namespace App\Http\Controllers\Workspace;

use App\Enums\ConversationHandoffStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Http\Requests\Conversation\StoreConversationRequest;
use App\Http\Requests\Conversation\UpdateConversationRequest;
use App\Http\Requests\Message\StoreMessageRequest;
use App\Jobs\ProcessAIResponse;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Conversation\ConversationAiEligibilityService;
use App\Services\Conversation\ConversationService;
use App\Services\Mobile\MessageAttachmentService;
use App\Services\Workforce\WorkforceInboxAttribution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConversationController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly ConversationService $conversationService,
        private readonly ConversationAiEligibilityService $aiEligibility,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Conversation::class);

        $search = $request->string('search')->toString();
        $channelFilter = $this->normalizeChannelFilter($request->string('channel')->toString());

        $handoffFilter = $request->string('handoff')->toString();

        $conversations = Conversation::query()
            ->with([
                'customer',
                'assignedUser:id,name',
                'assignedWorkerEngagement:id,workspace_id,worker_profile_id,status',
                'assignedWorkerEngagement.workerProfile:id,display_name',
                'aiEmployee:id,workspace_id,name,avatar_path,status',
                'messages' => fn ($query) => $query->latest()->limit(1),
            ])
            ->withCount('messages')
            ->when($channelFilter, function ($query, $channelFilter): void {
                if (in_array($channelFilter, ['whatsapp', 'web'], true)) {
                    $query->where('channel', $channelFilter);

                    return;
                }

                if ($channelFilter === 'manual') {
                    $query->where('channel', 'manual')
                        ->where(function ($manualQuery): void {
                            $manualQuery
                                ->whereNull('metadata->channel_source')
                                ->orWhere('metadata->channel_source', 'manual');
                        });

                    return;
                }

                $query->where(function ($channelQuery) use ($channelFilter): void {
                    $channelQuery
                        ->where('channel', $channelFilter)
                        ->orWhere(function ($legacyQuery) use ($channelFilter): void {
                            $legacyQuery
                                ->where('channel', 'manual')
                                ->where('metadata->channel_source', $channelFilter);
                        });
                });
            })
            ->when($search, function ($query, $search): void {
                $query->where(function ($innerQuery) use ($search): void {
                    $innerQuery
                        ->where('external_id', 'like', '%'.$search.'%')
                        ->orWhereHas('customer', function ($customerQuery) use ($search): void {
                            $customerQuery
                                ->where('name', 'like', '%'.$search.'%')
                                ->orWhere('phone', 'like', '%'.$search.'%')
                                ->orWhere('email', 'like', '%'.$search.'%');
                        });
                });
            })
            ->when($request->filled('status') && in_array($request->string('status')->toString(), ['open', 'closed', 'archived'], true), function ($query) use ($request): void {
                $query->where('status', $request->string('status')->toString());
            })
            ->when($request->boolean('unread'), function ($query): void {
                $query->where('metadata->unread_count', '>', 0);
            })
            ->when($handoffFilter === 'ai', function ($query): void {
                $query->where('ai_enabled', true)->where('handoff_status', 'none');
            })
            ->when($handoffFilter === 'handoff', function ($query): void {
                $query->whereIn('handoff_status', ['requested', 'queued', 'assigned', 'active']);
            })
            ->when($handoffFilter === 'queued', fn ($query) => $query->where('handoff_status', 'queued'))
            ->when($handoffFilter === 'assigned_me', function ($query) use ($request): void {
                $query->where('assigned_user_id', $request->user()?->id)
                    ->whereIn('handoff_status', ['assigned', 'active']);
            })
            ->when($handoffFilter === 'active', fn ($query) => $query->where('handoff_status', 'active'))
            ->when($handoffFilter === 'resolved', fn ($query) => $query->where('handoff_status', 'resolved'))
            ->latest('last_message_at')
            ->paginate(12)
            ->withQueryString();

        $conversations->setCollection(
            $conversations->getCollection()
                ->map(function (Conversation $conversation) {
                    $displayChannel = $this->resolveDisplayChannel($conversation);
                    $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
                    $conversation->setAttribute('display_channel', $displayChannel);
                    $conversation->setAttribute('unread_count', (int) ($metadata['unread_count'] ?? 0));

                    return $conversation;
                })
                ->values()
        );

        $workspaceId = (int) $this->currentWorkspace()->id;
        $sessionKey = 'inbox.last_conversation.'.$workspaceId;

        $activeConversationId = $request->integer('conversation');
        if (! $activeConversationId && ! $request->has('conversation')) {
            $remembered = (int) $request->session()->get($sessionKey, 0);
            if ($remembered > 0) {
                $activeConversationId = $remembered;
            }
        }

        $activeConversation = null;
        if ($activeConversationId) {
            $activeConversation = Conversation::query()
                ->with([
                    'customer.notes' => fn ($query) => $query->latest()->limit(8),
                    'customer.tags',
                    'customer.orders' => fn ($query) => $query->latest()->limit(8),
                    'assignedUser:id,name',
                    'assignedWorkerEngagement:id,workspace_id,worker_profile_id,status',
                    'assignedWorkerEngagement.workerProfile:id,display_name',
                    'aiEmployee:id,workspace_id,name,avatar_path,status',
                    'messages' => fn ($query) => $query->with([
                        'user',
                        'workerEngagement.workerProfile:id,display_name',
                        'attachments',
                    ])->latest()->limit(80),
                ])
                ->find($activeConversationId);

            if ($activeConversation) {
                $metadata = is_array($activeConversation->metadata) ? $activeConversation->metadata : [];
                if ((int) ($metadata['unread_count'] ?? 0) > 0) {
                    $metadata['unread_count'] = 0;
                    // Update only metadata via query to avoid persisting virtual attributes.
                    Conversation::query()
                        ->whereKey($activeConversation->id)
                        ->update(['metadata' => $metadata]);
                    $activeConversation->setAttribute('metadata', $metadata);
                    $activeConversation->syncOriginalAttribute('metadata');
                }

                $activeConversation->setAttribute('display_channel', $this->resolveDisplayChannel($activeConversation));

                $activeConversation->setRelation(
                    'messages',
                    $activeConversation->messages->sortBy('created_at')->values()
                );

                $request->session()->put($sessionKey, $activeConversation->id);
            } elseif ($activeConversationId > 0) {
                $request->session()->forget($sessionKey);
            }
        }

        return view('workspace.conversations.index', [
            'conversations' => $conversations,
            'activeConversation' => $activeConversation,
            'channelFilter' => $channelFilter,
            'availableChannels' => [
                'whatsapp' => 'WhatsApp',
                'instagram' => 'Instagram',
                'facebook_messenger' => 'Facebook Messenger',
                'tiktok' => 'TikTok',
                'telegram' => 'Telegram',
                'email' => 'Email',
                'web' => 'Web',
                'manual' => 'Manual',
            ],
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Conversation::class);

        return view('workspace.conversations.create', [
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreConversationRequest $request): RedirectResponse
    {
        $this->authorize('create', Conversation::class);

        $payload = $request->validated();
        $payload['metadata'] = $this->parseJsonField($request, 'metadata_json');
        $payload['status'] = $payload['status'] ?? 'open';
        $payload['ai_enabled'] = $request->boolean('ai_enabled', true);

        $conversation = $this->conversationService->create($payload);

        return redirect()->route('workspace.conversations.index', [
            'conversation' => $conversation->id,
        ])->with('success', 'تم إنشاء المحادثة.');
    }

    public function edit(Conversation $conversation): RedirectResponse
    {
        $this->authorize('update', $conversation);

        return redirect()->route('workspace.conversations.index', [
            'conversation' => $conversation->id,
        ]);
    }

    public function update(UpdateConversationRequest $request, Conversation $conversation): RedirectResponse
    {
        $payload = $request->validated();

        $conversation->update([
            'status' => $payload['status'] ?? $conversation->status,
            'ai_enabled' => $request->exists('ai_enabled')
                ? $request->boolean('ai_enabled')
                : $conversation->ai_enabled,
            'metadata' => $this->parseJsonField($request, 'metadata_json', $conversation->metadata ?? []),
        ]);

        return redirect()->route('workspace.conversations.index', [
            'conversation' => $conversation->id,
        ])->with('success', 'تم تحديث المحادثة.');
    }

    public function destroy(Conversation $conversation): RedirectResponse
    {
        $this->authorize('delete', $conversation);
        $conversation->delete();

        return redirect()->route('workspace.conversations.index')->with('success', 'تم حذف المحادثة.');
    }

    public function storeMessage(StoreMessageRequest $request, Conversation $conversation): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $conversation);

        $payload = $request->validated();
        $payload['conversation_id'] = $conversation->id;
        $payload['customer_id'] = $payload['customer_id'] ?? $conversation->customer_id;
        $payload['message_type'] = $payload['message_type'] ?? 'text';
        $payload['metadata'] = $this->parseJsonField($request, 'metadata_json');

        $sentMessage = $this->conversationService->addMessage($conversation, $payload, $request->user());
        $conversation = $conversation->fresh() ?? $conversation;

        if ($sentMessage->direction === 'inbound'
            && $this->aiEligibility->shouldDispatchAiJob($conversation, $sentMessage)) {
            ProcessAIResponse::dispatch($conversation->id, $sentMessage->id, (int) $conversation->workspace_id);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->serializeMessageForInbox($sentMessage),
                'conversation' => [
                    'id' => $conversation->id,
                    'ai_enabled' => (bool) $conversation->ai_enabled,
                    'handoff_status' => $conversation->handoff_status instanceof ConversationHandoffStatus
                        ? $conversation->handoff_status->value
                        : ($conversation->handoff_status ?? 'none'),
                ],
            ]);
        }

        return redirect()->route('workspace.conversations.index', [
            'conversation' => $conversation->id,
        ])->with('success', 'تم إرسال الرسالة.');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessageForInbox(Message $message): array
    {
        $message->loadMissing('attachments');

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'direction' => $message->direction,
            'message_type' => $message->message_type,
            'content' => $message->content,
            'ai_generated' => (bool) $message->ai_generated,
            'participant_type' => WorkforceInboxAttribution::typeForMessage($message)?->value,
            'worker_engagement_id' => $message->worker_engagement_id,
            'created_at' => optional($message->created_at)?->toIso8601String(),
            'attachments' => $message->attachments->map(function ($attachment): array {
                $ready = ($attachment->status ?? 'stored') === MessageAttachment::STATUS_STORED;

                return [
                    'id' => $attachment->id,
                    'kind' => $attachment->kind,
                    'original_name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'size_bytes' => $attachment->size_bytes,
                    'status' => $attachment->status,
                    'download_url' => $ready
                        ? app(MessageAttachmentService::class)->workspaceDownloadUrl($attachment)
                        : null,
                ];
            })->values()->all(),
        ];
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

    private function resolveDisplayChannel(Conversation $conversation): string
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
}
