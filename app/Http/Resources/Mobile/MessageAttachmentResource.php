<?php

namespace App\Http\Resources\Mobile;

use App\Services\Mobile\MessageAttachmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MessageAttachment */
class MessageAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MessageAttachmentService $service */
        $service = app(MessageAttachmentService::class);

        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => (int) $this->size_bytes,
            'download_url' => $service->downloadUrl($this->resource),
        ];
    }
}
