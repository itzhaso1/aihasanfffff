<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Models\Contract\Contract;
use App\Models\Contract\ContractAttachment;
use App\Models\Customer;
use App\Models\EmailAccount;
use App\Models\Projects\FinanceProject;
use App\Models\User;
use App\Services\Contracts\ContractEmailService;
use App\Services\Contracts\ContractPdfService;
use App\Services\Contracts\ContractService;
use App\Services\Notification\DomainNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ContractController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly ContractService $contractService,
        private readonly ContractPdfService $contractPdfService,
        private readonly ContractEmailService $contractEmailService,
        private readonly DomainNotificationService $domainNotificationService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeContracts($request, 'contracts.view');

        $status = trim((string) $request->string('status'));
        $search = trim((string) $request->string('search'));
        $customerId = $request->integer('customer_id');
        $projectId = $request->integer('project_id');
        $expiring = $request->boolean('expiring');

        $contracts = Contract::query()
            ->with(['customer', 'project', 'invoices'])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($customerId > 0, fn ($query) => $query->where('customer_id', $customerId))
            ->when($projectId > 0 && Schema::hasColumn('contracts', 'project_id'), fn ($query) => $query->where('project_id', $projectId))
            ->when($expiring, function ($query): void {
                $query->where('status', 'open')
                    ->whereNotNull('end_date')
                    ->whereDate('end_date', '<=', now()->addDays(30)->toDateString());
            })
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('contract_number', 'like', '%'.$search.'%')
                        ->orWhere('title', 'like', '%'.$search.'%')
                        ->orWhereHas('customer', fn ($customerQuery) => $customerQuery->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $base = Contract::query();

        return view('workspace.contracts.index', [
            'contracts' => $contracts,
            'filters' => [
                'status' => $status,
                'search' => $search,
                'customer_id' => $customerId,
                'project_id' => $projectId,
                'expiring' => $expiring,
            ],
            'stats' => [
                'open' => (clone $base)->where('status', 'open')->count(),
                'draft' => (clone $base)->where('status', 'draft')->count(),
                'closed' => (clone $base)->where('status', 'closed')->count(),
                'expiring' => (clone $base)->where('status', 'open')->whereNotNull('end_date')->whereDate('end_date', '<=', now()->addDays(30)->toDateString())->count(),
                'value_open' => (clone $base)->where('status', 'open')->sum('value'),
            ],
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'projects' => Schema::hasTable('finance_projects')
                ? FinanceProject::query()->orderBy('name')->get(['id', 'name'])
                : collect(),
            'routePrefix' => $this->contractRoutePrefix(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorizeContracts($request, 'contracts.manage');

        return view('workspace.contracts.create', [
            'contract' => new Contract(['currency' => 'SAR', 'status' => 'draft']),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'projects' => Schema::hasTable('finance_projects')
                ? FinanceProject::query()->orderBy('name')->get(['id', 'name'])
                : collect(),
            'emailAccounts' => EmailAccount::query()->orderBy('name')->get(['id', 'name', 'email']),
            'formAction' => route($this->contractRouteName('store')),
            'method' => 'POST',
            'routePrefix' => $this->contractRoutePrefix(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeContracts($request, 'contracts.manage');
        $workspace = $this->currentWorkspace();

        $payload = $request->validate($this->rules($workspace->id));
        $payload['status'] = 'draft';

        $contract = $this->contractService->create(
            $workspace,
            $payload,
            (int) $request->user()->id,
            $request->file('attachments', [])
        );

        return redirect()->route($this->contractRouteName('show'), $contract)->with('success', 'تم إنشاء العقد بنجاح.');
    }

    public function show(Request $request, Contract $contract): View
    {
        $this->authorizeContracts($request, 'contracts.view');
        $this->assertSameWorkspace($contract->workspace_id);
        $contract->load(['customer', 'project', 'items', 'attachments', 'billingSchedules', 'invoices.payments']);

        $relatedInvoices = $contract->invoices;
        $invoicedTotal = (float) $relatedInvoices->sum('total');
        $paidTotal = (float) $relatedInvoices->sum('amount_paid');
        $outstanding = (float) $relatedInvoices->sum('amount_due');

        return view('workspace.contracts.show', [
            'contract' => $contract,
            'emailAccounts' => EmailAccount::query()->orderBy('name')->get(['id', 'name', 'email']),
            'routePrefix' => $this->contractRoutePrefix(),
            'billingSummary' => [
                'invoiced_total' => $invoicedTotal,
                'paid_total' => $paidTotal,
                'outstanding' => $outstanding,
                'invoices' => $relatedInvoices,
            ],
        ]);
    }

    public function edit(Request $request, Contract $contract): View
    {
        $this->authorizeContracts($request, 'contracts.manage');
        $this->assertSameWorkspace($contract->workspace_id);
        $contract->load(['customer', 'items', 'attachments']);

        return view('workspace.contracts.edit', [
            'contract' => $contract,
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'projects' => Schema::hasTable('finance_projects')
                ? FinanceProject::query()->orderBy('name')->get(['id', 'name'])
                : collect(),
            'emailAccounts' => EmailAccount::query()->orderBy('name')->get(['id', 'name', 'email']),
            'formAction' => route($this->contractRouteName('update'), $contract),
            'method' => 'PUT',
            'routePrefix' => $this->contractRoutePrefix(),
        ]);
    }

    public function update(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorizeContracts($request, 'contracts.manage');
        $this->assertSameWorkspace($contract->workspace_id);

        if (in_array($contract->status, ['closed', 'cancelled'], true)) {
            return redirect()
                ->route($this->contractRouteName('show'), $contract)
                ->with('error', 'لا يمكن تعديل عقد مغلق أو ملغي.');
        }

        $workspace = $this->currentWorkspace();
        $payload = $request->validate($this->rules($workspace->id, $contract->id));
        $updated = $this->contractService->update($contract, $payload, $request->file('attachments', []));

        return redirect()->route($this->contractRouteName('show'), $updated)->with('success', 'تم تحديث العقد.');
    }

    public function activate(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorizeContracts($request, 'contracts.manage');
        $this->assertSameWorkspace($contract->workspace_id);
        $workspace = $this->currentWorkspace();

        $payload = $request->validate([
            'send_email' => ['nullable', 'boolean'],
            'email_account_id' => [
                Rule::requiredIf(fn () => $request->boolean('send_email')),
                'nullable',
                'integer',
                Rule::exists('email_accounts', 'id')->where(fn ($query) => $query->where('workspace_id', $workspace->id)),
            ],
            'recipient' => [Rule::requiredIf(fn () => $request->boolean('send_email')), 'nullable', 'string', 'max:2000'],
            'cc' => ['nullable', 'string', 'max:2000'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:15000'],
        ]);

        $contract = $this->contractService->activate($contract, (int) $request->user()->id);
        $this->domainNotificationService->notifyContractEvent(
            $contract,
            'تم تفعيل العقد '.$contract->contract_number,
            'أصبح العقد مفتوحاً ويمكن ربط جداول فوترة مالية به عند الحاجة.'
        );

        if (! $request->boolean('send_email')) {
            return redirect()
                ->route($this->contractRouteName('show'), $contract)
                ->with('success', 'تم تفعيل العقد بنجاح.');
        }

        try {
            $this->contractEmailService->sendActivationEmail($contract, [
                'email_account_id' => (int) $payload['email_account_id'],
                'recipient' => (string) $payload['recipient'],
                'cc' => (string) ($payload['cc'] ?? ''),
                'subject' => (string) ($payload['subject'] ?? ''),
                'message' => (string) ($payload['message'] ?? ''),
            ]);

            return redirect()
                ->route($this->contractRouteName('show'), $contract)
                ->with('success', 'تم تفعيل العقد وإرساله عبر البريد.');
        } catch (\Throwable $exception) {
            Log::warning('contract-activation-email-failed', [
                'contract_id' => $contract->id,
                'workspace_id' => $contract->workspace_id,
                'error' => $exception->getMessage(),
            ]);

            return redirect()
                ->route($this->contractRouteName('show'), $contract)
                ->with('error', 'تم تفعيل العقد لكن تعذر إرسال البريد حاليًا.');
        }
    }

    public function close(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorizeContracts($request, 'contracts.manage');
        $this->assertSameWorkspace($contract->workspace_id);
        $this->contractService->close($contract);
        $this->domainNotificationService->notifyContractEvent(
            $contract->fresh(),
            'تم إغلاق العقد '.$contract->contract_number,
            'أُغلق العقد يدوياً. تاريخ النهاية وحده لا يغلق العقد تلقائياً.'
        );

        return redirect()->route($this->contractRouteName('show'), $contract)->with('success', 'تم إغلاق العقد.');
    }

    public function cancel(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorizeContracts($request, 'contracts.manage');
        $this->assertSameWorkspace($contract->workspace_id);
        $this->contractService->cancel($contract);
        $this->domainNotificationService->notifyContractEvent(
            $contract->fresh(),
            'تم إلغاء العقد '.$contract->contract_number,
            'أُلغي العقد ولن تُولَّد فواتير جديدة من جداوله إلا بإجراء يدوي.'
        );

        return redirect()->route($this->contractRouteName('show'), $contract)->with('success', 'تم إلغاء العقد.');
    }

    public function downloadPdf(Request $request, Contract $contract)
    {
        $this->authorizeContracts($request, 'contracts.view');
        $this->assertSameWorkspace($contract->workspace_id);

        return $this->contractPdfService->download($contract);
    }

    public function downloadAttachment(Request $request, Contract $contract, ContractAttachment $attachment)
    {
        $this->authorizeContracts($request, 'contracts.view');
        $this->assertSameWorkspace($contract->workspace_id);
        abort_unless((int) $attachment->contract_id === (int) $contract->id, 404);

        return Storage::disk('public')->download(
            $attachment->file_path,
            $attachment->file_name ?: ('contract-attachment-'.$attachment->id)
        );
    }

    public function destroyAttachment(Request $request, Contract $contract, ContractAttachment $attachment): RedirectResponse
    {
        $this->authorizeContracts($request, 'contracts.manage');
        $this->assertSameWorkspace($contract->workspace_id);
        abort_unless((int) $attachment->contract_id === (int) $contract->id, 404);

        $this->contractService->deleteAttachment($attachment);

        return redirect()->route($this->contractRouteName('show'), $contract)->with('success', 'تم حذف المرفق.');
    }

    /**
     * @return array<string,mixed>
     */
    private function rules(int $workspaceId, ?int $ignoreContractId = null): array
    {
        return [
            'contract_number' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('contracts', 'contract_number')
                    ->where(fn ($query) => $query->where('workspace_id', $workspaceId))
                    ->ignore($ignoreContractId),
            ],
            'title' => ['required', 'string', 'max:255'],
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)),
            ],
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('finance_projects', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)),
            ],
            'currency' => ['nullable', 'string', 'size:3'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'terms' => ['nullable', 'string', 'max:40000'],
            'notes' => ['nullable', 'string', 'max:15000'],
            'items' => ['nullable', 'array', 'max:200'],
            'items.*.title' => ['nullable', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240'],
        ];
    }

    private function authorizeContracts(Request $request, string $permission): void
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user, 403);

        if ($user->can($permission)) {
            return;
        }

        $workspace = $this->currentWorkspace();
        $isElevatedMember = $workspace->users()
            ->where('users.id', $user->id)
            ->wherePivot('status', 'active')
            ->wherePivotIn('membership_role', ['owner', 'admin', 'manager'])
            ->exists();

        abort_unless($isElevatedMember, 403, 'You are not allowed to access contracts module.');
    }

    private function contractRoutePrefix(): string
    {
        if (Route::has('workspace.finance.contracts.index')) {
            return 'workspace.finance.contracts';
        }

        return 'workspace.contracts';
    }

    private function contractRouteName(string $suffix): string
    {
        return $this->contractRoutePrefix().'.'.$suffix;
    }
}
