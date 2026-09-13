<?php

namespace App\Http\Controllers\Api\Cashier\V1;

use App\Http\Controllers\Api\Cashier\CashierController;
use App\Http\Controllers\Api\Cashier\Concerns\AuthorizesCashier;
use App\Http\Controllers\Api\Cashier\Concerns\ResolvesCashierWorkspace;
use App\Services\Feature\FeatureAccessService;
use App\Services\Pos\PosDeviceRegistry;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DeviceController extends CashierController
{
    use AuthorizesCashier;
    use ResolvesCashierWorkspace;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly PosDeviceRegistry $devices,
        private readonly FeatureAccessService $featureAccessService,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $this->authorizeCashier($request, $workspace);

        if (! $this->featureAccessService->workspaceHasFeature($workspace, 'pos')) {
            return $this->fail('الكاشير غير متاح في باقتك الحالية', 403, meta: [
                'pos_enabled' => false,
                'plans_url' => url('/workspace/billing'),
            ]);
        }

        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $device = $this->devices->register(
                $workspace,
                $user,
                $data['device_id'],
                $data['name'] ?? null,
                $data['platform'] ?? null,
            );
        } catch (HttpException $e) {
            return $this->fail($e->getMessage(), $e->getStatusCode());
        }

        return $this->ok([
            'device_id' => $device->device_id,
            'workspace_id' => (int) $device->workspace_id,
            // account_id = authenticated cashier user bound to this device.
            'account_id' => (int) $device->user_id,
            'user_id' => (int) $device->user_id,
            'name' => $device->name,
            'platform' => $device->platform,
            'registered_at' => optional($device->registered_at)?->toIso8601String(),
            'last_seen_at' => optional($device->last_seen_at)?->toIso8601String(),
        ]);
    }
}
