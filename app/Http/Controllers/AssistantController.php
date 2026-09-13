<?php

namespace App\Http\Controllers;

use App\Services\AI\AiUsageAccountant;
use App\Services\AI\WebsiteAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AssistantController extends Controller
{
    private const MAX_MESSAGES_PER_MINUTE = 8;

    private const MAX_MESSAGES_PER_DAY = 40;

    public function chat(Request $request, WebsiteAssistantService $assistantService): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:800'],
            'model' => ['prohibited'],
            'provider' => ['prohibited'],
            'max_tokens' => ['prohibited'],
            'temperature' => ['prohibited'],
        ]);

        $minuteKey = $this->rateLimitKey($request, 'minute');
        if (RateLimiter::tooManyAttempts($minuteKey, self::MAX_MESSAGES_PER_MINUTE)) {
            return response()->json([
                'message' => 'تم تجاوز الحد الأقصى للرسائل. حاول مرة أخرى بعد قليل.',
                'retry_after' => RateLimiter::availableIn($minuteKey),
            ], 429);
        }

        $dayKey = $this->rateLimitKey($request, 'day');
        if (RateLimiter::tooManyAttempts($dayKey, self::MAX_MESSAGES_PER_DAY)) {
            return response()->json([
                'message' => 'تم استهلاك حد المساعد اليومي. حاول غدًا.',
                'retry_after' => RateLimiter::availableIn($dayKey),
            ], 429);
        }

        RateLimiter::hit($minuteKey, 60);
        RateLimiter::hit($dayKey, 86400);
        app(AiUsageAccountant::class)->incrementPublicBucket('assistant');

        $result = $assistantService->replyWithMeta(
            prompt: $validated['message'],
            request: $request,
            user: $request->user(),
        );

        return response()->json([
            'data' => [
                'reply' => $result['reply'],
                'source' => $result['source'],
                'reason' => $result['reason'],
                'model' => $result['model'],
            ],
        ]);
    }

    private function rateLimitKey(Request $request, string $window): string
    {
        $userId = $request->user()?->id;
        $ip = (string) $request->ip();

        return 'assistant-chat:'.$window.':'.($userId ? 'user:'.$userId : 'ip:'.$ip);
    }
}
