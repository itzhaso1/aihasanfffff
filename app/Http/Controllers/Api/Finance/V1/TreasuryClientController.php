<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Exceptions\Api\ApiErrorCode;
use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Models\Finance\FinanceBankStatement;
use App\Models\Finance\FinanceBankStatementLine;
use App\Models\Finance\FinanceTreasuryAccount;
use App\Models\Finance\FinanceTreasuryTransfer;
use App\Services\Finance\Api\FinanceClientPresenter;
use App\Services\Finance\BankReconciliationService;
use App\Services\Finance\TreasuryTransferService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TreasuryClientController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly TreasuryTransferService $treasuryTransferService,
        private readonly BankReconciliationService $bankReconciliationService,
        private readonly FinanceClientPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.view');

        $accounts = FinanceTreasuryAccount::query()->orderBy('type')->orderBy('name')->get();
        $transfers = FinanceTreasuryTransfer::query()
            ->with(['fromAccount', 'toAccount'])
            ->latest('id')
            ->limit(20)
            ->get();
        $statements = FinanceBankStatement::query()
            ->with(['treasuryAccount', 'lines'])
            ->latest('id')
            ->limit(20)
            ->get();

        return $this->ok([
            'accounts' => $accounts->map(fn (FinanceTreasuryAccount $account) => $this->presenter->treasuryAccount($account))->values()->all(),
            'transfers' => $transfers->map(fn (FinanceTreasuryTransfer $transfer) => $this->presenter->treasuryTransfer($transfer))->values()->all(),
            'statements' => $statements->map(fn (FinanceBankStatement $statement) => $this->presenter->bankStatement($statement))->values()->all(),
        ]);
    }

    public function transfer(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'accounting.manage');
        $validated = $request->validate([
            'from_treasury_account_id' => [
                'required',
                'integer',
                Rule::exists('finance_treasury_accounts', 'id')->where(fn ($query) => $query->where('workspace_id', $workspace->id)),
            ],
            'to_treasury_account_id' => [
                'required',
                'integer',
                'different:from_treasury_account_id',
                Rule::exists('finance_treasury_accounts', 'id')->where(fn ($query) => $query->where('workspace_id', $workspace->id)),
            ],
            'amount' => ['required', 'numeric', 'gt:0'],
            'transfer_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $transfer = $this->runFinanceDomain(
            fn () => $this->treasuryTransferService->transfer((int) $workspace->id, $validated, (int) $request->user()?->id)
        );

        return $this->ok(
            $this->presenter->treasuryTransfer($transfer->load(['fromAccount', 'toAccount'])),
            message: 'تم تنفيذ التحويل وترحيله محاسبيًا.',
            status: 201,
        );
    }

    public function storeStatement(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'accounting.manage');
        $validated = $request->validate([
            'treasury_account_id' => [
                'required',
                'integer',
                Rule::exists('finance_treasury_accounts', 'id')->where(fn ($query) => $query->where('workspace_id', $workspace->id)),
            ],
            'statement_date' => ['required', 'date'],
            'opening_balance' => ['required', 'numeric'],
            'closing_balance' => ['required', 'numeric'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $statement = $this->runFinanceDomain(
            fn () => $this->bankReconciliationService->createStatement((int) $workspace->id, $validated)
        );

        return $this->ok(
            $this->presenter->bankStatement($statement->load(['treasuryAccount', 'lines'])),
            message: 'تم إنشاء كشف البنك.',
            status: 201,
        );
    }

    public function showStatement(Request $request, FinanceBankStatement $statement): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'finance.view');

        return $this->ok($this->presenter->bankStatement($statement->load(['treasuryAccount', 'lines'])));
    }

    public function storeLines(Request $request, FinanceBankStatement $statement): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'accounting.manage');
        $lines = $this->linesFromRequest($request);
        $this->runFinanceDomain(fn () => $this->bankReconciliationService->addLines($statement, $lines));

        return $this->ok(
            $this->presenter->bankStatement($statement->fresh()->load(['treasuryAccount', 'lines'])),
            message: 'تمت إضافة حركات الكشف.',
        );
    }

    public function suggest(Request $request, FinanceBankStatement $statement): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'accounting.manage');
        $count = $this->runFinanceDomain(fn () => $this->bankReconciliationService->suggestMatches($statement));

        return $this->ok(
            $this->presenter->bankStatement($statement->fresh()->load(['treasuryAccount', 'lines'])),
            message: 'اقتراحات المطابقة: '.$count,
        );
    }

    public function matchLine(Request $request, FinanceBankStatement $statement, FinanceBankStatementLine $line): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'accounting.manage');
        abort_unless((int) $line->bank_statement_id === (int) $statement->id, 404);
        $validated = $request->validate([
            'matched_type' => ['required', 'string'],
            'matched_id' => ['required', 'integer'],
        ]);

        $this->runFinanceDomain(
            fn () => $this->bankReconciliationService->matchLine(
                $line,
                $validated['matched_type'],
                (int) $validated['matched_id'],
                (int) $request->user()?->id
            )
        );

        return $this->ok(
            $this->presenter->bankStatement($statement->fresh()->load(['treasuryAccount', 'lines'])),
            message: 'تمت مطابقة الحركة.',
        );
    }

    public function ignoreLine(Request $request, FinanceBankStatement $statement, FinanceBankStatementLine $line): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'accounting.manage');
        abort_unless((int) $line->bank_statement_id === (int) $statement->id, 404);
        $this->runFinanceDomain(fn () => $this->bankReconciliationService->ignoreLine($line));

        return $this->ok(
            $this->presenter->bankStatement($statement->fresh()->load(['treasuryAccount', 'lines'])),
            message: 'تم تجاهل الحركة.',
        );
    }

    public function complete(Request $request, FinanceBankStatement $statement): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'accounting.manage');
        $completed = $this->runFinanceDomain(fn () => $this->bankReconciliationService->complete($statement));

        return $this->ok(
            $this->presenter->bankStatement($completed->load(['treasuryAccount', 'lines'])),
            message: 'تم إغلاق التسوية.',
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function linesFromRequest(Request $request): array
    {
        if (is_string($request->input('lines_json'))) {
            $decoded = json_decode((string) $request->input('lines_json'), true);
            if (! is_array($decoded)) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'صيغة حركات الكشف غير صالحة.',
                    'code' => ApiErrorCode::ValidationFailed->value,
                ], 422));
            }
            $request->merge(['lines' => $decoded]);
        }

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.posted_date' => ['nullable', 'date'],
            'lines.*.amount' => ['required', 'numeric'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.reference' => ['nullable', 'string', 'max:64'],
        ]);

        return array_values($validated['lines']);
    }
}
