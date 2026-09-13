<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class FeatureNotAvailableException extends Exception
{
    public function __construct(
        public readonly string $feature,
        public readonly ?string $requiredPlan = null,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : __('هذه الميزة غير متاحة في باقتك الحالية.'));
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => $this->getMessage(),
                'error' => 'feature_not_available',
                'feature' => $this->feature,
                'required_plan' => $this->requiredPlan,
                'upgrade_url' => route('workspace.subscriptions.index'),
            ], 402);
        }

        return redirect()
            ->route('workspace.subscriptions.index')
            ->with('upgrade_required', [
                'feature' => $this->feature,
                'message' => $this->getMessage(),
                'required_plan' => $this->requiredPlan,
            ]);
    }
}
