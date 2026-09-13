<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Services\Finance\FinanceCopilotService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CopilotClientController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FinanceCopilotService $financeCopilotService,
    ) {}

    public function ask(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.view');
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
        ]);

        return $this->ok($this->financeCopilotService->ask((int) $workspace->id, $validated['question']));
    }
}
