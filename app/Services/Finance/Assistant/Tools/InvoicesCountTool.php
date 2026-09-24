<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\Finance\FinanceInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\FinanceAssistantPeriodResolver;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use App\Services\Finance\InvoiceInboxService;
use Illuminate\Support\Facades\Validator;

final class InvoicesCountTool extends AbstractPeriodFinanceTool
{
    public function __construct(
        FinanceAssistantPeriodResolver $periodResolver,
        private readonly InvoiceInboxService $invoiceInboxService,
    ) {
        parent::__construct($periodResolver);
    }

    public function name(): string
    {
        return 'invoices.count';
    }

    public function description(): string
    {
        return 'Count sales invoices for a period with optional payment lifecycle filter.';
    }

    public function permission(): string
    {
        return 'invoices.view';
    }

    public function parameterSchema(): array
    {
        return [
            ...parent::parameterSchema(),
            'lifecycle' => 'optional: overdue|paid|partial|sent|draft|cancelled',
        ];
    }

    public function validateArguments(array $arguments): array
    {
        $validated = parent::validateArguments($arguments);
        $extra = Validator::validate($arguments, [
            'lifecycle' => ['nullable', 'string', 'max:32'],
        ]);

        return [...$validated, ...$extra];
    }

    public function execute(Workspace $workspace, User $user, array $arguments): FinanceAssistantToolResult
    {
        $period = $this->periodFromArguments($arguments);

        $query = FinanceInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('type', 'sales')
            ->whereDate('issue_date', '>=', $period['from'])
            ->whereDate('issue_date', '<=', $period['to']);

        $lifecycle = trim((string) ($arguments['lifecycle'] ?? ''));
        if ($lifecycle !== '') {
            $this->invoiceInboxService->applyLifecycle($query, $lifecycle);
        } else {
            $query->whereIssued();
        }

        return FinanceAssistantToolResult::available($this->name(), $this->factEnvelope(
            $workspace,
            'finance_invoices',
            $period['from'],
            $period['to'],
            [
                'available' => true,
                'count' => (clone $query)->count(),
                'lifecycle' => $lifecycle !== '' ? $lifecycle : 'issued',
            ],
        ));
    }
}
