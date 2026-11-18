<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Models\ChatCatalogItem;
use App\Services\AI\AiMediaStagingService;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Support\Chat\ArabicSearchNormalizer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Inbox agent tool to send an official product image to the customer in chat.
 */
final class SendProductImageTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly AiMediaStagingService $stagingService,
        private readonly ArabicSearchNormalizer $normalizer = new ArabicSearchNormalizer,
    ) {}

    public function name(): string
    {
        return 'send_product_image';
    }

    public function description(): string
    {
        return 'Send the official product image/photo to the customer in the chat when they ask to see a picture or photo of a product. If the item has an image in the catalog, it will be attached and sent to the conversation. If no image exists, reports that no image is available.';
    }

    public function securityProfile(): InboxAgentToolSecurityProfile
    {
        return new InboxAgentToolSecurityProfile(
            riskLevel: InboxAgentToolRiskLevel::Low,
            accessMode: InboxAgentToolAccessMode::Write,
            requiresConfirmation: false,
            maxCallsPerRun: 3,
        );
    }

    public function inputSchema(): array
    {
        return [
            'item_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Catalog item ID to send image for.',
            ],
            'product_name' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Product name or search keyword if item ID is not known.',
            ],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $itemId = (int) ($arguments['item_id'] ?? $arguments['product_id'] ?? 0);
        $productName = trim((string) ($arguments['product_name'] ?? $arguments['query'] ?? ''));

        try {
            $item = $this->resolveItem($context, $itemId, $productName);

            if (! $item) {
                return InboxAgentToolExecutionResult::success(
                    $this->name(),
                    'send_product_image',
                    [
                        'image_attached' => false,
                        'has_image' => false,
                        'item_found' => false,
                        'message' => 'لم يتم العثور على المنتج المطلوب في الكتالوج.',
                    ],
                );
            }

            $imagePath = $item->primaryImagePath();
            if ($imagePath === null || trim($imagePath) === '') {
                return InboxAgentToolExecutionResult::success(
                    $this->name(),
                    'send_product_image',
                    [
                        'image_attached' => false,
                        'has_image' => false,
                        'item_found' => true,
                        'item_id' => (int) $item->id,
                        'product_name' => (string) $item->name,
                        'message' => 'هذا المنتج لا يحتوي على صورة متوفرة حالياً في الكتالوج.',
                    ],
                );
            }

            $imagePath = ltrim(trim($imagePath), '/');
            $diskName = $item->primaryImageDisk();
            $disk = Storage::disk($diskName);

            if (! $disk->exists($imagePath)) {
                if (Storage::disk('public')->exists($imagePath)) {
                    $diskName = 'public';
                    $disk = Storage::disk('public');
                } elseif (Storage::disk('local')->exists($imagePath)) {
                    $diskName = 'local';
                    $disk = Storage::disk('local');
                }
            }

            $sizeBytes = $disk->exists($imagePath) ? (int) $disk->size($imagePath) : 0;
            $mimeType = $disk->exists($imagePath) ? ($disk->mimeType($imagePath) ?: 'image/jpeg') : 'image/jpeg';
            $originalName = basename($imagePath) ?: ($item->name.'.jpg');

            $this->stagingService->stageAttachment(
                workspaceId: (int) $context->workspace->id,
                conversationId: (int) $context->conversation->id,
                attachmentData: [
                    'item_id' => (int) $item->id,
                    'product_name' => (string) $item->name,
                    'disk' => $diskName,
                    'path' => $imagePath,
                    'original_name' => $originalName,
                    'mime_type' => $mimeType,
                    'size_bytes' => $sizeBytes,
                ],
            );

            Log::info('ai.tools.send_product_image.staged', [
                'workspace_id' => $context->workspace->id,
                'conversation_id' => $context->conversation->id,
                'item_id' => $item->id,
                'product_name' => $item->name,
                'path' => $imagePath,
            ]);

            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'send_product_image',
                [
                    'image_attached' => true,
                    'has_image' => true,
                    'item_found' => true,
                    'item_id' => (int) $item->id,
                    'product_name' => (string) $item->name,
                    'message' => 'تم إرفاق صورة المنتج بنجاح وسيتم إرسالها للعميل في المحادثة.',
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('ai.tools.send_product_image.failed', [
                'workspace_id' => $context->workspace->id,
                'conversation_id' => $context->conversation->id,
                'error' => $exception->getMessage(),
            ]);

            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'send_product_image',
                'execution_error',
                'تعذر إرفاق صورة المنتج: '.$exception->getMessage(),
            );
        }
    }

    private function resolveItem(InboxAgentContext $context, int $itemId, string $productName): ?ChatCatalogItem
    {
        $baseQuery = ChatCatalogItem::withoutGlobalScopes()
            ->where('workspace_id', $context->workspace->id)
            ->where('is_active', true)
            ->where('is_visible', true);

        if ($itemId > 0) {
            $item = (clone $baseQuery)->find($itemId);
            if ($item) {
                return $item;
            }
        }

        if ($productName !== '') {
            $item = (clone $baseQuery)->where('name', 'like', "%{$productName}%")->first();
            if ($item) {
                return $item;
            }

            // Fuzzy normalized match
            $normalizedSearch = $this->normalizer->normalize($productName);
            $items = (clone $baseQuery)->get();
            foreach ($items as $candidate) {
                $candidateNorm = $this->normalizer->normalize((string) $candidate->name);
                if (str_contains($candidateNorm, $normalizedSearch) || str_contains($normalizedSearch, $candidateNorm)) {
                    return $candidate;
                }
            }
        }

        // Fallback: check customer message content if no name was provided
        $customerContent = trim((string) ($context->message->content ?? ''));
        if ($customerContent !== '') {
            $items = (clone $baseQuery)->get();
            foreach ($items as $candidate) {
                $candidateNorm = $this->normalizer->normalize((string) $candidate->name);
                $contentNorm = $this->normalizer->normalize($customerContent);
                if ($candidateNorm !== '' && str_contains($contentNorm, $candidateNorm)) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
