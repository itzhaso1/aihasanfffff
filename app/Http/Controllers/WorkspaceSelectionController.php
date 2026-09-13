<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class WorkspaceSelectionController extends Controller
{
    public function choose(Request $request): View|RedirectResponse
    {
        $workspaces = $request->user()?->workspaces()
            ->wherePivot('status', 'active')
            ->get();

        if ($workspaces && $workspaces->count() === 1) {
            $request->session()->put('current_workspace_id', $workspaces->first()->id);

            return redirect()->to('/workspace');
        }

        return view('workspace.choose', [
            'workspaces' => $workspaces,
        ]);
    }

    public function switch(Request $request, Workspace $workspace): RedirectResponse
    {
        $authorized = $request->user()?->workspaces()
            ->where('workspaces.id', $workspace->id)
            ->wherePivot('status', 'active')
            ->exists();

        abort_unless($authorized, 403);
        $request->session()->put('current_workspace_id', $workspace->id);

        return redirect()->to('/workspace');
    }
}
