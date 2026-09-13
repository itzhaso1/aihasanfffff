<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Resources\Mobile\UserResource;
use App\Http\Resources\Mobile\WorkspaceResource;
use App\Models\User;
use App\Services\Mobile\MobileAuthService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use RuntimeException;

class AuthController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly MobileAuthService $mobileAuthService,
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
                    'device_name' => $validated['device_name'] ?? null,
                    'device_type' => $validated['device_type'] ?? 'mobile',
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

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            return $this->ok(message: 'تم إرسال رابط إعادة تعيين كلمة المرور إلى بريدك الإلكتروني.');
        }

        if ($status === Password::RESET_THROTTLED) {
            return $this->fail('يرجى الانتظار قبل طلب رابط جديد.', 429);
        }

        return $this->fail('تعذر إرسال رابط إعادة التعيين. تحقق من البريد الإلكتروني.', 422, [
            'email' => [__($status)],
        ]);
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

        return $this->fail('تعذر إعادة تعيين كلمة المرور. تحقق من الرابط أو البريد.', 422, [
            'email' => [__($status)],
        ]);
    }

    public function social(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(['google', 'facebook'])],
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
                    'device_name' => $validated['device_name'] ?? null,
                    'device_type' => $validated['device_type'] ?? 'mobile',
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

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

        $this->mobileAuthService->logoutCurrent($user, $user->currentAccessToken());

        // Ensure bearer token cannot be reused even if token object typing differs.
        $bearer = $request->bearerToken();
        if (is_string($bearer) && str_contains($bearer, '|')) {
            [$id] = explode('|', $bearer, 2);
            if (is_numeric($id)) {
                $user->tokens()->whereKey((int) $id)->delete();
            }
        }

        return $this->ok(message: 'تم تسجيل الخروج بنجاح.');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

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
            'workspaces' => WorkspaceResource::collection($workspaces),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id),
            ],
            'phone' => [
                'nullable',
                'string',
                'max:32',
                Rule::unique(User::class)->ignore($user->id),
            ],
            'locale' => ['nullable', 'in:ar,en'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return $this->ok(new UserResource($user->fresh()), message: 'تم تحديث الملف الشخصي بنجاح.');
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', PasswordRule::defaults(), 'confirmed'],
        ]);

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        return $this->ok(message: 'تم تحديث كلمة المرور بنجاح.');
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

        $request->validate([
            'avatar' => ['required', 'image', 'max:5120'],
        ]);

        $previous = $user->avatar_path;
        $path = $request->file('avatar')->store('avatars/'.$user->id, 'public');

        $user->forceFill(['avatar_path' => $path])->save();

        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        return $this->ok(new UserResource($user->fresh()), message: 'تم تحديث الصورة الشخصية بنجاح.');
    }

    /**
     * @param  array{user:User,workspace:\App\Models\Workspace|null,workspaces:\Illuminate\Support\Collection,token:\Laravel\Sanctum\NewAccessToken}  $result
     */
    private function loginEnvelope(array $result, string $message): JsonResponse
    {
        return $this->ok([
            'token' => $result['token']->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => optional($result['token']->accessToken->expires_at)?->toIso8601String(),
            'user' => new UserResource($result['user']),
            'workspace' => $result['workspace'] ? new WorkspaceResource($result['workspace']) : null,
            'workspaces' => WorkspaceResource::collection($result['workspaces']),
        ], message: $message);
    }
}
