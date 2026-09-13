<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Exceptions\Api\ApiErrorCode;
use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Cashier\CashierGoogleBrowserLogin;
use App\Services\Feature\FeatureAccessService;
use App\Services\Finance\Api\FinanceClientPresenter;
use App\Services\Mobile\MobileAuthService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;
use Throwable;

class AuthController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly MobileAuthService $mobileAuthService,
        private readonly FeatureAccessService $featureAccessService,
        private readonly FinanceClientPresenter $presenter,
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
            return $this->fail('يرجى إدخال البريد الإلكتروني أو رقم الجوال.', ApiErrorCode::ValidationFailed, 422);
        }

        try {
            $result = $this->mobileAuthService->loginWithPassword(
                emailOrPhone: $identifier,
                password: $validated['password'],
                workspaceId: isset($validated['workspace_id']) ? (int) $validated['workspace_id'] : null,
                device: [
                    'device_name' => $validated['device_name'] ?? 'حاسم للمالية',
                    'device_type' => $validated['device_type'] ?? 'finance',
                    'user_agent' => $request->userAgent(),
                    'ip_address' => $request->ip(),
                ],
            );
        } catch (AuthenticationException) {
            return $this->fail('بيانات الدخول غير صحيحة.', ApiErrorCode::Unauthorized, 401);
        } catch (ModelNotFoundException $exception) {
            return $this->fail($exception->getMessage(), ApiErrorCode::NotFound, 404);
        }

        return $this->sessionEnvelope($result, 'تم تسجيل الدخول بنجاح.');
    }

    public function google(Request $request): JsonResponse
    {
        $request->merge(['provider' => 'google']);

        return $this->social($request);
    }

    public function social(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', 'in:google'],
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
                    'device_name' => $validated['device_name'] ?? 'حاسم للمالية',
                    'device_type' => $validated['device_type'] ?? 'finance',
                    'user_agent' => $request->userAgent(),
                    'ip_address' => $request->ip(),
                ],
            );
        } catch (ModelNotFoundException $exception) {
            return $this->fail($exception->getMessage(), ApiErrorCode::NotFound, 404);
        } catch (AuthenticationException|RuntimeException|Throwable) {
            return $this->fail('تعذر التحقق من حساب Google.', ApiErrorCode::Unauthorized, 401);
        }

        return $this->sessionEnvelope($result, 'تم تسجيل الدخول بنجاح.');
    }

    public function googleStart(): JsonResponse
    {
        try {
            return $this->ok($this->googleBrowserLogin->start('finance'));
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), ApiErrorCode::ValidationFailed, 422);
        } catch (Throwable) {
            return $this->fail(
                'تعذر بدء تسجيل Google. تحقق من GOOGLE_CLIENT_ID و GOOGLE_CLIENT_SECRET و GOOGLE_REDIRECT_URI في .env.',
                ApiErrorCode::ValidationFailed,
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
            return $this->fail((string) $payload['error'], ApiErrorCode::NotFound, 404);
        }

        return $this->ok($payload);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            return $this->ok(message: 'تم إرسال رابط إعادة تعيين كلمة المرور.');
        }

        return $this->fail('تعذر إرسال رابط إعادة التعيين. تحقق من البريد الإلكتروني.', ApiErrorCode::ValidationFailed, 422);
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
                    'password' => $request->password,
                    'remember_token' => Str::random(60),
                ])->save();
                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return $this->ok(message: 'تم إعادة تعيين كلمة المرور بنجاح.');
        }

        return $this->fail('تعذر إعادة تعيين كلمة المرور. تحقق من الرابط أو البريد.', ApiErrorCode::ValidationFailed, 422);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            $current = $user->currentAccessToken();
            $this->mobileAuthService->logoutCurrent($user, $current);
            $bearer = $request->bearerToken();
            if (is_string($bearer) && $bearer !== '') {
                PersonalAccessToken::findToken($bearer)?->delete();
            } elseif ($current instanceof PersonalAccessToken) {
                $current->delete();
            }
        }

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $this->ok(message: 'تم تسجيل الخروج بنجاح.');
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspaces = $user->workspaces()->wherePivot('status', 'active')->get();
        $workspaceId = $user->currentAccessToken()?->workspace_id;
        $workspace = $workspaceId
            ? $workspaces->firstWhere('id', $workspaceId)
            : $workspaces->first();

        if ($workspace) {
            $this->workspaceContext->set($workspace);
        }

        return $this->ok($this->sessionPayload($user, $workspace, $workspaces));
    }

    /**
     * @param  array{user:User,workspace:Workspace|null,workspaces:Collection,token:NewAccessToken}  $result
     */
    private function sessionEnvelope(array $result, string $message): JsonResponse
    {
        $payload = $this->sessionPayload($result['user'], $result['workspace'], $result['workspaces']);
        $payload['token'] = $result['token']->plainTextToken;
        $payload['token_type'] = 'Bearer';
        $payload['expires_at'] = optional($result['token']->accessToken->expires_at)?->toIso8601String();

        return $this->ok($payload, message: $message);
    }

    /**
     * @param  Collection<int, Workspace>  $workspaces
     * @return array<string, mixed>
     */
    private function sessionPayload(User $user, mixed $workspace, $workspaces): array
    {
        return [
            'user' => $this->presenter->user($user),
            'workspace' => $workspace ? [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug ?? null,
                'type' => $workspace->type,
                'finance_enabled' => $this->featureAccessService->workspaceHasFeature($workspace, 'finance'),
            ] : null,
            'workspaces' => $this->presenter->workspaces(
                $workspaces,
                fn ($item) => $this->featureAccessService->workspaceHasFeature($item, 'finance'),
            ),
            'permissions' => $workspace ? $this->financePermissionMap($user, $workspace) : [],
            'finance_enabled' => $workspace
                ? $this->featureAccessService->workspaceHasFeature($workspace, 'finance')
                : false,
        ];
    }
}
