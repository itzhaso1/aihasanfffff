<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Http\Requests\Conversation\StoreConversationRequest;
use App\Http\Requests\Message\StoreMessageRequest;
use App\Jobs\ProcessAIResponse;
use App\Models\Conversation;
use App\Models\Customer;
use App\Services\Conversation\ConversationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConversationController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(private readonly ConversationService $conversationService) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Conversation::class);

        $search = $request->string('search')->toString();
        $channelFilter = $this->normalizeChannelFilter($request->string('channel')->toString());

        $conversations = Conversation::query()
            ->with([
                'customer',
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

                $query->where('channel', 'manual')
                    ->where('metadata->channel_source', $channelFilter);
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

        $activeConversationId = $request->integer('conversation');

        $activeConversation = null;
        if ($activeConversationId) {
            $activeConversation = Conversation::query()
                ->with([
                    'customer.notes' => fn ($query) => $query->latest()->limit(8),
                    'customer.tags',
                    'customer.orders' => fn ($query) => $query->latest()->limit(8),
                    'messages' => fn ($query) => $query->with('user')->latest()->limit(80),
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
        $payload['ai_enabled'] = (bool) ($payload['ai_enabled'] ?? true);

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

    public function update(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorize('update', $conversation);

        $payload = $request->validate([
            'status' => ['nullable', 'in:open,closed,archived'],
            'ai_enabled' => ['nullable', 'boolean'],
            'metadata_json' => ['nullable', 'string'],
        ]);

        $conversation->update([
            'status' => $payload['status'] ?? $conversation->status,
            'ai_enabled' => array_key_exists('ai_enabled', $payload) ? (bool) $payload['ai_enabled'] : $conversation->ai_enabled,
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

    public function storeMessage(StoreMessageRequest $request, Conversation $conversation): RedirectResponse
    {
        $this->authorize('update', $conversation);

        $payload = $request->validated();
        $payload['conversation_id'] = $conversation->id;
        $payload['customer_id'] = $payload['customer_id'] ?? $conversation->customer_id;
        $payload['message_type'] = $payload['message_type'] ?? 'text';
        $payload['metadata'] = $this->parseJsonField($request, 'metadata_json');

        $sentMessage = $this->conversationService->addMessage($conversation, $payload, $request->user());

        if ($sentMessage->direction === 'inbound' && $conversation->ai_enabled) {
            ProcessAIResponse::dispatch($conversation->id, $sentMessage->id);
        }

        return redirect()->route('workspace.conversations.index', [
            'conversation' => $conversation->id,
        ])->with('success', 'تم إرسال الرسالة.');
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
