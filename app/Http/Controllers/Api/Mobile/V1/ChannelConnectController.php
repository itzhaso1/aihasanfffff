<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Channel\ChannelConnectionService;
use App\Services\Feature\FeatureAccessService;
use App\Services\Meta\Facebook\FacebookConnectService;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ChannelConnectController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly ChannelConnectionService $connections,
        private readonly FacebookConnectService $facebookConnectService,
        private readonly WorkspaceAccess $workspaceAccess,
        private readonly FeatureAccessService $featureAccessService,
    ) {}

    public function start(Request $request, string $channel): JsonResponse
    {
        [$user, $workspace] = $this->authorizeChannelManage();

        if ($channel === ChannelConnectionService::CHANNEL_TELEGRAM) {
            $validated = $request->validate([
                'bot_token' => ['required', 'string', 'max:255'],
            ]);

            try {
                $connection = $this->connections->connectTelegram($workspace, $user, $validated['bot_token']);
            } catch (RuntimeException $exception) {
                return $this->fail($exception->getMessage(), 422);
            }

            $label = $connection->bot_username ? '@'.$connection->bot_username : 'Telegram bot';

            return $this->ok([
                'channel' => $channel,
                'mode' => 'bot_token',
                'status' => 'connected',
                'connected' => true,
                'display_name' => $label,
            ], message: 'تم ربط Telegram.');
        }

        try {
            $payload = $this->connections->start($workspace, $user, $channel);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->assertNoSecrets($payload);
    }

    public function disconnect(string $channel): JsonResponse
    {
        [, $workspace] = $this->authorizeChannelManage();

        try {
            $this->connections->disconnect($workspace, $channel);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok([
            'channel' => $channel,
            'status' => 'disconnected',
            'connected' => false,
        ], message: 'تم قطع اتصال القناة.');
    }

    public function facebookPages(Request $request): JsonResponse
    {
        [$user, $workspace] = $this->authorizeChannelManage();
        $attempt = trim((string) $request->query('attempt', ''));
        if ($attempt === '') {
            return $this->fail('محاولة اختيار الصفحة غير صالحة.', 422);
        }

        try {
            $pages = $this->facebookConnectService->pendingPages($attempt, $user, $workspace);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->assertNoSecrets([
            'channel' => ChannelConnectionService::CHANNEL_FACEBOOK,
            'status' => 'select_page',
            'pages' => $pages,
        ]);
    }

    public function selectFacebookPage(Request $request): JsonResponse
    {
        [$user, $workspace] = $this->authorizeChannelManage();
        $validated = $request->validate([
            'attempt' => ['required', 'string', 'max:128'],
            'page_id' => ['required', 'string', 'max:191'],
        ]);

        try {
            $connection = $this->facebookConnectService->completePageSelection(
                $validated['attempt'],
                $validated['page_id'],
                $user,
                $workspace,
            );
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok([
            'channel' => ChannelConnectionService::CHANNEL_FACEBOOK,
            'status' => 'connected',
            'connected' => true,
            'display_name' => $connection->display_name,
        ], message: 'تم ربط Facebook.');
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function authorizeChannelManage(): array
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = request()->user();
        abort_unless($user && $this->workspaceAccess->canManageChannels($user, $workspace), 403, 'ليست لديك صلاحية إدارة القنوات.');
        abort_unless($this->featureAccessService->hasFeature($user, $workspace, 'whatsapp'), 403, 'إدارة القنوات غير متاحة لهذه المساحة.');

        return [$user, $workspace];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertNoSecrets(array $payload): JsonResponse
    {
        $encoded = json_encode($payload) ?: '';
        foreach (['client_secret', 'app_secret', 'access_token', 'refresh_token', 'bot_token'] as $secret) {
            abort_if(str_contains(strtolower($encoded), $secret), 500, 'Refusing to expose channel secrets.');
        }

        return $this->ok($payload);
    }
}
