<?php

use App\Exceptions\Api\ApiApplicationException;
use App\Exceptions\MissingWorkspaceContextException;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\AuthorizeMobileInboxWorkspace;
use App\Http\Middleware\EnsureFeatureAccess;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureWorkspaceCapability;
use App\Http\Middleware\EnsureWorkspaceMembership;
use App\Http\Middleware\EnsureWorkspaceSelected;
use App\Http\Middleware\Mobile\EnsureIdempotency;
use App\Http\Middleware\ResolveWorkspaceContext;
use App\Http\Middleware\RestrictIndependentWorkerWeb;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Two\InvalidStateException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')
                ->prefix('platform')
                ->group(base_path('routes/platform.php'));
        },
    )
    ->withBroadcasting(
        channels: __DIR__.'/../routes/channels.php',
        attributes: ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'webhooks/resend',
            'whatsapp-webhook',
            'meta-webhook',
            'mobile/channel-connect/whatsapp/*',
        ]);

        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            RestrictIndependentWorkerWeb::class,
        ]);
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            RestrictIndependentWorkerWeb::class,
        );

        $middleware->redirectUsersTo(function (Request $request) {
            if ($request->is('platform', 'platform/*') && auth('platform_admin')->check()) {
                return route('platform.dashboard');
            }

            return route('dashboard');
        });

        $middleware->alias([
            'workspace.resolve' => ResolveWorkspaceContext::class,
            'workspace.member' => EnsureWorkspaceMembership::class,
            'workspace.capability' => EnsureWorkspaceCapability::class,
            'inbox.actor' => AuthorizeMobileInboxWorkspace::class,
            'workspace.selected' => EnsureWorkspaceSelected::class,
            'workspace.feature' => EnsureFeatureAccess::class,
            'platform.admin' => EnsurePlatformAdmin::class,
            'mobile.idempotency' => EnsureIdempotency::class,
            'assign.correlation' => AssignCorrelationId::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/mobile/*') || $request->is('api/mobile/v1/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'انتهت جلسة تسجيل الدخول.',
                ], 401);
            }
        });

        $exceptions->render(function (MissingWorkspaceContextException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Workspace context is required.',
                    'code' => 'workspace_context_required',
                ], 403);
            }

            abort(403, 'Workspace context is required.');
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/mobile/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا تملك صلاحية تنفيذ هذا الإجراء.',
                ], 403);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/mobile/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'العنصر المطلوب غير موجود.',
                ], 404);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/mobile/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'بيانات غير صالحة.',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (ApiApplicationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'code' => $e->errorCode->value,
                ], $e->status);
            }
        });

        $exceptions->render(function (InvalidStateException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'تعذر إكمال تسجيل الدخول عبر Google. أعد المحاولة.',
                ], 401);
            }

            return redirect()
                ->route('login')
                ->withErrors([
                    'email' => 'تعذر إكمال تسجيل الدخول عبر Google. أعد المحاولة.',
                ]);
        });
    })->create();
