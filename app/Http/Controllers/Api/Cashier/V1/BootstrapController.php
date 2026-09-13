<?php

namespace App\Http\Controllers\Api\Cashier\V1;

use App\Http\Controllers\Api\Cashier\CashierController;
use App\Http\Controllers\Api\Cashier\Concerns\AuthorizesCashier;
use App\Http\Controllers\Api\Cashier\Concerns\ResolvesCashierWorkspace;
use App\Http\Resources\Mobile\UserResource;
use App\Http\Resources\Mobile\WorkspaceResource;
use App\Services\Feature\FeatureAccessService;
use App\Services\Pos\PosOrderStatsService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BootstrapController extends CashierController
{
    use AuthorizesCashier;
    use ResolvesCashierWorkspace;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FeatureAccessService $featureAccessService,
        private readonly PosOrderStatsService $posOrderStatsService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $this->authorizeCashier($request, $workspace);

        $posEnabled = $this->featureAccessService->workspaceHasFeature($workspace, 'pos');
        $entitlements = $this->featureAccessService->entitlementsSnapshot($workspace);
        $permissions = $this->permissionMap($user, $workspace);
        $channelStats = $posEnabled
            ? $this->posOrderStatsService->channelCounts()
            : [
                'table' => 0,
                'takeaway' => 0,
                'delivery' => 0,
                'total' => 0,
                'open_table' => 0,
                'open_takeaway' => 0,
                'open_delivery' => 0,
                'open_total' => 0,
            ];

        return $this->ok([
            'app' => [
                'name' => 'كاشير حاسم',
                'api_version' => 'cashier/v1',
                'realtime_channel' => 'workspace.'.$workspace->id.'.pos',
                'polling' => [
                    'menu_orders_seconds' => 3,
                    'tables_seconds' => 3,
                ],
                'shifts_supported' => false,
                'offline' => [
                    'orders' => true,
                    'catalog_cache' => true,
                    'card_payments' => false,
                ],
            ],
            'user' => new UserResource($user),
            'workspace' => new WorkspaceResource($workspace),
            'pos_enabled' => $posEnabled,
            'entitlements' => $entitlements,
            'permissions' => $permissions,
            'settings' => [
                'tax_rate' => (float) data_get($workspace->settings ?? [], 'pos.tax_rate', 0),
                'currency' => data_get($workspace->settings ?? [], 'pos.currency', 'SAR'),
                // Web SoT key: pos.new_order_sound (PosSettingsController / CashierController).
                'sound_enabled' => (bool) data_get($workspace->settings ?? [], 'pos.new_order_sound', true),
                'enable_delivery' => (bool) data_get($workspace->settings ?? [], 'pos.enable_delivery', true),
            ],
            'channel_stats' => $channelStats,
            'plans_url' => url('/workspace/billing'),
        ]);
    }
}
