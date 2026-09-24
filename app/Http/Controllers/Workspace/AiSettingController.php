<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Http\Requests\Ai\UpdateAiSettingRequest;
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
    ) {}

    public function edit(Request $request): View
    {
        $this->authorizeAiSettings($request);

        $setting = AiSetting::query()->first();

        return view('workspace.ai-settings.edit', [
            'setting' => $setting,
            'aiRoles' => ChatAiRole::all(),
            'rulesJson' => json_encode($setting?->rules ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'businessInfoJson' => json_encode($setting?->business_information ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'agentPersonaJson' => json_encode($setting?->agent_persona ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeAiSettings($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'ai_role' => ['nullable', 'string', 'in:'.implode(',', ChatAiRole::all())],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'tone' => ['nullable', 'string', 'max:100'],
            'reply_style' => ['nullable', 'string', 'max:100'],
            'provider' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
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
