<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function index(Request $request): View
    {
        $workspaces = Workspace::query()
            ->with('owner')
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('slug', 'like', '%'.$search.'%');
            })
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('platform.workspaces.index', compact('workspaces'));
    }

    public function edit(Workspace $workspace): View
    {
        return view('platform.workspaces.edit', compact('workspace'));
    }

    public function update(Request $request, Workspace $workspace): RedirectResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:individual,company,store'],
            'status' => ['required', 'in:active,inactive,suspended'],
        ]);

        $workspace->update($payload);

        return redirect()->route('platform.workspaces.index')->with('success', 'تم تحديث مساحة العمل.');
    }
}
