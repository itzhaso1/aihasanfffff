<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\AI\Employees\EmployeeFactory;
use App\AI\Employees\EmployeeOperationException;
use App\AI\Employees\EmployeePersonaOptions;
use App\AI\Employees\EmployeeRepository;
use App\AI\Employees\EmployeeService;
use App\AI\Employees\InvalidEmployeeData;
use App\AI\Employees\Models\AiEmployee;
use App\AI\Employees\Presets\EmployeePreset;
use App\AI\Employees\Presets\PresetRepository;
use App\AI\Employees\Preview\EmployeePreviewResult;
use App\AI\Employees\Preview\EmployeePreviewRunner;
use App\AI\Models\ModelSettingsResolver;
use App\AI\Skills\SkillDefinition;
use App\AI\Skills\SkillRegistry;
use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Requests\Ai\SaveAiEmployeeRequest;
use App\Http\Resources\Mobile\AiEmployeeResource;
use App\Models\Workspace;
use App\Services\AI\AiSafety;
use App\Services\Platform\PlatformAiConfiguration;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Mobile API for AI employees. Every route checks AI-settings permission and resolves
 * the employee inside the current workspace, so ids from other workspaces are 404.
 */
class AiEmployeeController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly WorkspaceAccess $workspaceAccess,
        private readonly EmployeeRepository $employees,
        private readonly EmployeeService $service,
        private readonly EmployeeFactory $factory,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->authorizedWorkspace($request);

        return $this->ok(
            AiEmployeeResource::collection($this->employees->listForWorkspace((int) $workspace->id)),
            $this->employeeMeta($request, $workspace),
        );
    }

    public function store(SaveAiEmployeeRequest $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);

        return $this->handled(function () use ($request, $workspace): JsonResponse {
            $presetKey = $request->validated('preset_key');
            $draft = is_string($presetKey)
                ? $this->factory->fromPreset($presetKey, $request->employeeFields())
                : $this->factory->fromArray($request->employeeFields());
            $employee = $this->service->create($workspace, $draft, $request->user()?->id);

            return $this->ok(new AiEmployeeResource($employee), message: 'تم إنشاء الموظف.', status: 201);
        });
    }

    public function show(Request $request, int $employee): JsonResponse
    {
        return $this->ok(new AiEmployeeResource($this->employee($this->authorizedWorkspace($request), $employee)));
    }

    public function update(SaveAiEmployeeRequest $request, int $employee): JsonResponse
    {
        $record = $this->employee($this->requireWorkspace($this->workspaceContext), $employee);

        return $this->handled(fn (): JsonResponse => $this->ok(
            new AiEmployeeResource($this->service->update($record, $request->employeeFields(), $request->user()?->id)),
            message: 'تم تحديث الموظف.',
        ));
    }

    public function destroy(Request $request, int $employee): JsonResponse
    {
        $record = $this->employee($this->authorizedWorkspace($request), $employee);

        return $this->handled(function () use ($record, $request): JsonResponse {
            $this->service->softDelete($record, $request->user()?->id);

            return $this->ok(message: 'تم حذف الموظف.');
        });
    }

    public function setDefault(Request $request, int $employee): JsonResponse
    {
        $record = $this->employee($this->authorizedWorkspace($request), $employee);

        return $this->handled(fn (): JsonResponse => $this->ok(new AiEmployeeResource($this->service->setDefault($record, $request->user()?->id))));
    }

    public function activate(Request $request, int $employee): JsonResponse
    {
        $record = $this->employee($this->authorizedWorkspace($request), $employee);

        return $this->ok(new AiEmployeeResource($this->service->activate($record, $request->user()?->id)));
    }

    public function deactivate(Request $request, int $employee): JsonResponse
    {
        $record = $this->employee($this->authorizedWorkspace($request), $employee);

        return $this->ok(new AiEmployeeResource($this->service->deactivate($record, $request->user()?->id)));
    }

    /** Preview run: nothing is sent, stored or executed; the reply is never deliverable. */
    public function test(
        Request $request,
        int $employee,
        EmployeePreviewRunner $preview,
        ModelSettingsResolver $settings,
    ): JsonResponse {
        $record = $this->employee($this->authorizedWorkspace($request), $employee);
        $validated = $request->validate(['message' => ['required', 'string', 'min:1', 'max:500']]);

        $started = microtime(true);
        $response = $preview->run($record, $validated['message']);
        $elapsed = (int) round((microtime(true) - $started) * 1000);

        return $this->ok(EmployeePreviewResult::from(
            $response,
            $settings->resolve((int) $record->id),
            'platform',
            $elapsed,
        )->toArray());
    }

    public function presets(Request $request, PresetRepository $presets): JsonResponse
    {
        $this->authorizedWorkspace($request);

        return $this->ok(array_map(static fn (EmployeePreset $preset): array => $preset->toArray(), $presets->all()));
    }

    public function skills(Request $request): JsonResponse
    {
        $this->authorizedWorkspace($request);

        return $this->ok(array_map(static fn (SkillDefinition $skill): array => $skill->toArray(), SkillRegistry::enabled()));
    }

    private function authorizedWorkspace(Request $request): Workspace
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $request->user();
        if (! $user || ! $this->workspaceAccess->canManageAiSettings($user, $workspace)) {
            abort(403, 'لا تملك صلاحية إعدادات الذكاء الاصطناعي.');
        }

        return $workspace;
    }

    private function employee(Workspace $workspace, int $employeeId): AiEmployee
    {
        return $this->employees->findForWorkspace($workspace, $employeeId) ?? abort(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function employeeMeta(Request $request, Workspace $workspace): array
    {
        $platform = app(PlatformAiConfiguration::class)->snapshot();
        $meta = [
            'persona_options' => EmployeePersonaOptions::toArray(),
            'providers' => $this->providerCatalog(),
            'platform_ai' => $platform->toArray(),
        ];
        $user = $request->user();
        if ($user && $this->workspaceAccess->canViewAiProviderStatus($user, $workspace)) {
            $meta['provider_status'] = $this->providerStatusSummary();
        }

        return $meta;
    }

    /**
     * @return list<array{id: string, label: string, models: list<string>}>
     */
    private function providerCatalog(): array
    {
        $safety = app(AiSafety::class);
        $labels = [
            'google_ai_studio' => 'Google AI Studio (Gemini)',
            'openai' => 'OpenAI',
        ];
        $providers = [];
        foreach ($safety->allowedProviders() as $id) {
            $providers[] = [
                'id' => $id,
                'label' => $labels[$id] ?? $id,
                'models' => $safety->allowedModels($id),
            ];
        }

        return $providers;
    }

    /**
     * Owner/admin-only: configured providers and whether credentials exist. Never includes secrets.
     *
     * @return list<array{provider: string, default_model: string, allowed_models: list<string>, credentials_present: bool, is_default: bool}>
     */
    private function providerStatusSummary(): array
    {
        $safety = app(AiSafety::class);
        $platform = app(PlatformAiConfiguration::class)->snapshot();
        $summary = [];
        foreach ($safety->allowedProviders() as $id) {
            $models = $safety->allowedModels($id);
            $summary[] = [
                'provider' => $id,
                'default_model' => $id === $platform->provider ? $platform->model : ($models[0] ?? ''),
                'allowed_models' => $models,
                'credentials_present' => $this->credentialsPresent($id),
                'is_default' => $id === $platform->provider,
            ];
        }

        return $summary;
    }

    private function credentialsPresent(string $provider): bool
    {
        return match ($provider) {
            'google_ai_studio' => filled(config('services.google_ai_studio.key')),
            'openai' => filled(config('services.openai.key')),
            default => false,
        };
    }

    /**
     * @param  Closure(): JsonResponse  $operation
     */
    private function handled(Closure $operation): JsonResponse
    {
        try {
            return $operation();
        } catch (InvalidEmployeeData $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        } catch (EmployeeOperationException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }
    }
}
