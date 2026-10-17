<?php

declare(strict_types=1);

namespace App\AI\Employees;

use App\AI\Employees\Drafts\EmployeeDraft;
use App\AI\Employees\Drafts\EmployeeDraftMapper;
use App\AI\Employees\Drafts\EmployeeToolDraft;
use App\AI\Employees\Models\AiEmployee;
use App\AI\Employees\Models\AiEmployeeMemorySettings;
use App\AI\Employees\Models\AiEmployeeModelSettings;
use App\AI\Employees\Models\AiEmployeePersona;
use App\AI\Employees\Models\AiEmployeeSkill;
use App\AI\Employees\Models\AiEmployeeToolPermission;
use App\AI\Training\TrainingRepository;
use App\Models\Conversation;
use App\Models\Workspace;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/**
 * Employee lifecycle. Every write runs inside the owning workspace context and a
 * transaction; default changes lock the workspace row so two defaults cannot appear.
 */
final class EmployeeService
{
    private const TRAINING_PROFILE_PREFIX = 'profile.';

    public function __construct(
        private readonly EmployeeRepository $employees,
        private readonly EmployeeModelPolicy $models,
        private readonly EmployeeDraftMapper $mapper,
        private readonly TrainingRepository $training,
        private readonly WorkspaceContext $context,
    ) {}

