<?php

namespace App\Http\Controllers\Download;

use App\Models\OrderItem;
use App\Services\Product\DigitalDownloadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DigitalDownloadController
{
    public function __construct(
        private readonly DigitalDownloadService $digitalDownloadService,
    ) {}

    public function __invoke(Request $request, OrderItem $orderItem): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $product = $this->digitalDownloadService->resolveDigitalProduct($orderItem);
        $disk = $product->digital_asset_disk ?: 'local';
        $path = (string) $product->digital_asset_path;

        abort_unless($path !== '' && Storage::disk($disk)->exists($path), 404);

        $order = $orderItem->order;
        if ($order) {
            $metadata = is_array($order->metadata) ? $order->metadata : [];
            $downloads = is_array($metadata['digital_downloads'] ?? null) ? $metadata['digital_downloads'] : [];
            $entry = is_array($downloads[$orderItem->id] ?? null) ? $downloads[$orderItem->id] : ['count' => 0];
            $entry['count'] = ((int) ($entry['count'] ?? 0)) + 1;
            $entry['last_downloaded_at'] = now()->toIso8601String();
            $downloads[$orderItem->id] = $entry;
            $metadata['digital_downloads'] = $downloads;
            $order->update(['metadata' => $metadata]);
        }

        $filename = basename($path);

        return Storage::disk($disk)->download($path, $filename);
    }
}
