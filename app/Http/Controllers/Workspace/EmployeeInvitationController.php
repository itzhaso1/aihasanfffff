<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Http\Requests\Workspace\StoreEmployeeInvitationRequest;
use App\Models\EmployeeInvitation;
use App\Models\User;
use App\Models\WorkspaceUser;
use App\Notifications\EmployeeInvitationNotification;
use App\Services\Workforce\WorkforceParticipantDirectory;
use App\Support\Authorization\WorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\View\View;

class EmployeeInvitationController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly WorkspaceAccess $workspaceAccess,
        private readonly WorkforceParticipantDirectory $participants,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeMembers($request);
        $workspace = $this->currentWorkspace();
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return view('workspace.employees.index', [
            'participants' => $this->participants->forWorkspace($actor, $workspace),
            'canManageAiSettings' => $this->workspaceAccess->canManageAiSettings($actor, $workspace),
            'memberships' => WorkspaceUser::query()
                ->with('user')
                ->where('workspace_id', $workspace->id)
                ->latest('id')
                ->paginate(12),
            'invitations' => EmployeeInvitation::query()
                ->where('workspace_id', $workspace->id)
                ->latest('id')
                ->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorizeMembers($request);

        return view('workspace.employees.create');
    }

    public function store(StoreEmployeeInvitationRequest $request): RedirectResponse
    {
        $this->authorizeMembers($request);
        $workspace = $this->currentWorkspace();
        $actor = $request->user();

        $payload = $request->validated();

        abort_unless($actor && $this->workspaceAccess->canInviteRole($actor, $workspace, $payload['role']), 403);

        $invitation = EmployeeInvitation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'invited_by' => $actor->id,
            'email' => $payload['email'],
            'role' => $payload['role'],
            'status' => 'pending',
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);

        Notification::route('central_mail', $payload['email'])
            ->notify(new EmployeeInvitationNotification($invitation));

        return redirect()->route('workspace.employees.index')->with('success', 'تم إرسال الدعوة بنجاح.');
    }

    public function destroy(Request $request, EmployeeInvitation $employee): RedirectResponse
    {
        $this->authorizeMembers($request);
        $workspace = $this->currentWorkspace();
        abort_unless((int) $employee->workspace_id === (int) $workspace->id, 404);

        $employee->update(['status' => 'cancelled']);

        return redirect()->route('workspace.employees.index')->with('success', 'تم إلغاء الدعوة.');
    }

    private function authorizeMembers(Request $request): void
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($this->workspaceAccess->canManageMembers($user, $this->currentWorkspace()), 403);
    }
}
