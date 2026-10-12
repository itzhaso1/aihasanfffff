<?php

namespace App\Http\Controllers\Workspace;

use App\AI\Employees\EmployeeFactory;
use App\AI\Employees\EmployeeOperationException;
use App\AI\Employees\EmployeePersonaOptions;
use App\AI\Employees\EmployeeRepository;
use App\AI\Employees\EmployeeService;
use App\AI\Employees\InvalidEmployeeData;
use App\AI\Employees\Models\AiEmployee;
use App\AI\Employees\Presets\PresetRepository;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Models\AiSetting;
use App\Services\AI\AiSettingManagementService;
use App\Services\AI\Inbox\Persona\InboxAgentPersonaValidationException;
use App\Services\AI\Inbox\Persona\InboxAgentPersonaValidator;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Chat\ChatAiRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AiSettingController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly AiSettingManagementService $aiSettings,
        private readonly EmployeeRepository $employees,
        private readonly EmployeeService $employeeService,
        private readonly EmployeeFactory $employeeFactory,
        private readonly PresetRepository $presets,
    ) {}

    public function edit(Request $request): View
    {
        $this->authorizeAiSettings($request);
        $workspace = $this->currentWorkspace();
        $employees = $this->employees->listForWorkspace((int) $workspace->id);
        $requested = $request->integer('employee');
        $selected = $employees->firstWhere('id', $requested) ?? $employees->firstWhere(fn (AiEmployee $employee) => $employee->isDefault()) ?? $employees->first();

        return view('workspace.ai-settings.edit', [
            'employees' => $employees,
            'selected' => $selected,
            'aiRoles' => ChatAiRole::all(),
            'options' => EmployeePersonaOptions::toArray(),
            'presets' => $this->presets->all(),
            'tab' => $request->string('tab')->toString() === 'personas' ? 'personas' : 'general',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeAiSettings($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'ai_role' => ['nullable', 'string', 'in:'.implode(',', ChatAiRole::all())],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'tone' => ['nullable', 'string', 'max:100'],
            'reply_style' => ['nullable', 'string', 'max:100'],
            'max_tokens' => ['required', 'integer', 'min:50', 'max:1024'],
            'temperature' => ['required', 'numeric', 'min:0', 'max:1'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $setting = AiSetting::query()->firstOrNew();
        $agentPersonaPayload = $this->parseJsonField($request, 'agent_persona_json', $setting->agent_persona ?? []);
        $agentPersona = null;
        if ($agentPersonaPayload !== []) {
            try {
                $agentPersona = app(InboxAgentPersonaValidator::class)
                    ->validate($agentPersonaPayload)
                    ->toArray();
            } catch (InboxAgentPersonaValidationException $exception) {
                throw ValidationException::withMessages([
                    'agent_persona_json' => $exception->getMessage(),
                ]);
            }
        }

        $this->aiSettings->update($this->currentWorkspace(), [
            ...$validated,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'rules' => $this->parseJsonField($request, 'rules_json', $setting->rules ?? []),
            'business_information' => $this->parseJsonField($request, 'business_information_json', $setting->business_information ?? []),
            'agent_persona' => $agentPersona,
        ]);

        return redirect()->route('workspace.ai-settings.edit')->with('success', 'تم تحديث إعدادات الذكاء الاصطناعي.');
    }

    public function updateEmployee(Request $request, int $employee): RedirectResponse
    {
        $this->authorizeAiSettings($request);
        $record = $this->findEmployee($employee);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:64'],
            'role' => ['sometimes', 'string', 'max:64'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'experience_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'model' => ['sometimes', 'array'],
            'model.temperature' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'model.response_length' => ['sometimes', 'string', 'max:16'],
            'persona' => ['sometimes', 'array'],
            'persona.language' => ['sometimes', 'string', 'max:16'],
            'persona.dialect' => ['sometimes', 'nullable', 'string', 'max:32'],
            'persona.formality' => ['sometimes', 'string', 'max:32'],
            'persona.tone' => ['sometimes', 'string', 'max:32'],
            'persona.emoji_policy' => ['sometimes', 'string', 'max:16'],
            'persona.traits' => ['sometimes', 'array', 'max:8'],
            'persona.traits.*' => ['string', 'max:32'],
            'tab' => ['nullable', 'string'],
        ]);
        unset($validated['tab']);
        if (isset($validated['persona']) && ! array_key_exists('traits', $validated['persona'])) {
            $validated['persona']['traits'] = [];
        }

        return $this->employeeAction(
            fn () => $this->employeeService->update($record, $validated, $request->user()?->id),
            'تم حفظ الموظف.',
            $record->id,
            $request->string('tab')->toString() === 'personas' ? 'personas' : 'general',
        );
    }

    public function storeEmployee(Request $request): RedirectResponse
    {
        $this->authorizeAiSettings($request);
        $validated = $request->validate([
            'preset_key' => ['required', 'string', 'max:64'],
        ]);

        return $this->employeeAction(function () use ($request, $validated) {
            $draft = $this->employeeFactory->fromPreset($validated['preset_key']);
            $employee = $this->employeeService->create($this->currentWorkspace(), $draft, $request->user()?->id);

            return $employee->id;
        }, 'تمت إضافة الشخصية.', null, 'personas');
    }

    public function setDefaultEmployee(Request $request, int $employee): RedirectResponse
    {
        $this->authorizeAiSettings($request);

        return $this->employeeAction(
            fn () => $this->employeeService->setDefault($this->findEmployee($employee), $request->user()?->id),
            'تم تعيين الشخصية الافتراضية.',
            $employee,
            'personas',
        );
    }

    public function activateEmployee(Request $request, int $employee): RedirectResponse
    {
        $this->authorizeAiSettings($request);

        return $this->employeeAction(
            fn () => $this->employeeService->activate($this->findEmployee($employee), $request->user()?->id),
            'تم تفعيل الشخصية.',
            $employee,
            'personas',
        );
    }

    public function deactivateEmployee(Request $request, int $employee): RedirectResponse
    {
        $this->authorizeAiSettings($request);

        return $this->employeeAction(
            fn () => $this->employeeService->deactivate($this->findEmployee($employee), $request->user()?->id),
            'تم إيقاف الشخصية.',
            $employee,
            'personas',
        );
    }

    public function destroyEmployee(Request $request, int $employee): RedirectResponse
    {
        $this->authorizeAiSettings($request);

        return $this->employeeAction(function () use ($request, $employee): void {
            $this->employeeService->softDelete($this->findEmployee($employee), $request->user()?->id);
        }, 'تم حذف الشخصية.', null, 'personas');
    }

    private function findEmployee(int $employee): AiEmployee
    {
        return $this->employees->findForWorkspace($this->currentWorkspace(), $employee) ?? abort(404);
    }

    private function employeeAction(callable $action, string $success, ?int $employeeId, string $tab): RedirectResponse
    {
        try {
            $result = $action();
            if ($result instanceof AiEmployee) {
                $employeeId = $result->id;
            } elseif (is_int($result)) {
                $employeeId = $result;
            }
        } catch (InvalidEmployeeData|EmployeeOperationException $exception) {
            return redirect()->route('workspace.ai-settings.edit', array_filter([
                'tab' => $tab,
                'employee' => $employeeId,
            ]))->withErrors(['employee' => $exception->getMessage()]);
        }

        return redirect()->route('workspace.ai-settings.edit', array_filter([
            'tab' => $tab,
            'employee' => $employeeId,
        ]))->with('success', $success);
    }

    private function authorizeAiSettings(Request $request): void
    {
        $user = $request->user();
        $workspace = $this->currentWorkspace();
        abort_unless(
            $user && app(WorkspaceAccess::class)->canManageAiSettings($user, $workspace),
            403
        );
    }
}
