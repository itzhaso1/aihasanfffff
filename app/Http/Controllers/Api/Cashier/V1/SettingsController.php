<?php

namespace App\Http\Controllers\Api\Cashier\V1;

use App\Http\Controllers\Api\Cashier\CashierController;
use App\Http\Controllers\Api\Cashier\Concerns\AuthorizesCashier;
use App\Http\Controllers\Api\Cashier\Concerns\ResolvesCashierWorkspace;
use App\Services\Feature\FeatureAccessService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingsController extends CashierController
{
    use AuthorizesCashier;
    use ResolvesCashierWorkspace;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FeatureAccessService $featureAccessService,
    ) {}

    /**
     * Mirror Web: PosSettingsController@updatePosSettings
     */
    public function updatePos(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace, 'menu.manage');
        $this->ensurePos($workspace);

        $validated = $request->validate([
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'new_order_sound' => ['nullable', 'boolean'],
            'enable_delivery' => ['nullable', 'boolean'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $settings = (array) ($workspace->settings ?? []);
        data_set($settings, 'pos.tax_rate', round((float) ($validated['tax_rate'] ?? 0), 2));
        data_set($settings, 'pos.new_order_sound', (bool) ($validated['new_order_sound'] ?? false));
        data_set($settings, 'pos.enable_delivery', (bool) ($validated['enable_delivery'] ?? false));
        if (! empty($validated['currency'])) {
            data_set($settings, 'pos.currency', strtoupper($validated['currency']));
        }

        $workspace->update(['settings' => $settings]);
        $workspace->refresh();

        return $this->ok(
            $this->posSettingsPayload($workspace),
            message: 'تم تحديث إعدادات الكاشير بنجاح.'
        );
    }

    /**
     * Mirror Web: PosSettingsController@updateMenuSlider
     */
    public function updateMenuSlider(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace, 'menu.manage');
        $this->ensurePos($workspace);

        $validated = $request->validate([
            'slider_images' => ['nullable', 'array', 'max:8'],
            'slider_images.*' => ['nullable', 'image', 'max:5120'],
            'remove_slider_images' => ['nullable', 'array'],
            'remove_slider_images.*' => ['string'],
        ]);

        $settings = (array) ($workspace->settings ?? []);
        $existingImages = collect(data_get($settings, 'pos.menu_slider_images', []))
            ->filter(fn ($path): bool => is_string($path) && $path !== '')
            ->values();

        $removeImages = collect($validated['remove_slider_images'] ?? [])
            ->filter(fn ($path): bool => is_string($path) && $path !== '')
            ->values();

        $imagesToKeep = $existingImages
            ->reject(fn (string $path): bool => $removeImages->contains($path))
            ->values();

        $prefix = 'workspaces/'.$workspace->id.'/pos-slider/';
        foreach ($removeImages as $removePath) {
            if (str_starts_with($removePath, $prefix)) {
                Storage::disk('public')->delete($removePath);
            }
        }

        $uploadedPaths = collect();
        foreach (($request->file('slider_images') ?? []) as $file) {
            if (! $file) {
                continue;
            }

            $uploadedPaths->push($file->store($prefix, 'public'));
        }

        $maxImages = 8;
        $allowedUploadCount = max(0, $maxImages - $imagesToKeep->count());
        $acceptedUploads = $uploadedPaths->take($allowedUploadCount)->values();
        $overflowUploads = $uploadedPaths->slice($allowedUploadCount)->values();
        foreach ($overflowUploads as $overflowPath) {
            Storage::disk('public')->delete($overflowPath);
        }

        $finalImages = $imagesToKeep
            ->merge($acceptedUploads)
            ->filter()
            ->unique()
            ->take($maxImages)
            ->values()
            ->all();

        data_set($settings, 'pos.menu_slider_images', $finalImages);
        $workspace->update(['settings' => $settings]);
        $workspace->refresh();

        $urls = collect($finalImages)
            ->map(fn (string $path) => Storage::disk('public')->url($path))
            ->values()
            ->all();

        return $this->ok([
            'menu_slider_images' => array_values($finalImages),
            'menu_slider_image_urls' => $urls,
            'settings' => $this->posSettingsPayload($workspace),
        ], message: 'تم تحديث سلايدر المنيو بنجاح.');
    }

    /**
     * @return array<string, mixed>
     */
    private function posSettingsPayload(\App\Models\Workspace $workspace): array
    {
        $settings = (array) ($workspace->settings ?? []);

        return [
            'tax_rate' => (float) data_get($settings, 'pos.tax_rate', 0),
            'currency' => data_get($settings, 'pos.currency', 'SAR'),
            'sound_enabled' => (bool) data_get($settings, 'pos.new_order_sound', true),
            'new_order_sound' => (bool) data_get($settings, 'pos.new_order_sound', true),
            'enable_delivery' => (bool) data_get($settings, 'pos.enable_delivery', true),
            'menu_slider_images' => collect(data_get($settings, 'pos.menu_slider_images', []))
                ->filter(fn ($path): bool => is_string($path) && $path !== '')
                ->values()
                ->all(),
        ];
    }

    private function ensurePos(\App\Models\Workspace $workspace): void
    {
        if (! $this->featureAccessService->workspaceHasFeature($workspace, 'pos')) {
            throw new HttpResponseException(
                $this->fail('الكاشير غير متاح في باقتك الحالية', 403, meta: [
                    'pos_enabled' => false,
                    'plans_url' => url('/workspace/billing'),
                ])
            );
        }
    }
}
