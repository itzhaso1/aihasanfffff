<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WhatsApp\WhatsAppEmbeddedSignupService;
use App\Support\ChannelConnect\ChannelConnectAttemptStore;
use App\Support\ChannelConnect\ChannelConnectReturn;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class WhatsAppMobileSignupController extends Controller
{
    public function __construct(
        private readonly ChannelConnectAttemptStore $attemptStore,
        private readonly WhatsAppEmbeddedSignupService $embeddedSignupService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function show(string $token): View
    {
        $attempt = $this->attemptStore->get('whatsapp_signup', $token);
        if ($attempt === null) {
            return view('mobile.channel-connect.whatsapp-expired');
        }

        return view('mobile.channel-connect.whatsapp', [
            'token' => $token,
            'metaAppId' => (string) config('whatsapp.meta_app_id'),
            'metaConfigId' => (string) config('whatsapp.embedded_signup_config_id'),
            'graphApiVersion' => (string) config('whatsapp.api_version', 'v20.0'),
            'completeUrl' => route('mobile.channels.whatsapp.complete', ['token' => $token]),
        ]);
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $attempt = $this->attemptStore->get('whatsapp_signup', $token);
        if ($attempt === null) {
            return response()->json([
                'success' => false,
                'message' => 'انتهت صلاحية محاولة الربط. أعد المحاولة من التطبيق.',
                'redirect' => ChannelConnectReturn::appUrl('whatsapp', 'error', 'انتهت صلاحية محاولة الربط. أعد المحاولة من التطبيق.'),
            ], 410);
        }

        $user = User::query()->find((int) ($attempt['user_id'] ?? 0));
        $workspace = Workspace::query()->find((int) ($attempt['workspace_id'] ?? 0));
        if (! $user || ! $workspace) {
            return response()->json([
                'success' => false,
                'message' => 'تعذر إتمام ربط واتساب.',
                'redirect' => ChannelConnectReturn::appUrl('whatsapp', 'error', 'تعذر إتمام ربط واتساب.'),
            ], 422);
        }

        $belongs = $user->workspaces()
            ->where('workspaces.id', $workspace->id)
            ->wherePivot('status', 'active')
            ->exists();
        if (! $belongs) {
            return response()->json([
                'success' => false,
                'message' => 'ليست لديك صلاحية إدارة هذه المساحة.',
                'redirect' => ChannelConnectReturn::appUrl('whatsapp', 'error', 'ليست لديك صلاحية إدارة هذه المساحة.', [
                    'workspace' => (int) $workspace->id,
                ]),
            ], 403);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:1024'],
            'session_info' => ['nullable', 'array'],
            'session_info.waba_id' => ['nullable', 'string', 'max:191'],
            'session_info.phone_number_id' => ['nullable', 'string', 'max:191'],
            'session_info.business_id' => ['nullable', 'string', 'max:191'],
        ]);

        $consumed = $this->attemptStore->pull('whatsapp_signup', $token);
        if ($consumed === null) {
            return response()->json([
                'success' => false,
                'message' => 'انتهت صلاحية محاولة الربط.',
                'redirect' => ChannelConnectReturn::appUrl('whatsapp', 'error', 'انتهت صلاحية محاولة الربط.', [
                    'workspace' => (int) $workspace->id,
                ]),
            ], 410);
        }

        try {
            $this->workspaceContext->runFor($workspace, function () use ($workspace, $validated): void {
                $this->embeddedSignupService->connectWorkspace($workspace, $validated);
            });
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'redirect' => ChannelConnectReturn::appUrl('whatsapp', 'error', $exception->getMessage(), [
                    'workspace' => (int) $workspace->id,
                ]),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'redirect' => ChannelConnectReturn::appUrl('whatsapp', 'success', 'تم ربط واتساب بنجاح.', [
                'workspace' => (int) $workspace->id,
            ]),
        ]);
    }
}
