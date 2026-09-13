<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Models\Contract\Contract;
use App\Models\Finance\FinanceBillingSchedule;
use App\Services\Finance\BillingScheduleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class BillingScheduleController extends FinanceBaseController
{
    public function __construct(
        private readonly BillingScheduleService $billingScheduleService,
    ) {}

    public function store(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorizeFinance($request, 'contracts.manage');
        $this->assertSameWorkspace($contract->workspace_id);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'frequency' => ['required', 'in:weekly,monthly,quarterly,yearly,installment'],
            'interval_count' => ['nullable', 'integer', 'min:1', 'max:24'],
            'total_occurrences' => ['required', 'integer', 'min:1', 'max:120'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'auto_issue' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:draft,active'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $schedule = $this->billingScheduleService->createFromContract(
                $contract,
                $validated,
                (int) $request->user()?->id
            );
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route($this->contractShowRoute(), $contract)
            ->with('success', 'تم إنشاء جدول الفوترة '.$schedule->title.'. لن تُنشأ فواتير تلقائياً إلا بعد تفعيل الجدول.');
    }

    public function activate(Request $request, Contract $contract, FinanceBillingSchedule $schedule): RedirectResponse
    {
        $this->authorizeFinance($request, 'contracts.manage');
        $this->assertSameWorkspace($contract->workspace_id);
        abort_unless((int) $schedule->contract_id === (int) $contract->id, 404);

        try {
            $this->billingScheduleService->activate($schedule);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route($this->contractShowRoute(), $contract)->with('success', 'تم تفعيل جدول الفوترة.');
    }

    public function pause(Request $request, Contract $contract, FinanceBillingSchedule $schedule): RedirectResponse
    {
        $this->authorizeFinance($request, 'contracts.manage');
        $this->assertSameWorkspace($contract->workspace_id);
        abort_unless((int) $schedule->contract_id === (int) $contract->id, 404);

        try {
            $this->billingScheduleService->pause($schedule);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route($this->contractShowRoute(), $contract)->with('success', 'تم إيقاف جدول الفوترة.');
    }

    public function cancel(Request $request, Contract $contract, FinanceBillingSchedule $schedule): RedirectResponse
    {
        $this->authorizeFinance($request, 'contracts.manage');
        $this->assertSameWorkspace($contract->workspace_id);
        abort_unless((int) $schedule->contract_id === (int) $contract->id, 404);
        $this->billingScheduleService->cancel($schedule);

        return redirect()->route($this->contractShowRoute(), $contract)->with('success', 'تم إلغاء جدول الفوترة.');
    }

    public function generate(Request $request, Contract $contract, FinanceBillingSchedule $schedule): RedirectResponse
    {
        $this->authorizeFinance($request, 'invoices.create');
        $this->assertSameWorkspace($contract->workspace_id);
        abort_unless((int) $schedule->contract_id === (int) $contract->id, 404);

        try {
            $invoice = $this->billingScheduleService->generateOne($schedule);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if (! $invoice) {
            return back()->with('error', 'لا توجد دورة مستحقة لتوليد فاتورة حالياً.');
        }

        return redirect()->route('workspace.finance.invoices.show', $invoice)
            ->with('success', 'تم توليد الفاتورة '.$invoice->invoice_number.' من جدول الفوترة.');
    }

    private function contractShowRoute(): string
    {
        return 'workspace.finance.contracts.show';
    }
}
