<?php

namespace App\Http\Controllers\Api\Cashier\V1;

use App\Http\Controllers\Api\Cashier\CashierController;
use App\Http\Controllers\Api\Cashier\Concerns\AuthorizesCashier;
use App\Http\Controllers\Api\Cashier\Concerns\ResolvesCashierWorkspace;
use App\Models\Workspace;
use App\Services\Cashier\Assistant\CashierAssistantOrchestrator;
use App\Services\Feature\FeatureAccessService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class AssistantController extends CashierController
{
    use AuthorizesCashier;
    use ResolvesCashierWorkspace;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FeatureAccessService $featureAccessService,
        private readonly CashierAssistantOrchestrator $orchestrator,
    ) {}

    public function ask(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $this->authorizeCashier($request, $workspace, 'orders.manage');
        $this->ensurePos($workspace);
        $this->ensureAi($request, $workspace);

        $validated = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'locale' => ['nullable', 'string', 'max:8'],
        ]);

        try {
            $payload = $this->orchestrator->ask(
                $workspace,
                $user,
                $validated['question'],
                (string) ($validated['locale'] ?? 'ar'),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok($payload);
    }

    private function ensurePos(Workspace $workspace): void
    {
        if (! $this->featureAccessService->workspaceHasFeature($workspace, 'pos')) {
            throw new HttpResponseException(
                $this->fail('الكاشير غير متاح في باقتك الحالية', 403, meta: [
                    'pos_enabled' => false,
                ])
            );
        }
    }

    private function ensureAi(Request $request, Workspace $workspace): void
    {
        $user = $request->user();
        if (! $user || ! $this->featureAccessService->hasFeature($user, $workspace, 'ai')) {
            throw new HttpResponseException(
                $this->fail('ميزة الذكاء الاصطناعي غير متاحة لباقتك.', 403)
            );
        }
    }
}
