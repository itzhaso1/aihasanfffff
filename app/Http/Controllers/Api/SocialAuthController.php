<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\SocialAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SocialAuthController extends Controller
{
    public function __construct(
        private readonly SocialAuthService $socialAuthService,
    ) {}

    public function exchangeToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', Rule::in(['google', 'facebook'])],
            'access_token' => ['required', 'string'],
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
        ]);

        $payload = $this->socialAuthService->loginWithAccessToken(
            $validated['provider'],
            $validated['access_token'],
            $validated['workspace_id'] ?? null
        );

        return response()->json([
            'message' => 'Social login successful.',
            'data' => [
                'user' => $payload['user'],
                'workspace' => $payload['workspace'],
                'token' => $payload['token']->plainTextToken,
            ],
        ]);
    }
}