    /** Creates an employee; the first active employee of a workspace becomes its default. */
    public function create(Workspace $workspace, EmployeeDraft $draft, ?int $actorId = null): AiEmployee
    {
        $model = $draft->model !== null ? $this->models->validate($draft->model) : $this->models->defaults();
        $this->assertTrainingProfile($draft);

        return $this->write($workspace, function () use ($workspace, $draft, $model, $actorId): AiEmployee {
            $this->lockWorkspace($workspace);
            $employee = new AiEmployee([
                ...$this->identity($draft),
                'workspace_id' => $workspace->id,
                'preset_key' => $draft->presetKey,
                'preset_version' => $draft->presetVersion,
                'legacy_ai_setting_id' => $draft->legacyAiSettingId,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
            $employee->status = $draft->status;
            $employee->config_version = 1;
            if ($draft->status === EmployeeStatus::Active && ! $this->employees->hasDefault((int) $workspace->id)) {
                $employee->default_slot = AiEmployee::DEFAULT_SLOT;
            }
            $employee->save();
            $this->writeConfiguration($employee, $draft, $model);

            return $this->employees->reload($employee);
        });
    }

    /**
     * Applies a partial change set in the API/preset array shape.
     *
     * @param  array<string, mixed>  $changes
     */
    public function update(AiEmployee $employee, array $changes, ?int $actorId = null): AiEmployee
    {
        $draft = $this->mapper->fromArray($changes, $this->mapper->fromEmployee($employee));
        $model = $draft->model !== null ? $this->models->validate($draft->model) : $this->models->defaults();
        $this->assertTrainingProfile($draft);

        return $this->write($this->workspaceOf($employee), function () use ($employee, $draft, $model, $actorId): AiEmployee {
            $employee->fill([...$this->identity($draft), 'updated_by' => $actorId]);
            $employee->config_version = $employee->config_version + 1;
            $employee->save();
            $this->writeConfiguration($employee, $draft, $model);

            return $this->employees->reload($employee);
        });
    }

    /** Changes display data only; the employee id, and everything bound to it, stays. */
    public function rename(AiEmployee $employee, string $name, ?int $actorId = null): AiEmployee
    {
        return $this->update($employee, ['name' => $name], $actorId);
    }

    public function setDefault(AiEmployee $employee, ?int $actorId = null): AiEmployee
    {
        if (! $employee->isActive()) {
            throw EmployeeOperationException::inactiveCannotBeDefault();
        }

        return $this->write($this->workspaceOf($employee), function () use ($employee, $actorId): AiEmployee {
            $this->lockWorkspace($this->workspaceOf($employee));
            $previousDefault = $this->employees->findDefault((int) $employee->workspace_id);
            AiEmployee::queryForWorkspace((int) $employee->workspace_id)
                ->where('default_slot', AiEmployee::DEFAULT_SLOT)
                ->whereKeyNot($employee->id)
                ->update(['default_slot' => null]);
            $employee->default_slot = AiEmployee::DEFAULT_SLOT;
            $employee->updated_by = $actorId;
            $employee->save();
            if ($previousDefault !== null && (int) $previousDefault->id !== (int) $employee->id) {
                Conversation::withoutGlobalScopes()
                    ->where('workspace_id', $employee->workspace_id)
                    ->where('ai_employee_id', $previousDefault->id)
                    ->update(['ai_employee_id' => $employee->id]);
            }

            return $this->employees->reload($employee);
        });
    }

    /** Activation also makes the employee the default when the workspace has none. */
    public function activate(AiEmployee $employee, ?int $actorId = null): AiEmployee
    {
        return $this->write($this->workspaceOf($employee), function () use ($employee, $actorId): AiEmployee {
            $this->lockWorkspace($this->workspaceOf($employee));
            $employee->status = EmployeeStatus::Active;
            $employee->updated_by = $actorId;
            if (! $this->employees->hasDefault((int) $employee->workspace_id)) {
                $employee->default_slot = AiEmployee::DEFAULT_SLOT;
            }
            $employee->save();

            return $this->employees->reload($employee);
        });
    }

    /** An inactive employee is never the default; the workspace may be left without one. */
    public function deactivate(AiEmployee $employee, ?int $actorId = null): AiEmployee
    {
        return $this->write($this->workspaceOf($employee), function () use ($employee, $actorId): AiEmployee {
            $employee->status = EmployeeStatus::Inactive;
            $employee->default_slot = null;
            $employee->updated_by = $actorId;
            $employee->save();

            return $this->employees->reload($employee);
        });
    }

    public function softDelete(AiEmployee $employee, ?int $actorId = null): void
    {
        if ($employee->isDefault()) {
            throw EmployeeOperationException::defaultCannotBeDeleted();
        }

        $this->write($this->workspaceOf($employee), function () use ($employee, $actorId): void {
            $employee->updated_by = $actorId;
            $employee->save();
            $employee->delete();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function identity(EmployeeDraft $draft): array
    {
        return [
            'name' => $draft->name,
            'avatar_key' => $draft->avatarKey,
            'job_title' => $draft->jobTitle,
            'gender' => $draft->gender,
            'role' => $draft->role,
            'instructions' => $draft->instructions,
            'training_profile' => $draft->trainingProfile,
            'experience_notes' => $draft->experienceNotes,
        ];
    }

    private function writeConfiguration(AiEmployee $employee, EmployeeDraft $draft, Drafts\EmployeeModelDraft $model): void
    {
        $scope = ['workspace_id' => $employee->workspace_id, 'ai_employee_id' => $employee->id];
        $persona = $draft->persona;
        AiEmployeePersona::query()->updateOrCreate($scope, [
            'language' => $persona->language,
            'dialect' => $persona->dialect,
            'formality' => $persona->formality,
            'tone' => $persona->tone,
            'emoji_policy' => $persona->emojiPolicy,
            'greeting_policy' => $persona->greetingPolicy,
            'traits' => $persona->traits,
            'style_notes' => $persona->styleNotes,
        ]);
        AiEmployeeMemorySettings::query()->updateOrCreate($scope, [
            'conversation_memory_enabled' => $draft->memory->conversationMemoryEnabled,
            'customer_memory_enabled' => $draft->memory->customerMemoryEnabled,
            'commerce_context_enabled' => $draft->memory->commerceContextEnabled,
            'relevant_history_enabled' => $draft->memory->relevantHistoryEnabled,
        ]);
        AiEmployeeModelSettings::query()->updateOrCreate($scope, [
            'provider' => $model->provider,
            'model' => $model->model,
            'temperature' => $model->temperature,
            'response_length' => $model->responseLength,
            'max_output_tokens' => $model->maxOutputTokens,
            'thinking_budget' => $model->thinkingBudget,
        ]);

        AiEmployeeSkill::query()->where('ai_employee_id', $employee->id)->delete();
        foreach ($draft->skills as $key => $settings) {
            AiEmployeeSkill::query()->create([...$scope, 'skill_key' => $key, 'settings' => $settings === [] ? null : $settings]);
        }
        AiEmployeeToolPermission::query()->where('ai_employee_id', $employee->id)->delete();
        foreach ($draft->tools as $tool) {
            /** @var EmployeeToolDraft $tool */
            AiEmployeeToolPermission::query()->create([
                ...$scope,
                'tool_name' => $tool->name,
                'allowed' => $tool->allowed,
                'max_calls_per_run' => $tool->maxCallsPerRun,
            ]);
        }
    }

    private function assertTrainingProfile(EmployeeDraft $draft): void
    {
        if ($draft->trainingProfile === null) {
            return;
        }
        if ($this->training->findById(self::TRAINING_PROFILE_PREFIX.$draft->trainingProfile) === null) {
            throw EmployeeOperationException::unknownTrainingProfile($draft->trainingProfile);
        }
    }

    private function lockWorkspace(Workspace $workspace): void
    {
        Workspace::query()->whereKey($workspace->id)->lockForUpdate()->first();
    }

    private function workspaceOf(AiEmployee $employee): Workspace
    {
        return Workspace::query()->findOrFail($employee->workspace_id);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function write(Workspace $workspace, callable $operation): mixed
    {
        return $this->context->runFor($workspace, fn () => DB::transaction($operation));
    }
}
