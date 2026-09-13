<?php

namespace App\Http\Controllers\Api\Cashier\V1;

use App\Http\Controllers\Api\Cashier\CashierController;
use App\Http\Controllers\Api\Cashier\Concerns\AuthorizesCashier;
use App\Http\Controllers\Api\Cashier\Concerns\ResolvesCashierWorkspace;
use App\Http\Resources\Mobile\UserResource;
use App\Http\Resources\Mobile\WorkspaceResource;
use App\Models\User;
use App\Services\Cashier\CashierGoogleBrowserLogin;
use App\Services\Feature\FeatureAccessService;
use App\Services\Mobile\MobileAuthService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use RuntimeException;
use Throwable;

class AuthController extends CashierController
{
    use AuthorizesCashier;
    use ResolvesCashierWorkspace;

    public function __construct(
        private readonly MobileAuthService $mobileAuthService,
        private readonly FeatureAccessService $featureAccessService,
        private readonly WorkspaceContext $workspaceContext,
        private readonly CashierGoogleBrowserLogin $googleBrowserLogin,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required_without_all:phone,email_or_phone', 'nullable', 'string'],
            'phone' => ['required_without_all:email,email_or_phone', 'nullable', 'string'],
            'email_or_phone' => ['required_without_all:email,phone', 'nullable', 'string'],
            'password' => ['required', 'string'],
            'workspace_id' => ['nullable', 'integer'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'device_type' => ['nullable', 'string', 'max:32'],
        ]);

        $identifier = trim((string) ($validated['email_or_phone'] ?? $validated['email'] ?? $validated['phone'] ?? ''));
        if ($identifier === '') {
            return $this->fail('يرجى إدخال البريد الإلكتروني أو رقم الجوال.', 422);
        }

        try {
            $result = $this->mobileAuthService->loginWithPassword(
                emailOrPhone: $identifier,
                password: $validated['password'],
                workspaceId: isset($validated['workspace_id']) ? (int) $validated['workspace_id'] : null,
                device: [
                    'device_name' => $validated['device_name'] ?? 'كاشير حاسم',
                    'device_type' => $validated['device_type'] ?? 'cashier',
                    'user_agent' => $request->userAgent(),
                    'ip_address' => $request->ip(),
                ],
            );
        } catch (AuthenticationException) {
            return $this->fail('بيانات الدخول غير صحيحة.', 401);
        } catch (ModelNotFoundException $exception) {
            return $this->fail($exception->getMessage(), 404);
        }

        return $this->loginEnvelope($result, 'تم تسجيل الدخول بنجاح.');
    }

    public function social(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', 'in:google,facebook'],
            'access_token' => ['required', 'string'],
            'workspace_id' => ['nullable', 'integer'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'device_type' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $result = $this->mobileAuthService->loginWithSocial(
                provider: $validated['provider'],
                accessToken: $validated['access_token'],
                workspaceId: isset($validated['workspace_id']) ? (int) $validated['workspace_id'] : null,
                device: [
                    'device_name' => $validated['device_name'] ?? 'كاشير حاسم',
                    'device_type' => $validated['device_type'] ?? 'cashier',
                    'user_agent' => $request->userAgent(),
                    'ip_address' => $request->ip(),
                ],
            );
        } catch (AuthenticationException $exception) {
            return $this->fail($exception->getMessage() ?: 'فشل تسجيل الدخول الاجتماعي.', 401);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        } catch (ModelNotFoundException $exception) {
            return $this->fail($exception->getMessage(), 404);
        }

        return $this->loginEnvelope($result, 'تم تسجيل الدخول بنجاح.');
    }

    public function googleStart(): JsonResponse
    {
        try {
            return $this->ok($this->googleBrowserLogin->start());
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        } catch (Throwable) {
            return $this->fail(
                'تعذر بدء تسجيل Google. تحقق من GOOGLE_CLIENT_ID و GOOGLE_CLIENT_SECRET و GOOGLE_REDIRECT_URI في .env.',
                422,
            );
        }
    }

    public function googleStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ticket' => ['required', 'string', 'uuid'],
        ]);

        $payload = $this->googleBrowserLogin->status($validated['ticket']);
        if ($payload['status'] === 'expired') {
            return $this->fail((string) $payload['error'], 404);
        }

        return $this->ok($payload);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            return $this->ok(message: 'تم إرسال رابط إعادة تعيين كلمة المرور إلى بريدك الإلكتروني.');
        }

        if ($status === Password::RESET_THROTTLED) {
            return $this->fail('يرجى الانتظار قبل طلب رابط جديد.', 429);
        }

        return $this->fail('تعذر إرسال رابط إعادة التعيين. تحقق من البريد الإلكتروني.', 422);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request): void {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return $this->ok(message: 'تم إعادة تعيين كلمة المرور بنجاح.');
        }

        return $this->fail('تعذر إعادة تعيين كلمة المرور. تحقق من الرابط أو البريد.', 422);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            $this->mobileAuthService->logoutCurrent($user, $user->currentAccessToken());
        }

        return $this->ok(message: 'تم تسجيل الخروج بنجاح.');
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $workspaces = $user->workspaces()
            ->wherePivot('status', 'active')
            ->get();

        $workspaceId = $user->currentAccessToken()?->workspace_id;
        $workspace = $workspaceId
            ? $workspaces->firstWhere('id', $workspaceId)
            : $workspaces->first();

        return $this->ok([
            'user' => new UserResource($user),
            'workspace' => $workspace ? new WorkspaceResource($workspace) : null,
            'workspaces' => $this->cashierWorkspacesPayload($workspaces),
            'permissions' => $workspace ? $this->permissionMap($user, $workspace) : [],
            'pos_enabled' => $workspace
                ? $this->featureAccessService->workspaceHasFeature($workspace, 'pos')
                : false,
            'entitlements' => $workspace
                ? $this->featureAccessService->entitlementsSnapshot($workspace)
                : null,
        ]);
    }

    /**
     * @param  array{user:User,workspace:\App\Models\Workspace|null,workspaces:\Illuminate\Support\Collection,token:\Laravel\Sanctum\NewAccessToken}  $result
     */
    private function loginEnvelope(array $result, string $message): JsonResponse
    {
        $workspace = $result['workspace'];
        $permissions = $workspace
            ? $this->permissionMap($result['user'], $workspace)
            : [];
        $entitlements = $workspace
            ? $this->featureAccessService->entitlementsSnapshot($workspace)
            : null;
        $posEnabled = $workspace
            ? $this->featureAccessService->workspaceHasFeature($workspace, 'pos')
            : false;

        return $this->ok([
            'token' => $result['token']->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => optional($result['token']->accessToken->expires_at)?->toIso8601String(),
            'user' => new UserResource($result['user']),
            'workspace' => $workspace ? new WorkspaceResource($workspace) : null,
            'workspaces' => $this->cashierWorkspacesPayload($result['workspaces']),
            'permissions' => $permissions,
            'entitlements' => $entitlements,
            'pos_enabled' => $posEnabled,
        ], message: $message);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\Workspace>  $workspaces
     * @return list<array<string, mixed>>
     */
    private function cashierWorkspacesPayload($workspaces): array
    {
        return $workspaces
            ->map(function ($workspace): array {
                return [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'type' => $workspace->type,
                    'pos_enabled' => $this->featureAccessService->workspaceHasFeature($workspace, 'pos'),
                ];
            })
            ->values()
            ->all();
    }
}
