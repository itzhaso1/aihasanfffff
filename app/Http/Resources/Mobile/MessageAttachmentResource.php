<?php

namespace App\Http\Resources\Mobile;

use App\Models\MessageAttachment;
use App\Services\Mobile\MessageAttachmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MessageAttachment */
class MessageAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MessageAttachmentService $service */
        $service = app(MessageAttachmentService::class);

        $ready = $this->status === MessageAttachment::STATUS_STORED;

        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'status' => $this->status,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => (int) $this->size_bytes,
            'duration_ms' => $this->duration_ms,
            'download_url' => $ready ? $service->downloadUrl($this->resource) : null,
            'preview_url' => $ready && $this->kind === 'image' ? $service->downloadUrl($this->resource) : null,
        ];
    }
}
