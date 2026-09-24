<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Models\AuditLog;
use App\Models\Contract\Contract;
use App\Models\Contract\ContractAttachment;
use App\Models\Customer;
use App\Models\EmailAccount;
use App\Models\Projects\FinanceProject;
use App\Models\User;
use App\Services\Contracts\ContractBalanceService;
use App\Services\Contracts\ContractEmailService;
use App\Services\Contracts\ContractPdfService;
use App\Services\Contracts\ContractService;
use App\Services\Notification\DomainNotificationService;
use App\Support\Authorization\FinanceAccess;
use App\Support\Uploads\SecureUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class ContractController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly ContractService $contractService,
        private readonly ContractPdfService $contractPdfService,
        private readonly ContractEmailService $contractEmailService,
        private readonly DomainNotificationService $domainNotificationService,
        private readonly ContractBalanceService $balanceService,
        private readonly FinanceAccess $financeAccess,
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

        return view('workspace.contracts.index', [
            'contracts' => $contracts,
            'filters' => [
                'status' => $status,
                'search' => $search,
                'customer_id' => $customerId,
                'project_id' => $projectId,
                'expiring' => $expiring,
            ],
            'stats' => $this->balanceService->workspaceDashboard(),
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
        $this->domainNotificationService->notifyContractEvent(
            $contract,
            'تم إنشاء العقد '.$contract->contract_number,
            'أُنشئ العقد كمسودة ويمكن تعديله قبل التفعيل.',
            'contract_created'
        );

        return redirect()->route($this->contractRouteName('show'), $contract)->with('success', 'تم إنشاء العقد بنجاح.');
    }

    public function show(Request $request, Contract $contract): View
    {
        $this->authorizeContracts($request, 'contracts.view');
        $this->assertSameWorkspace($contract->workspace_id);
        $contract->load([
            'customer',
            'project',
            'items',
            'attachments.uploader',
            'billingSchedules.invoices',
            'invoices.payments',
            'parentContract',
            'renewals',
            'companySigner',
        ]);

        $billingSummary = $this->balanceService->summarize($contract);
        $schedules = $contract->billingSchedules->map(function ($schedule) use ($contract) {
            $schedule->payment_status = $this->balanceService->scheduleStatus($schedule);
            $value = (float) $contract->value;
            $schedule->percentage = $value > 0 ? round(((float) $schedule->amount / $value) * 100, 2) : 0;

            return $schedule;
        });

        $auditLogs = AuditLog::query()
            ->with('user')
            ->where('entity_type', Contract::class)
            ->where('entity_id', $contract->id)
            ->latest('occurred_at')
            ->limit(50)
            ->get();

        return view('workspace.contracts.show', [
            'contract' => $contract,
            'emailAccounts' => EmailAccount::query()->orderBy('name')->get(['id', 'name', 'email']),
            'routePrefix' => $this->contractRoutePrefix(),
            'billingSummary' => $billingSummary,
            'schedules' => $schedules,
            'auditLogs' => $auditLogs,
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

        if (in_array($contract->status, ['closed', 'cancelled', 'renewed'], true)) {
            return redirect()
                ->route($this->contractRouteName('show'), $contract)
                ->with('error', 'لا يمكن تعديل عقد مغلق أو ملغي أو مُجدَّد.');
        }

        $workspace = $this->currentWorkspace();
        $payload = $request->validate($this->rules($workspace->id, $contract->id));
        try {
            $updated = $this->contractService->update($contract, $payload, $request->file('attachments', []), (int) $request->user()->id);
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

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
            'أصبح العقد سارياً ويمكن ربط جداول فوترة مالية به عند الحاجة.',
            'contract_activated'
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
            'أُغلق العقد يدوياً. تاريخ النهاية وحده لا يغلق العقد تلقائياً.',
            'contract_closed'
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
            'أُلغي العقد ولن تُولَّد فواتير جديدة من جداوله إلا بإجراء يدوي.',
            'contract_cancelled'
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
        abort_unless((int) $attachment->workspace_id === (int) $contract->workspace_id, 404);

        $disk = $attachment->storageDisk();
        abort_unless(Storage::disk($disk)->exists($attachment->file_path), 404);

        return Storage::disk($disk)->download(
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

    public function storeAttachment(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorizeContracts($request, 'contracts.attachments');
        $this->assertSameWorkspace($contract->workspace_id);
        $request->validate(SecureUpload::contractAttachmentRules(true));

        try {
            $this->contractService->storeAttachments($contract, $request->file('attachments', []) ?: [], (int) $request->user()->id);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route($this->contractRouteName('show'), $contract)->with('success', 'تم رفع المرفق.');
    }

    public function renew(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorizeContracts($request, 'contracts.renew');
        $this->assertSameWorkspace($contract->workspace_id);
        $payload = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'copy_schedules' => ['nullable', 'boolean'],
        ]);
        $payload['copy_schedules'] = $request->boolean('copy_schedules');

        try {
            $renewal = $this->contractService->renew($contract, $payload, (int) $request->user()->id);
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        $this->domainNotificationService->notifyContractEvent(
            $renewal,
            'تم تجديد العقد '.$contract->contract_number,
            'أُنشئ عقد تجديد '.$renewal->contract_number.' دون تعديل السجل التاريخي للعقد الأصلي.',
            'contract_renewed'
        );

        return redirect()->route($this->contractRouteName('show'), $renewal)->with('success', 'تم إنشاء عقد التجديد.');
    }

    public function sign(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorizeContracts($request, 'contracts.update');
        $this->assertSameWorkspace($contract->workspace_id);
        $payload = $request->validate([
            'party' => ['required', 'in:customer,company'],
            'signer_name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->contractService->recordSignature($contract, $payload, (int) $request->user()->id);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route($this->contractRouteName('show'), $contract)->with('success', 'تم تسجيل التوقيع التشغيلي. هذا ليس توقيعاً إلكترونياً قانونياً معتمداً.');
    }

    public function destroy(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorizeContracts($request, 'contracts.delete');
        $this->assertSameWorkspace($contract->workspace_id);

        try {
            $this->contractService->delete($contract);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route($this->contractRouteName('index'))->with('success', 'تم أرشفة العقد.');
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
            'description' => ['nullable', 'string', 'max:15000'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'terms' => ['nullable', 'string', 'max:40000'],
            'notes' => ['nullable', 'string', 'max:15000'],
            'term_fields' => ['nullable', 'array'],
            'term_fields.payment' => ['nullable', 'string', 'max:8000'],
            'term_fields.delivery' => ['nullable', 'string', 'max:8000'],
            'term_fields.cancellation' => ['nullable', 'string', 'max:8000'],
            'term_fields.renewal' => ['nullable', 'string', 'max:8000'],
            'term_fields.warranty' => ['nullable', 'string', 'max:8000'],
            'term_fields.termination' => ['nullable', 'string', 'max:8000'],
            'term_fields.additional' => ['nullable', 'string', 'max:8000'],
            'items' => ['nullable', 'array', 'max:200'],
            'items.*.title' => ['nullable', 'string', 'max:255'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.item_kind' => ['nullable', 'in:service,product,subscription,one_time,monthly,recurring'],
            'items.*.unit' => ['nullable', 'string', 'max:32'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_type' => ['nullable', 'in:amount,percent'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax_type' => ['nullable', 'string', 'max:32'],
            ...SecureUpload::contractAttachmentRules(),
        ];
    }

    private function authorizeContracts(Request $request, string $permission): void
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user, 403);
        $workspace = $this->currentWorkspace();

        if (in_array($permission, ['contracts.view', 'contracts.payments'], true)) {
            abort_unless(
                $this->financeAccess->can($user, $workspace, 'contracts.view')
                    || $this->financeAccess->can($user, $workspace, 'contracts.manage'),
                403,
                'You are not allowed to access contracts module.'
            );

            return;
        }

        if ($permission === 'contracts.invoice') {
            abort_unless(
                $this->financeAccess->can($user, $workspace, 'contracts.manage')
                    || $this->financeAccess->can($user, $workspace, 'invoices.create'),
                403,
                'You are not allowed to access contracts module.'
            );

            return;
        }

        abort_unless(
            $this->financeAccess->can($user, $workspace, 'contracts.manage')
                || $this->financeAccess->can($user, $workspace, $permission),
            403,
            'You are not allowed to access contracts module.'
        );
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
