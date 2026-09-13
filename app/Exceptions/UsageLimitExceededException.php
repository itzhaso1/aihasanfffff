<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class UsageLimitExceededException extends Exception
{
    public function __construct(
        public readonly string $meter,
        public readonly int|float $limit,
        public readonly int|float $used,
        public readonly string $overageBehavior = 'hard_block',
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : __('لقد وصلت إلى حد الاستخدام لهذه الميزة. يرجى ترقية الباقة أو شراء رصيد إضافي.'));
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => $this->getMessage(),
                'error' => 'usage_limit_exceeded',
                'meter' => $this->meter,
                'limit' => $this->limit,
                'used' => $this->used,
                'overage_behavior' => $this->overageBehavior,
                'upgrade_url' => route('workspace.subscriptions.index'),
            ], 402);
        }

        return redirect()
            ->route('workspace.subscriptions.index')
            ->with('usage_limit_exceeded', [
                'meter' => $this->meter,
                'message' => $this->getMessage(),
                'limit' => $this->limit,
                'used' => $this->used,
            ]);
    }
}
